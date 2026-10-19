<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Students\ClassProgress;
use App\Support\DayRoster;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A plan that has been used up is finished: it is not a class to teach, and it
 * cannot be counted again.
 *
 * Reported as a 20-class plan reading 21, with finished students still on the
 * dashboard beside Present and Absent buttons — there one hour, gone the next.
 * The two were the same bug. DayRoster kept a finished student's empty row for
 * 24 hours after any marking, and whenever a makeup pointed at the day, and
 * AttendanceService then accepted the marking: sessions_remaining was clamped at
 * zero while the class was still counted, so the derived plan total
 * (StudentProfile::sessionsPurchased) went up by one.
 */
class CompletedPlanTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'A321 Twenty Classes';

    private User $instructor;

    private User $student;

    private StudentProfile $profile;

    /** A Wednesday. The student is timetabled Monday, Wednesday and Friday. */
    private string $today = '2026-09-16';

    /** Thursday — no timetable slot. */
    private string $thursday = '2026-09-17';

    private string $friday = '2026-09-18';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setClock($this->today.' 10:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => self::NAME]);

        // Twenty classes bought, nineteen taught: one left.
        $this->profile = StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 1,
            'sessions_attended' => 19,
            'sessions_deducted' => 0,
            'start_date' => '2026-01-05',
        ]);

        foreach ([1, 3, 5] as $isoDay) {
            StudentSchedule::create([
                'student_id' => $this->student->id,
                'day_of_week' => $isoDay,
                'start_time' => '18:30:00',
            ]);
        }

        for ($i = 1; $i <= 19; $i++) {
            ClassSession::factory()->present()->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $this->student->id,
                'scheduled_date' => CarbonImmutable::parse('2026-08-01')->addDays($i)->toDateString(),
                'marked_at' => '2026-08-31 18:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function setClock(string $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    private function progress(): array
    {
        return app(ClassProgress::class)->for($this->profile->refresh());
    }

    private function markFinalClassToday(): void
    {
        app(AttendanceService::class)->mark(
            $this->instructor, $this->student, $this->today, SessionStatus::Present,
        );
    }

    private function rosterNames(string $date): array
    {
        return DayRoster::for($this->instructor->id, CarbonImmutable::parse($date))
            ->map(fn (array $row) => $row['student']->name)
            ->all();
    }

    // ------------------------------------------------------------- the count

    #[Test]
    public function the_last_class_of_a_twenty_class_plan_reads_twenty_not_twenty_one(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->today,
                'status' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['attended' => 20, 'remaining' => 0, 'purchased' => 20],
            array_intersect_key($this->progress(), array_flip(['attended', 'remaining', 'purchased'])),
        );

        // Friday's slot comes round. The plan is spent, so marking it is refused
        // rather than counted as a twenty-first class.
        $this->setClock($this->friday.' 18:45:00');

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'present',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(20, $this->progress()['purchased']);
        $this->assertSame(20, $this->progress()['attended']);
        $this->assertSame(20, $this->profile->sessions_attended);
        $this->assertFalse(
            ClassSession::query()->where('student_id', $this->student->id)
                ->where('scheduled_date', $this->friday)->exists(),
            'a refused marking leaves no row behind',
        );
    }

    #[Test]
    public function a_student_absence_cannot_spend_a_class_the_plan_does_not_have(): void
    {
        $this->markFinalClassToday();

        try {
            app(AttendanceService::class)->mark(
                $this->instructor, $this->student, $this->friday, SessionStatus::Absent, Party::Student,
            );
            $this->fail('A used-up plan accepted a student absence.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $this->progress()['student_absent']);
        $this->assertSame(20, $this->progress()['purchased']);
    }

    #[Test]
    public function a_used_up_plan_cannot_be_postponed_into_a_makeup(): void
    {
        $this->markFinalClassToday();

        $this->expectException(ValidationException::class);

        app(AttendanceService::class)->postpone(
            $this->instructor, $this->student, $this->friday, Party::Student, rescheduledDate: '2026-09-21',
        );
    }

    #[Test]
    public function the_final_class_can_still_be_corrected_and_cleared(): void
    {
        // A slot already holding one of the plan's classes is a record, not a
        // new class, so fixing a mistake on it must keep working at zero.
        $this->markFinalClassToday();

        $service = app(AttendanceService::class);

        $session = $service->mark(
            $this->instructor, $this->student, $this->today, SessionStatus::Absent, Party::Student,
        );
        $this->assertSame(20, $this->progress()['purchased']);
        $this->assertSame(0, $this->progress()['remaining']);

        $service->unmark($this->instructor, $session);
        $this->assertSame(1, $this->progress()['remaining']);
        $this->assertSame(20, $this->progress()['purchased']);
    }

    // -------------------------------------------------------- the roster

    #[Test]
    public function the_day_of_the_final_class_keeps_the_row_without_marking_buttons(): void
    {
        $this->markFinalClassToday();

        $row = DayRoster::for($this->instructor->id, CarbonImmutable::parse($this->today))
            ->firstWhere('student.id', $this->student->id);

        $this->assertNotNull($row, 'the final class still owes a report, so its row stays');
        $this->assertSame(SessionStatus::Present, $row['session']?->status);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index'))
            ->assertOk()
            ->assertSee(self::NAME)
            ->assertDontSee('name="status" value="present"', false);
    }

    #[Test]
    public function a_recent_marking_does_not_bring_a_finished_student_back_on_their_next_slot(): void
    {
        // The last class was taught yesterday evening, well inside 24 hours. It
        // used to hold the student on today's slot with live buttons — and the
        // row vanished again once the clock passed the 24-hour mark.
        $this->profile->update(['sessions_remaining' => 0, 'sessions_attended' => 20]);

        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2026-09-15',
            'marked_at' => '2026-09-15 18:45:00',
        ]);

        $this->assertNotContains(self::NAME, $this->rosterNames($this->today));

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertDontSee('name="status" value="present"', false);
    }

    #[Test]
    public function a_makeup_does_not_list_a_student_with_no_class_left_to_spend_on_it(): void
    {
        $this->profile->update(['sessions_remaining' => 0, 'sessions_attended' => 20]);

        // The current shape: a postponed row pointing forward at Thursday.
        ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2026-09-14',
            'status' => SessionStatus::Postponed,
            'postponed_by' => Party::Student,
            'rescheduled_date' => $this->thursday,
            'rescheduled_time' => '15:00:00',
        ]);

        // The legacy shape: an unmarked row sitting on the day it lands.
        ClassSession::factory()->makeupFor('2026-09-11')->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->today,
        ]);

        $this->assertNotContains(self::NAME, $this->rosterNames($this->thursday));
        $this->assertNotContains(self::NAME, $this->rosterNames($this->today));
    }

    // ----------------------------------------------------- the dashboard total

    #[Test]
    public function the_dashboard_counts_twenty_classes_when_twenty_are_live(): void
    {
        // Twenty students marked today, plus one whose plan is spent and who has
        // an unmarked makeup row left over on today's date. The calendar cell
        // and the roster beneath it must both say twenty.
        $this->profile->update(['sessions_remaining' => 0, 'sessions_attended' => 20]);

        ClassSession::factory()->makeupFor('2026-09-11')->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->today,
        ]);

        for ($i = 1; $i <= 20; $i++) {
            $live = User::factory()->student()->create(['name' => sprintf('B%03d Live', $i)]);

            StudentProfile::factory()->create([
                'user_id' => $live->id,
                'instructor_id' => $this->instructor->id,
                'enrollment_status' => EnrollmentStatus::Approved,
                'sessions_remaining' => 5,
                'start_date' => '2026-01-05',
            ]);

            ClassSession::factory()->present()->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $live->id,
                'scheduled_date' => $this->today,
            ]);
        }

        $view = $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['month' => 9, 'year' => 2026]))
            ->assertOk();

        $cell = collect($view->viewData('calendar'))
            ->first(fn (array $cell) => ($cell['date'] ?? null)?->toDateString() === $this->today);

        $this->assertSame(20, $view->viewData('today')->count());
        $this->assertSame(20, $cell['total']);
        $this->assertSame(0, $cell['unmarked']);
    }

    #[Test]
    public function the_calendar_never_counts_a_finished_students_class_the_roster_drops(): void
    {
        $this->profile->update(['sessions_remaining' => 0, 'sessions_attended' => 20]);

        // A cleared marking (a husk) on Monday, and a makeup pointed at last Friday.
        ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2026-09-14',
            'status' => null,
        ]);
        ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2026-09-09',
            'status' => SessionStatus::Postponed,
            'postponed_by' => Party::Teacher,
            'rescheduled_date' => '2026-09-12',
        ]);

        $calendar = $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->viewData('calendar');

        foreach ($calendar as $cell) {
            if (($cell['date'] ?? null) === null) {
                continue;
            }

            $this->assertSame(
                DayRoster::for($this->instructor->id, $cell['date'])->count(),
                max($cell['total'], $cell['upcoming']),
                'calendar and roster disagree on '.$cell['date']->toDateString(),
            );
        }
    }
}
