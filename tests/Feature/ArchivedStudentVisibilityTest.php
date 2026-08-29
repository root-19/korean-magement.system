<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Support\DayRoster;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An archived student stays archived on every page that lists students.
 *
 * Several of those pages join `users` with a raw query builder, which gets no
 * soft-delete global scope, and lean on `is_active` instead. The two are not the
 * same flag: two live rows are soft-deleted and still marked active, and they
 * were reaching pages that go through the model — the dashboard calendar and the
 * class lists — while DayRoster dropped them.
 *
 * The mismatch is worse than the row itself. The calendar promised classes on
 * days that opened empty, and the trial list drew a row with no name on it,
 * because `user` DOES apply the scope and came back null.
 */
class ArchivedStudentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $archived;

    /** A Friday. */
    private string $today = '2026-08-28';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->today.' 09:00:00');
        Carbon::setTestNow($this->today.' 09:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->archived = User::factory()->student()->create(['name' => 'A900 Archived Student']);

        StudentProfile::factory()->create([
            'user_id' => $this->archived->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 5,
            'is_regular' => false,
        ]);

        foreach (range(1, 5) as $isoDay) {
            StudentSchedule::create([
                'student_id' => $this->archived->id,
                'day_of_week' => $isoDay,
                'start_time' => '10:00:00',
            ]);
        }

        // Soft-delete but leave is_active alone — the exact state the two live
        // rows are in. Deleting through the app clears the flag; these predate
        // that, so the flag cannot be trusted to stand in for the timestamp.
        $this->archived->delete();
        DB::table('users')->where('id', $this->archived->id)->update(['is_active' => 1]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_calendar_does_not_promise_a_class_the_roster_will_not_have(): void
    {
        $nextMonth = CarbonImmutable::parse($this->today)->addMonthNoOverflow();

        $calendar = $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['month' => $nextMonth->month, 'year' => $nextMonth->year]))
            ->assertOk()
            ->viewData('calendar');

        foreach ($calendar as $cell) {
            if (($cell['date'] ?? null) === null) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                $cell['upcoming'],
                DayRoster::for($this->instructor->id, $cell['date'])->count(),
                sprintf(
                    'the calendar counts %d upcoming on %s but the roster opens with fewer',
                    $cell['upcoming'],
                    $cell['date']->toDateString(),
                ),
            );
        }
    }

    #[Test]
    public function the_trial_list_does_not_draw_a_row_with_no_name(): void
    {
        $students = $this->actingAs($this->instructor)
            ->get(route('instructor.trials.index'))
            ->assertOk()
            ->assertDontSee('A900 Archived Student')
            ->viewData('students');

        foreach ($students as $profile) {
            $this->assertNotNull(
                $profile->user,
                'a listed profile whose user resolves to null renders as a blank row',
            );
        }
    }

    #[Test]
    public function the_day_roster_never_listed_them_in_the_first_place(): void
    {
        // The baseline the other two pages are being held to.
        $this->assertCount(0, DayRoster::for($this->instructor->id, CarbonImmutable::parse($this->today)));
    }
}
