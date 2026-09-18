<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Support\DayRoster;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A session row does not make someone your student.
 *
 * DayRoster builds a day from three groups, and only the first — the weekly
 * timetable — checked who the student is. The other two read `class_sessions`
 * for the instructor and that date and listed whoever they found, so a single
 * stray row put a student the instructor no longer teaches onto the dashboard,
 * on a weekday they have no slot on, badged "Off timetable" with Present and
 * Absent buttons beside it.
 *
 * Legacy applied the check the rewrite dropped. `getStudentsWithAttendanceForDate`
 * in app/models/ClassModel.php looked every off-schedule student back up with
 * `instructor_id = ? AND status = 'active' AND enrollment_status != 'pending'
 * AND deleted_at IS NULL` before adding them to the day.
 *
 * `StudentProfile::teachable()` is that rule here, and it already gated the
 * dashboard's own student count and every other instructor list.
 */
class RosterStudentIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    /** A Wednesday. */
    private string $today = '2026-09-16';

    /** Thursday — a day the student has no timetable slot on. */
    private string $offTimetable = '2026-09-17';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->today.' 10:00:00');
        Carbon::setTestNow($this->today.' 10:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A777 Not My Student']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 5,
            'start_date' => '2026-01-05',
        ]);

        // Monday, Wednesday, Friday — never Thursday.
        foreach ([1, 3, 5] as $isoDay) {
            StudentSchedule::create([
                'student_id' => $this->student->id,
                'day_of_week' => $isoDay,
                'start_time' => '18:30:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An unmarked row on a day the student is not timetabled on. */
    private function strayRow(?SessionStatus $status = null): ClassSession
    {
        return ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->offTimetable,
            'scheduled_time' => null,
            'status' => $status,
        ]);
    }

    private function rosterNames(string $date): array
    {
        return DayRoster::for($this->instructor->id, CarbonImmutable::parse($date))
            ->map(fn (array $row) => $row['student']->name)
            ->all();
    }

    #[Test]
    public function a_student_moved_to_another_instructor_leaves_the_old_roster(): void
    {
        $this->strayRow();

        $this->student->studentProfile->update([
            'instructor_id' => User::factory()->instructor()->create()->id,
        ]);

        $this->assertNotContains('A777 Not My Student', $this->rosterNames($this->offTimetable));
    }

    #[Test]
    public function a_deactivated_student_is_not_pulled_back_by_a_session_row(): void
    {
        $this->strayRow();

        $this->student->update(['is_active' => false]);

        $this->assertNotContains('A777 Not My Student', $this->rosterNames($this->offTimetable));
    }

    #[Test]
    public function an_enrolment_still_awaiting_approval_is_not_a_class(): void
    {
        $this->strayRow();

        $this->student->studentProfile->update(['enrollment_status' => EnrollmentStatus::Pending]);

        $this->assertNotContains('A777 Not My Student', $this->rosterNames($this->offTimetable));
    }

    #[Test]
    public function a_makeup_moved_onto_a_day_goes_with_the_student_it_belonged_to(): void
    {
        // Group 3: the postponed row points forward at a makeup date. Left
        // unchecked it conjured a class for a student who had already moved on.
        ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2026-09-14',
            'status' => SessionStatus::Postponed,
            'rescheduled_date' => $this->offTimetable,
            'rescheduled_time' => '15:00:00',
        ]);

        $this->student->update(['is_active' => false]);

        $this->assertNotContains('A777 Not My Student', $this->rosterNames($this->offTimetable));
    }

    #[Test]
    public function the_calendar_does_not_count_a_class_the_roster_will_not_show(): void
    {
        // A dot the day cannot honour is the same lie in another place: the
        // teacher clicks it and the roster opens empty.
        $this->strayRow();

        $this->student->update(['is_active' => false]);

        $calendar = $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->viewData('calendar');

        foreach ($calendar as $cell) {
            if (($cell['date'] ?? null) === null) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                max($cell['total'], $cell['upcoming']),
                DayRoster::for($this->instructor->id, $cell['date'])->count(),
                sprintf(
                    'the calendar counts %d on %s but the roster opens with fewer',
                    max($cell['total'], $cell['upcoming']),
                    $cell['date']->toDateString(),
                ),
            );
        }
    }

    #[Test]
    public function a_class_taught_off_timetable_still_shows_for_a_student_who_is_still_mine(): void
    {
        // The guard against over-filtering: an off-timetable class that was
        // actually marked is work done, and it owes a report.
        $this->strayRow(SessionStatus::Present);

        $this->assertContains('A777 Not My Student', $this->rosterNames($this->offTimetable));
    }

    #[Test]
    public function the_students_own_timetable_days_are_untouched(): void
    {
        $this->assertContains('A777 Not My Student', $this->rosterNames($this->today));
    }
}
