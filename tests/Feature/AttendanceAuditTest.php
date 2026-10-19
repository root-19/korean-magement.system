<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin Audit System page: one payout cut-off, each teacher's minutes and
 * Present / Absent / Postponed counts, and the week charted day by day.
 */
class AttendanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        config(['academy.payout.week_starts_on' => 6]);

        // Wednesday: the cut-off runs Saturday Sep 12 to Friday Sep 18.
        Carbon::setTestNow('2026-09-16 10:00:00');
        CarbonImmutable::setTestNow('2026-09-16 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->ana = User::factory()->instructor()->create(['name' => 'Teacher Ana']);
        $this->ben = User::factory()->instructor()->create(['name' => 'Teacher Ben']);

        $short = $this->student($this->ana, 25);
        $long = $this->student($this->ben, 50);

        $this->classOn($this->ana, $short, '2026-09-14')->present()->create();
        $this->classOn($this->ana, $short, '2026-09-15')->present()->create();
        $this->classOn($this->ana, $short, '2026-09-16')->studentAbsent()->create();
        $this->classOn($this->ana, $short, '2026-09-17')->postponed()->create(['rescheduled_date' => '2026-09-21']);
        // Taught early on the 18th for a slot the week after: it pays, and counts, here.
        ClassSession::factory()->early('2026-09-18', '2026-09-25')->create([
            'instructor_id' => $this->ana->id,
            'student_id' => $short->id,
        ]);

        $this->classOn($this->ben, $long, '2026-09-13')->present()->create();
        $this->classOn($this->ben, $long, '2026-09-17')->teacherAbsent()->create();

        // Not counted: the Friday before the cut-off, and a class never marked.
        $this->classOn($this->ana, $short, '2026-09-11')->present()->create();
        $this->classOn($this->ben, $long, '2026-09-15')->create(['status' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function student(User $instructor, int $minutes): User
    {
        $student = User::factory()->student()->create();

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'learning_time' => $minutes,
        ]);

        return $student;
    }

    private function classOn(User $instructor, User $student, string $date)
    {
        return ClassSession::factory()->state([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => $date,
        ]);
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.attendance-audit.index', $query))->assertOk();
    }

    #[Test]
    public function it_totals_minutes_and_attendance_for_the_cut_off(): void
    {
        $this->assertSame([
            'present' => 4,
            'absent' => 2,
            'student_absent' => 1,
            'teacher_absent' => 1,
            'postponed' => 1,
            'total_minutes' => 5 * 25 + 2 * 50,
            'used_minutes' => 3 * 25 + 1 * 50,
        ], $this->page()->viewData('totals'));
    }

    #[Test]
    public function each_teacher_has_their_own_row_most_minutes_first(): void
    {
        $teachers = $this->page()->viewData('teachers');

        $this->assertSame(['Teacher Ana', 'Teacher Ben'], $teachers->pluck('name')->all());

        $this->assertSame(
            ['present' => 3, 'absent' => 1, 'postponed' => 1, 'total_minutes' => 125, 'used_minutes' => 75],
            array_intersect_key($teachers[0], array_flip(['present', 'absent', 'postponed', 'total_minutes', 'used_minutes'])),
        );
        $this->assertSame(
            ['present' => 1, 'absent' => 1, 'postponed' => 0, 'total_minutes' => 100, 'used_minutes' => 50],
            array_intersect_key($teachers[1], array_flip(['present', 'absent', 'postponed', 'total_minutes', 'used_minutes'])),
        );
    }

    #[Test]
    public function the_chart_has_every_day_of_the_cut_off(): void
    {
        $daily = collect($this->page()->viewData('daily'))
            ->mapWithKeys(fn (array $day) => [
                $day['date']->toDateString() => [$day['present'], $day['absent'], $day['postponed']],
            ])
            ->all();

        $this->assertSame([
            '2026-09-12' => [0, 0, 0],
            '2026-09-13' => [1, 0, 0],
            '2026-09-14' => [1, 0, 0],
            '2026-09-15' => [1, 0, 0],
            '2026-09-16' => [0, 1, 0],
            '2026-09-17' => [0, 1, 1],
            '2026-09-18' => [1, 0, 0],
        ], $daily);
    }

    #[Test]
    public function the_page_renders_the_chart_and_table(): void
    {
        $this->page()
            ->assertSee('Classes per day')
            ->assertSee('<polyline', false)
            ->assertSee('Teacher Ana')
            ->assertSee('Show as table');
    }

    #[Test]
    public function an_earlier_cut_off_can_be_chosen(): void
    {
        $totals = $this->page(['week' => '2026-09-11'])->viewData('totals');

        $this->assertSame(1, $totals['present']);
        $this->assertSame(25, $totals['total_minutes']);
    }

    #[Test]
    public function a_cut_off_with_no_classes_says_so(): void
    {
        $this->page(['week' => '2026-08-01'])->assertSee('No marked classes in this cut-off');
    }

    #[Test]
    public function a_malformed_week_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.attendance-audit.index', ['week' => 'not-a-date']))
            ->assertSessionHasErrors('week');
    }

    #[Test]
    public function it_is_in_the_admin_sidebar_and_closed_to_teachers(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertSee(route('admin.attendance-audit.index'));

        $this->actingAs($this->ana)
            ->get(route('admin.attendance-audit.index'))
            ->assertForbidden();
    }
}
