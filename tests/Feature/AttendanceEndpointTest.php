<?php

namespace Tests\Feature;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The attendance HTTP endpoints.
 *
 * AttendanceServiceTest covers the domain rules; these cover the controller
 * layer around it — request shape, authorisation, and the redirect. That gap is
 * where a real 500 hid: the "Present" button posts no `party` field, and
 * `validate()` omits keys the request never contained, so reading
 * `$data['party']` blew up on the single most-used action in the app.
 */
class AttendanceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create();
        $this->date = now()->toDateString();

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'sessions_remaining' => 10,
            'sessions_attended' => 0,
        ]);

        StudentSchedule::create([
            'student_id' => $this->student->id,
            'day_of_week' => now()->dayOfWeekIso,
            'start_time' => '18:30:00',
        ]);
    }

    // ------------------------------------------------------------ the 500 bug

    #[Test]
    public function marking_present_works_without_a_party_field(): void
    {
        // Exactly what the Present button submits: no `party` key at all.
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'present',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $session = ClassSession::first();

        $this->assertSame(SessionStatus::Present, $session->status);
        $this->assertNull($session->absent_by);
    }

    #[Test]
    public function marking_present_with_an_explicitly_null_party_also_works(): void
    {
        // A JSON client may send the key with a null value rather than omit it.
        $this->actingAs($this->instructor)
            ->postJson(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'present',
                'party' => null,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(SessionStatus::Present, ClassSession::first()->status);
    }

    // ---------------------------------------------------------------- absences

    #[Test]
    public function marking_a_student_absence_records_the_party(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'absent',
                'party' => 'student',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = ClassSession::first();

        $this->assertSame(SessionStatus::Absent, $session->status);
        $this->assertSame(Party::Student, $session->absent_by);
    }

    #[Test]
    public function marking_a_teacher_absence_records_the_party(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'absent',
                'party' => 'teacher',
            ])
            ->assertRedirect();

        $this->assertSame(Party::Teacher, ClassSession::first()->absent_by);
    }

    #[Test]
    public function an_absence_with_no_party_is_rejected_rather_than_guessed(): void
    {
        // Who was absent decides pay, so it must never be defaulted.
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'absent',
            ])
            ->assertSessionHasErrors('absent_by');

        $this->assertSame(0, ClassSession::count());
    }

    #[Test]
    public function an_unknown_party_fails_validation(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'absent',
                'party' => 'nobody',
            ])
            ->assertSessionHasErrors('party');
    }

    #[Test]
    public function an_unknown_status_fails_validation(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'maybe',
            ])
            ->assertSessionHasErrors('status');
    }

    // -------------------------------------------------------------- postponing

    #[Test]
    public function postponing_works_from_the_endpoint(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'postponed',
                'party' => 'other',
                'reason' => 'Public holiday',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = ClassSession::first();

        $this->assertSame(SessionStatus::Postponed, $session->status);
        $this->assertSame('Public holiday', $session->postpone_reason);
        // Postponed classes do not consume a prepaid session.
        $this->assertSame(10, StudentProfile::first()->sessions_remaining);
    }

    #[Test]
    public function postponing_without_a_party_falls_back_to_other(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'postponed',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(Party::Other, ClassSession::first()->postponed_by);
    }

    // ------------------------------------------------------------ early class

    #[Test]
    public function an_early_class_can_be_recorded_from_the_endpoint(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.early'), [
                'student_id' => $this->student->id,
                'held_date' => now()->toDateString(),
                'target_date' => now()->addWeeks(3)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $session = ClassSession::first();

        $this->assertNotNull($session->held_date);
        $this->assertSame(now()->toDateString(), $session->paid_date->toDateString());
    }

    #[Test]
    public function an_early_class_dated_in_the_future_is_rejected(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.early'), [
                'student_id' => $this->student->id,
                'held_date' => now()->addDay()->toDateString(),
                'target_date' => now()->addWeeks(3)->toDateString(),
            ])
            ->assertSessionHasErrors('held_date');
    }

    // ---------------------------------------------------------------- clearing

    #[Test]
    public function clearing_a_marking_rolls_back_the_counters(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'present',
            ]);

        $session = ClassSession::first();
        $this->assertSame(9, StudentProfile::first()->sessions_remaining);

        $this->actingAs($this->instructor)
            ->delete(route('instructor.classes.destroy', $session))
            ->assertRedirect();

        $this->assertNull($session->refresh()->status);
        $this->assertSame(10, StudentProfile::first()->sessions_remaining);
    }

    // ----------------------------------------------------------- authorisation

    #[Test]
    public function an_instructor_cannot_mark_another_instructors_student(): void
    {
        // The legacy endpoints trusted student_id straight off $_POST, so any
        // instructor could mark attendance — and therefore bill — against
        // someone else's student.
        $other = User::factory()->instructor()->create();

        $this->actingAs($other)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'present',
            ])
            ->assertForbidden();

        $this->assertSame(0, ClassSession::count());
    }

    #[Test]
    public function an_instructor_cannot_clear_another_instructors_session(): void
    {
        $session = ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->date,
        ]);

        $other = User::factory()->instructor()->create();

        $this->actingAs($other)
            ->delete(route('instructor.classes.destroy', $session))
            ->assertForbidden();

        $this->assertSame(SessionStatus::Present, $session->refresh()->status);
    }

    #[Test]
    public function a_guest_cannot_mark_attendance(): void
    {
        $this->post(route('instructor.classes.attendance'), [
            'student_id' => $this->student->id,
            'date' => $this->date,
            'status' => 'present',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, ClassSession::count());
    }
}
