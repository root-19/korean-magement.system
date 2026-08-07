<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard's day panels and its money figures.
 *
 * A 200 is not enough here. The stat tiles once printed the literal text
 * "@money($summary->net())" — the page rendered fine, the directive simply was
 * never compiled because it sat inside a component attribute. These assert on
 * what the instructor actually reads.
 */
class InstructorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $tomorrowStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();

        // Timetabled tomorrow and only tomorrow, so today's roster stays empty
        // and the two panels cannot be confused for one another.
        $this->tomorrowStudent = User::factory()->student()->create(['name' => 'A900 Tomorrow Only']);

        StudentProfile::factory()->create([
            'user_id' => $this->tomorrowStudent->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);

        StudentSchedule::create([
            'student_id' => $this->tomorrowStudent->id,
            'day_of_week' => now()->addDay()->dayOfWeekIso,
            'start_time' => '18:30:00',
        ]);
    }

    #[Test]
    public function money_figures_are_formatted_not_printed_as_directive_source(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertDontSee('@money')
            ->assertSee('₱0');
    }

    #[Test]
    public function the_earnings_page_formats_its_figures_too(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.earnings.index'))
            ->assertOk()
            ->assertDontSee('@money');
    }

    #[Test]
    public function tomorrows_schedule_lists_students_timetabled_tomorrow(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee("Tomorrow's Schedule")
            ->assertSee('A900 Tomorrow Only')
            ->assertSee(now()->addDay()->format('l, F j, Y'));
    }

    #[Test]
    public function tomorrow_is_read_only(): void
    {
        // Attendance belongs to the day it happens; teaching a later slot ahead
        // of time goes through the early-class flow, which credits the right
        // date. So a future day offers no marking buttons.
        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Scheduled')
            ->assertDontSee('>Present<', false)
            ->assertDontSee('>Absent<', false);
    }

    #[Test]
    public function a_past_date_from_the_calendar_is_markable(): void
    {
        $yesterday = now()->subDay();

        StudentSchedule::create([
            'student_id' => $this->tomorrowStudent->id,
            'day_of_week' => $yesterday->dayOfWeekIso,
            'start_time' => '09:00:00',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['date' => $yesterday->toDateString()]))
            ->assertOk()
            ->assertSee('Students for '.$yesterday->format('F j, Y'))
            ->assertSee('Total present time')
            ->assertSee('>Present<', false);
    }

    #[Test]
    public function a_future_date_from_the_calendar_is_a_preview(): void
    {
        $nextWeek = now()->addWeek()->startOfWeek();

        StudentSchedule::create([
            'student_id' => $this->tomorrowStudent->id,
            'day_of_week' => $nextWeek->dayOfWeekIso,
            'start_time' => '09:00:00',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['date' => $nextWeek->toDateString()]))
            ->assertOk()
            ->assertSee('Upcoming classes for '.$nextWeek->format('F j, Y'))
            ->assertSee('A900 Tomorrow Only')
            ->assertDontSee('Total present time');
    }

    #[Test]
    public function a_future_month_is_projected_from_the_timetable(): void
    {
        // No sessions exist next month, so the calendar has to come from the
        // weekly timetable or it would be an empty grid with nothing to open.
        $nextMonth = now()->addMonthNoOverflow();

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', [
                'month' => $nextMonth->month,
                'year' => $nextMonth->year,
            ]))
            ->assertOk()
            ->assertSee($nextMonth->format('F Y'))
            ->assertSee('Upcoming');
    }
}
