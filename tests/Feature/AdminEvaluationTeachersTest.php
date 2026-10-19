<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AttendanceRequest;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin's list of teachers with classes "For evaluation": past classes, in
 * the last 7 days, still unmarked on the teacher's own roster and not already
 * waiting on an admin decision.
 */
class AdminEvaluationTeachersTest extends TestCase
{
    use RefreshDatabase;

    private const TEACHER = 'T100 Behind Teacher';

    private const STUDENT = 'A410 Monday Student';

    private User $admin;

    private User $instructor;

    private User $student;

    /** A Wednesday. */
    private string $today = '2026-09-16';

    /** The Monday just gone — the student's slot, still unmarked. */
    private string $monday = '2026-09-14';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow($this->today.' 10:00:00');
        CarbonImmutable::setTestNow($this->today.' 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->instructor = User::factory()->instructor()->create(['name' => self::TEACHER]);
        $this->student = User::factory()->student()->create(['name' => self::STUDENT]);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 10,
            'start_date' => '2026-09-01',
        ]);

        StudentSchedule::create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '18:30:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function page()
    {
        return $this->actingAs($this->admin)->get(route('admin.evaluation-teachers.index'))->assertOk();
    }

    private function request(string $status): void
    {
        AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->monday,
            'reason' => 'Forgot to mark it on the day.',
            'status' => $status,
        ]);
    }

    #[Test]
    public function a_teacher_with_an_unmarked_past_class_is_listed(): void
    {
        $teachers = $this->page()
            ->assertSee(self::TEACHER)
            ->assertSee(self::STUDENT)
            ->assertSee('Sep 14')
            ->viewData('teachers');

        $this->assertCount(1, $teachers);
        $this->assertSame(1, $teachers->first()['classes']->count());
    }

    #[Test]
    public function a_class_already_sent_for_evaluation_is_left_to_the_evaluation_list(): void
    {
        $this->request(AttendanceRequest::PENDING);

        $this->page()->assertDontSee(self::TEACHER)->assertSee('Every class is marked');
    }

    #[Test]
    public function an_approved_class_is_open_to_marking_so_it_is_not_listed(): void
    {
        $this->request(AttendanceRequest::APPROVED);

        $this->page()->assertDontSee(self::TEACHER);
    }

    #[Test]
    public function a_rejected_class_still_shows_the_button_so_it_is_listed(): void
    {
        $this->request(AttendanceRequest::REJECTED);

        $this->page()->assertSee(self::TEACHER)->assertSee('Rejected');
    }

    #[Test]
    public function a_marked_class_is_not_listed(): void
    {
        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->monday,
        ]);

        $this->page()->assertDontSee(self::TEACHER);
    }

    #[Test]
    public function a_class_older_than_the_lookback_is_not_listed(): void
    {
        // Monday is marked; the Monday before it (9 days ago) is not, but falls
        // outside the 7-day window.
        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->monday,
        ]);

        $this->page()->assertDontSee(self::TEACHER);
    }

    #[Test]
    public function todays_unmarked_class_is_still_open_so_it_is_not_listed(): void
    {
        // Enrolled from today, timetabled on Wednesdays only: today's class is
        // the only one, and it can still be marked directly.
        $this->student->studentProfile->update(['start_date' => $this->today]);
        StudentSchedule::query()->where('student_id', $this->student->id)->update(['day_of_week' => 3]);

        $this->page()->assertDontSee(self::TEACHER);
    }

    #[Test]
    public function a_deactivated_teacher_is_not_listed(): void
    {
        $this->instructor->update(['is_active' => false]);

        $this->page()->assertDontSee(self::TEACHER);
    }

    #[Test]
    public function the_page_is_in_the_admin_sidebar_and_closed_to_teachers(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertSee(route('admin.evaluation-teachers.index'));

        $this->actingAs($this->instructor)
            ->get(route('admin.evaluation-teachers.index'))
            ->assertForbidden();
    }
}
