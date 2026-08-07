<?php

namespace Tests\Feature;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Marking attendance and the prepaid-session counters that must move with it.
 *
 * The legacy helpers (ClassModel::adjustStudentMetrics) incremented blindly and
 * outside a transaction, so re-marking a slot double-counted and a mid-way error
 * left the counters permanently wrong.
 */
class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    private User $instructor;

    private User $student;

    private StudentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AttendanceService::class);
        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create();

        $this->profile = StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'sessions_remaining' => 10,
            'sessions_attended' => 0,
            'sessions_deducted' => 0,
        ]);
    }

    private function counters(): array
    {
        $this->profile->refresh();

        return [
            'attended' => $this->profile->sessions_attended,
            'remaining' => $this->profile->sessions_remaining,
        ];
    }

    #[Test]
    public function marking_present_consumes_a_session(): void
    {
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);

        $this->assertSame(['attended' => 1, 'remaining' => 9], $this->counters());
    }

    #[Test]
    public function a_student_absence_consumes_a_session_but_is_not_attendance(): void
    {
        // The student burned a prepaid class by not turning up.
        $this->service->mark(
            $this->instructor,
            $this->student,
            '2025-08-04',
            SessionStatus::Absent,
            Party::Student,
        );

        $this->assertSame(['attended' => 0, 'remaining' => 9], $this->counters());
    }

    #[Test]
    public function a_teacher_absence_does_not_consume_the_students_session(): void
    {
        // Not the student's fault, so they keep the credit.
        $this->service->mark(
            $this->instructor,
            $this->student,
            '2025-08-04',
            SessionStatus::Absent,
            Party::Teacher,
        );

        $this->assertSame(['attended' => 0, 'remaining' => 10], $this->counters());
    }

    #[Test]
    public function postponing_does_not_consume_a_session(): void
    {
        $this->service->postpone($this->instructor, $this->student, '2025-08-04', Party::Student);

        $this->assertSame(['attended' => 0, 'remaining' => 10], $this->counters());
    }

    #[Test]
    public function re_marking_the_same_slot_does_not_double_count(): void
    {
        // The legacy bug: each save incremented again.
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);

        $this->assertSame(['attended' => 1, 'remaining' => 9], $this->counters());
        $this->assertSame(1, ClassSession::count(), 'the slot must stay a single row');
    }

    #[Test]
    public function correcting_present_to_teacher_absent_returns_the_session(): void
    {
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);
        $this->assertSame(['attended' => 1, 'remaining' => 9], $this->counters());

        $this->service->mark(
            $this->instructor,
            $this->student,
            '2025-08-04',
            SessionStatus::Absent,
            Party::Teacher,
        );

        $this->assertSame(['attended' => 0, 'remaining' => 10], $this->counters());
    }

    #[Test]
    public function correcting_present_to_student_absent_keeps_the_session_consumed(): void
    {
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);

        $this->service->mark(
            $this->instructor,
            $this->student,
            '2025-08-04',
            SessionStatus::Absent,
            Party::Student,
        );

        // Still consumed, but no longer attended.
        $this->assertSame(['attended' => 0, 'remaining' => 9], $this->counters());
    }

    #[Test]
    public function clearing_a_marking_rolls_the_counters_back(): void
    {
        $session = $this->service->mark(
            $this->instructor,
            $this->student,
            '2025-08-04',
            SessionStatus::Present,
        );

        $this->service->unmark($this->instructor, $session);

        $this->assertSame(['attended' => 0, 'remaining' => 10], $this->counters());
        $this->assertNull($session->refresh()->status);
    }

    #[Test]
    public function an_absence_must_say_who_was_absent(): void
    {
        // It decides whether the session pays or is deducted, so it cannot default.
        $this->expectException(ValidationException::class);

        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Absent);
    }

    #[Test]
    public function counters_never_go_negative(): void
    {
        $this->profile->update(['sessions_remaining' => 0, 'sessions_attended' => 0]);

        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);

        $counters = $this->counters();

        $this->assertSame(0, $counters['remaining'], 'clamped at zero, as the legacy GREATEST(x-1,0) did');
        $this->assertSame(1, $counters['attended']);
    }

    // ------------------------------------------------------------ early classes

    #[Test]
    public function an_early_class_records_the_held_date_separately(): void
    {
        $session = $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: '2025-08-04',
            targetDate: '2025-08-27',
        );

        $this->assertSame('2025-08-04', $session->held_date->toDateString());
        $this->assertSame('2025-08-27', $session->scheduled_date->toDateString());
        // Derived by the database.
        $this->assertSame('2025-08-04', $session->paid_date->toDateString());
        $this->assertSame(SessionStatus::Present, $session->status);
    }

    #[Test]
    public function an_early_class_leaves_the_postpone_reason_clean(): void
    {
        // The legacy code smuggled the held date into this column as
        // 'Early class held on YYYY-MM-DD'. It must stay free text now.
        $session = $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: '2025-08-04',
            targetDate: '2025-08-27',
        );

        $this->assertNull($session->postpone_reason);
    }

    #[Test]
    public function an_early_class_does_not_collide_with_the_same_day_regular_class(): void
    {
        // The legacy UNIQUE(teacher, student, date) made this impossible, which is
        // why the held date had to be hidden in a text column.
        $this->service->mark($this->instructor, $this->student, '2025-08-04', SessionStatus::Present);

        $early = $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: '2025-08-04',
            targetDate: '2025-08-27',
        );

        $this->assertSame(2, ClassSession::count());
        $this->assertSame('2025-08-04', $early->paid_date->toDateString());
        $this->assertSame(['attended' => 2, 'remaining' => 8], $this->counters());
    }

    #[Test]
    public function an_early_class_cannot_be_dated_in_the_future(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: now()->addDay()->toDateString(),
            targetDate: now()->addMonth()->toDateString(),
        );
    }

    #[Test]
    public function an_early_class_target_must_be_after_the_held_date(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: '2025-08-27',
            targetDate: '2025-08-04',
        );
    }

    #[Test]
    public function an_early_class_needs_a_remaining_session(): void
    {
        $this->profile->update(['sessions_remaining' => 0]);

        $this->expectException(ValidationException::class);

        $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: '2025-08-04',
            targetDate: '2025-08-27',
        );
    }

    #[Test]
    public function an_already_marked_slot_cannot_be_pulled_forward(): void
    {
        $this->service->mark($this->instructor, $this->student, '2025-08-27', SessionStatus::Present);

        $this->expectException(ValidationException::class);

        $this->service->markEarly(
            $this->instructor,
            $this->student,
            heldDate: '2025-08-04',
            targetDate: '2025-08-27',
        );
    }
}
