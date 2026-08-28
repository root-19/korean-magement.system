<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
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
 * A timetable only counts inside the student's enrolment dates.
 *
 * The weekly timetable is a repeating rule with no history in it, so projecting
 * it unbounded put a student enrolled on the 26th onto the 25th, and onto every
 * one of their weekdays in the weeks before they existed. Those days have
 * passed, so the roster renders them closed, and the only control on a closed
 * class is "For evaluation" — instructors were asking admins to reopen classes
 * that were never scheduled.
 */
class EnrollmentWindowRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    /** A Friday. */
    private string $today = '2026-08-28';

    /** Wednesday — the day the student enrolled. */
    private string $startDate = '2026-08-26';

    /** Tuesday — a weekday they are timetabled on, one day before they enrolled. */
    private string $beforeStart = '2026-08-25';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->today.' 14:00:00');
        Carbon::setTestNow($this->today.' 14:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A516 Late Enrollee']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'start_date' => $this->startDate,
        ]);

        // Monday through Friday, the shape the real students were set up with.
        foreach (range(1, 5) as $day) {
            StudentSchedule::create([
                'student_id' => $this->student->id,
                'day_of_week' => $day,
                'start_time' => '21:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_student_is_not_on_the_roster_before_their_start_date(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->beforeStart]))
            ->assertOk()
            ->assertDontSee('A516 Late Enrollee')
            // No phantom row means no phantom evaluation request.
            ->assertDontSee('For evaluation');
    }

    #[Test]
    public function last_weeks_slots_are_empty_too(): void
    {
        $lastWeek = CarbonImmutable::parse($this->beforeStart)->subWeek()->toDateString();

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $lastWeek]))
            ->assertOk()
            ->assertDontSee('A516 Late Enrollee');
    }

    #[Test]
    public function the_start_date_itself_is_a_class_day(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->startDate]))
            ->assertOk()
            ->assertSee('A516 Late Enrollee');
    }

    #[Test]
    public function a_student_with_no_start_date_projects_as_before(): void
    {
        // 6 of the live students were imported without one. A blank bound has to
        // stay unbounded, or they would drop off every roster at once.
        $this->student->studentProfile->update(['start_date' => null]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->beforeStart]))
            ->assertOk()
            ->assertSee('A516 Late Enrollee');
    }

    #[Test]
    public function a_class_recorded_before_the_start_date_still_shows(): void
    {
        // The window trims the projection, not the record: a class that was
        // actually taught and marked is a fact about that day, and hiding it
        // would strand the payment and the report it owes.
        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->beforeStart,
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->beforeStart]))
            ->assertOk()
            ->assertSee('A516 Late Enrollee');
    }

    #[Test]
    public function a_student_drops_off_after_their_end_date(): void
    {
        $this->student->studentProfile->update([
            'start_date' => '2026-01-05',
            'end_date' => '2026-08-27',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->today]))
            ->assertOk()
            ->assertDontSee('A516 Late Enrollee');
    }

    #[Test]
    public function the_calendar_projects_nothing_before_the_start_date(): void
    {
        // The month grid runs off the same timetable, and the teacher checked it
        // first: an upcoming dot on a day with no class is the same lie in a
        // different place.
        $this->student->studentProfile->update(['start_date' => '2026-09-16']);

        $calendar = $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->viewData('calendar');

        $upcoming = collect($calendar)
            ->filter(fn (array $cell) => $cell['date'] !== null)
            ->mapWithKeys(fn (array $cell) => [$cell['date']->toDateString() => $cell['upcoming']]);

        // Tue Sep 15 is a timetabled weekday, the day before they start.
        $this->assertSame(0, $upcoming->get('2026-09-15'));
        $this->assertSame(1, $upcoming->get('2026-09-16'));
    }
}
