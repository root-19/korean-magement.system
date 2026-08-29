<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Support\MakeupSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Postponing a class: who moved it, and when the student comes back.
 *
 * The endpoint used to accept a postponement and throw the return date away —
 * `rescheduled_date` was in the service signature but nothing ever passed it, so
 * a postponed class left the timetable and reappeared nowhere.
 */
class PostponeTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    /** A Friday, so the Monday-to-Friday timetable below has slots after it. */
    private string $friday = '2026-08-07';

    protected function setUp(): void
    {
        parent::setUp();

        // Both classes: Carbon and CarbonImmutable hold separate test clocks,
        // and the views read now() while the closed-class gate reads
        // CarbonImmutable::today(). Setting one leaves them a day apart.
        CarbonImmutable::setTestNow($this->friday.' 09:00:00');
        Carbon::setTestNow($this->friday.' 09:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A100 Postpone Me']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 2,
        ]);

        foreach (range(1, 5) as $isoDay) {
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

    /**
     * Both clocks, for the same reason setUp sets both.
     */
    private function moveClockTo(string $date): void
    {
        CarbonImmutable::setTestNow($date.' 09:00:00');
        Carbon::setTestNow($date.' 09:00:00');
    }

    /**
     * The class list's roster as one string per row, in the order shown.
     *
     * A day can list the same student twice, so the assertions below are about
     * which line says what — something a whole-page assertSee cannot tell.
     *
     * @return array<int, string>
     */
    private function rosterRows(string $date): array
    {
        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $date]))
            ->assertOk()
            ->getContent();

        preg_match('~<tbody>(.*?)</tbody>~s', $html, $matches);

        $rows = array_map(
            fn (string $row) => trim((string) preg_replace('/\s+/', ' ', strip_tags($row))),
            preg_split('~</tr>~', $matches[1] ?? []) ?: [],
        );

        return array_values(array_filter($rows, fn (string $row) => $row !== ''));
    }

    /**
     * Postpone the Friday class through the endpoint the instructor uses.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function postponeFriday(array $overrides = []): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), array_merge([
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'student',
            ], $overrides))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------- the makeup date rule

    #[Test]
    public function auto_lands_on_the_slot_after_the_students_remaining_classes(): void
    {
        // Friday postponed, 2 classes left, Mon-Fri timetable: the remaining two
        // are taught Mon 10th and Tue 11th, so Tuesday is the new last class.
        // This is the case the legacy modal previewed.
        $makeup = MakeupSchedule::for($this->student->fresh(), $this->friday, 2);

        $this->assertSame('2026-08-11', $makeup->autoDate->toDateString());
        $this->assertSame('Tuesday, August 11, 2026', $makeup->autoDate->format('l, F j, Y'));
        $this->assertSame('18:30', $makeup->defaultTime, 'the makeup inherits the usual slot time');
    }

    #[Test]
    public function a_student_with_nothing_left_still_gets_the_next_slot(): void
    {
        // Otherwise the postponement has no date at all, which is worse.
        $makeup = MakeupSchedule::for($this->student->fresh(), $this->friday, 0);

        $this->assertSame('2026-08-10', $makeup->autoDate->toDateString());
    }

    #[Test]
    public function a_student_with_no_timetable_has_no_auto_date(): void
    {
        StudentSchedule::where('student_id', $this->student->id)->delete();

        $makeup = MakeupSchedule::for($this->student->fresh(), $this->friday, 2);

        $this->assertNull($makeup->autoDate, 'there is no slot to append to');
        $this->assertSame('No weekly timetable', $makeup->scheduleLabel());
    }

    #[Test]
    public function the_preview_lists_the_slots_up_to_the_makeup(): void
    {
        $preview = MakeupSchedule::for($this->student->fresh(), $this->friday, 2)->toArray()['preview'];

        $this->assertSame(['Mon, Aug 10', 'Tue, Aug 11'], array_column($preview, 'label'));
        $this->assertSame([false, true], array_column($preview, 'isMakeup'));
    }

    // ------------------------------------------------------------- the endpoint

    #[Test]
    public function postponing_records_who_moved_it_the_reason_and_the_return_date(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'student',
                'reason' => 'Family trip',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = ClassSession::firstOrFail();

        $this->assertSame(SessionStatus::Postponed, $session->status);
        $this->assertSame(Party::Student, $session->postponed_by);
        $this->assertSame('Family trip', $session->postpone_reason);
        $this->assertSame('2026-08-11', $session->rescheduled_date->toDateString());
        $this->assertSame('18:30:00', (string) $session->rescheduled_time);
    }

    #[Test]
    public function a_manual_return_date_is_used_as_given(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'teacher',
                'reschedule' => 'manual',
                'rescheduled_date' => '2026-08-19',
                'rescheduled_time' => '20:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = ClassSession::firstOrFail();

        $this->assertSame('2026-08-19', $session->rescheduled_date->toDateString());
        $this->assertSame('20:00:00', (string) $session->rescheduled_time);
        $this->assertSame(Party::Teacher, $session->postponed_by);
    }

    #[Test]
    public function a_manual_reschedule_needs_a_date(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'reschedule' => 'manual',
            ])
            ->assertSessionHasErrors('rescheduled_date');
    }

    #[Test]
    public function a_makeup_cannot_land_before_the_class_it_replaces(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'reschedule' => 'manual',
                'rescheduled_date' => '2026-08-06',
            ])
            ->assertSessionHasErrors('rescheduled_date');
    }

    #[Test]
    public function the_confirmation_says_when_the_class_comes_back(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'other',
            ])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Tue, Aug 11'));
    }

    // ------------------------------------------------------------------- money

    #[Test]
    public function postponing_deducts_nothing_and_keeps_the_prepaid_session(): void
    {
        // True whoever postponed it, which is what the modal's banner promises.
        foreach (['student', 'teacher', 'other'] as $party) {
            $this->actingAs($this->instructor)
                ->post(route('instructor.classes.attendance'), [
                    'student_id' => $this->student->id,
                    'date' => $this->friday,
                    'status' => 'postponed',
                    'party' => $party,
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(
                2,
                StudentProfile::firstOrFail()->sessions_remaining,
                "postponed by {$party} must not burn a session",
            );
        }
    }

    // ------------------------------------------------------------ where it shows

    #[Test]
    public function the_makeup_appears_on_the_roster_of_the_day_it_moved_to(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'student',
            ]);

        // The day it came from says where it went…
        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->friday]))
            ->assertOk()
            ->assertSee('Postponed')
            ->assertSee('back Tue, Aug 11');

        // …and the day it moved to lists it as a makeup.
        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard', ['date' => '2026-08-11']))
            ->assertOk()
            ->assertSee('A100 Postpone Me')
            ->assertSee('Makeup for Aug 7');
    }

    #[Test]
    public function postpone_sits_in_the_attendance_column_beside_present_and_absent(): void
    {
        // Not behind the "Absent" prompt: postponing is its own outcome, and
        // having to say a class was absent first to reach it read as a bug.
        foreach ([
            route('instructor.classes.index', ['date' => $this->friday]),
            route('instructor.dashboard'),
        ] as $url) {
            $html = $this->actingAs($this->instructor)->get($url)->assertOk()->getContent();

            // Everything before the absent-party prompt is the row of controls
            // the instructor sees first.
            $buttons = substr($html, 0, strpos($html, 'Who was absent?') ?: strlen($html));

            foreach (['Present', 'Absent', 'Postpone'] as $label) {
                $this->assertStringContainsString(
                    $label,
                    $buttons,
                    "{$label} must be in the first row of controls on {$url}",
                );
            }
        }
    }

    #[Test]
    public function the_postpone_control_hands_the_projection_to_the_modal(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->friday]))
            ->assertOk()
            ->assertSee('open-postpone-modal')
            ->assertSee('Who is postponing?')
            // The computed makeup date rides along in the button's payload, so the
            // modal opens already showing where the class would land.
            ->assertSee('Tuesday, August 11, 2026');
    }

    #[Test]
    public function a_makeup_shows_the_agreed_time_not_the_students_usual_slot(): void
    {
        // Tuesday is a day this student already attends, at 18:30. The makeup was
        // moved to 3 PM, and the roster showed the timetable time instead — so
        // the one row that says when to teach named the wrong hour.
        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-11',
            'rescheduled_time' => '15:00',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-11']))
            ->assertOk()
            ->assertSee('Makeup for Aug 7')
            ->assertSee('3:00 PM');
    }

    #[Test]
    public function the_calendar_counts_a_makeup_on_the_day_it_lands(): void
    {
        // Saturday is off this student's timetable, so nothing but the makeup
        // puts a class there — and the makeup's row stays on Friday. Counting
        // rows alone, the calendar went blank on the day it arrived: the class
        // showed as upcoming right up to the day it was due, then vanished.
        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-08',
        ]);

        $this->moveClockTo('2026-08-08');

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Saturday, August 8 — 1 class');
    }

    #[Test]
    public function a_makeup_that_passed_unmarked_stays_on_the_calendar(): void
    {
        // Two days later it is still owed: it was never taught and never paid.
        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-08',
        ]);

        $this->moveClockTo('2026-08-10');

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Saturday, August 8 — 1 class');
    }

    #[Test]
    public function a_makeup_on_an_off_timetable_day_still_marks_the_calendar(): void
    {
        // Saturday is not in this student's Monday-to-Friday timetable, so only
        // the makeup can put a class there.
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'student',
                'reschedule' => 'manual',
                'rescheduled_date' => '2026-08-15',
            ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            // The calendar's day title carries the count for that date.
            ->assertSee('Saturday, August 15 — 1 class');
    }

    // ------------------------------------------- and where it stops showing

    #[Test]
    public function the_slots_before_a_makeup_are_empty_once_nothing_is_left_to_teach(): void
    {
        // The ticket: one class left, moved to a later day, and the student was
        // still listed on each of their usual days in between — with Present and
        // Absent buttons on a class nobody was coming to. Postponing moves a
        // session, it does not add one, so those slots have nothing to teach.
        StudentProfile::firstOrFail()->update(['sessions_remaining' => 1]);

        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-19',
        ]);

        $this->moveClockTo('2026-08-10');

        // Monday is on their timetable, and was listing the class anyway. The
        // roster count is what is asserted, not the name: the postponement's own
        // flash message names the student on the next page load.
        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-10']))
            ->assertOk()
            ->assertSee('0 students scheduled')
            ->assertSee('No classes on Monday, August 10');

        // The day it actually comes back still has it.
        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-19']))
            ->assertOk()
            ->assertSee('1 student scheduled')
            ->assertSee('Makeup for Aug 7');
    }

    #[Test]
    public function the_postponed_day_itself_still_says_where_the_class_went(): void
    {
        // Only the bare timetable slots go. The postponed row is a record of
        // something that happened and has to stay readable.
        StudentProfile::firstOrFail()->update(['sessions_remaining' => 1]);

        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-19',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->friday]))
            ->assertOk()
            ->assertSee('1 student scheduled')
            ->assertSee('back Wed, Aug 19');
    }

    #[Test]
    public function a_student_with_a_session_to_spare_keeps_their_other_slots(): void
    {
        // Two classes left and one of them moved: the other is still owed on the
        // timetable, so Monday is a real class. Hiding it would lose the work.
        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-19',
        ]);

        $this->moveClockTo('2026-08-10');

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-10']))
            ->assertOk()
            ->assertSee('1 student scheduled');
    }

    #[Test]
    public function a_makeup_that_has_already_passed_does_not_hold_the_timetable_back(): void
    {
        // A makeup date that came and went unmarked is its own problem. Letting
        // it go on cancelling out the timetable would hide live classes for as
        // long as the row survives.
        StudentProfile::firstOrFail()->update(['sessions_remaining' => 1]);

        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-10',
        ]);

        $this->moveClockTo('2026-08-12');

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-12']))
            ->assertOk()
            ->assertSee('1 student scheduled');
    }

    #[Test]
    public function the_calendar_stops_projecting_the_slots_the_makeup_emptied(): void
    {
        // The projection has to agree with the roster: a dot on Monday leads to a
        // day that now renders empty.
        StudentProfile::firstOrFail()->update(['sessions_remaining' => 1]);

        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-19',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertDontSee('Monday, August 10 — 1 class scheduled')
            ->assertSee('Wednesday, August 19 — 1 class scheduled');
    }

    // ------------------------------------------- and how many lines it takes up

    #[Test]
    public function a_makeup_landing_on_a_regular_class_day_stays_one_line(): void
    {
        // Monday is already this student's class and the makeup moved onto it, so
        // the day holds one line that says both things. Listing the makeup
        // separately was tried and read as the same student twice — and the
        // second line could never be marked: a day holds one slot per student
        // (class_sessions_slot_unique), so only one of the two could be recorded.
        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-10',
        ]);

        $this->moveClockTo('2026-08-10');

        $rows = $this->rosterRows('2026-08-10');

        $this->assertCount(1, $rows, 'one student, one line');
        $this->assertStringContainsString('A100 Postpone Me', $rows[0]);
        $this->assertStringContainsString('Makeup for Aug 7', $rows[0]);
        $this->assertStringContainsString('Present', $rows[0], 'and it is markable');
    }

    #[Test]
    public function the_calendar_counts_that_day_once_as_well(): void
    {
        // The dot has to agree with the roster it opens: one line on Monday, one
        // class on the calendar.
        $this->postponeFriday([
            'reschedule' => 'manual',
            'rescheduled_date' => '2026-08-10',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Monday, August 10 — 1 class scheduled');
    }

    // ------------------------------------------------- when it is called off

    #[Test]
    public function marking_the_slot_afterwards_takes_the_makeup_off_the_later_day(): void
    {
        // The student turned up after all, so the postponement never happened —
        // and the class it promised on a later day has to go with it. The
        // pointer was left behind, so the makeup date kept listing a class
        // nobody was coming to, and the calendar kept counting it.
        $this->postponeFriday();

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(ClassSession::firstOrFail()->rescheduled_date);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-11']))
            ->assertOk()
            ->assertDontSee('Makeup for Aug 7');
    }

    #[Test]
    public function clearing_the_postponement_takes_the_makeup_with_it(): void
    {
        // Otherwise the makeup is stranded: its own date shows a class, and the
        // row that would clear it is no longer marked as anything.
        $this->postponeFriday();

        $this->actingAs($this->instructor)
            ->delete(route('instructor.classes.destroy', ClassSession::firstOrFail()))
            ->assertSessionHasNoErrors();

        $this->assertNull(ClassSession::firstOrFail()->rescheduled_date);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => '2026-08-11']))
            ->assertOk()
            ->assertDontSee('Makeup for Aug 7');
    }

    // ------------------------------------------------------- the absence side

    #[Test]
    public function a_teacher_absence_is_shown_as_a_deduction_on_the_row(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'absent',
                'party' => 'teacher',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->friday]))
            ->assertOk()
            ->assertSee('Absent (Teacher)')
            ->assertSee('Deducted from payout');
    }

    #[Test]
    public function a_student_absence_is_not_shown_as_a_deduction(): void
    {
        // The instructor showed up and waited, so this one pays.
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->friday,
                'status' => 'absent',
                'party' => 'student',
            ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->friday]))
            ->assertOk()
            ->assertSee('Absent (Student)')
            ->assertDontSee('Deducted from payout');
    }

    // ------------------------------------------------- a makeup date is required

    #[Test]
    public function a_postponement_with_no_makeup_date_is_refused(): void
    {
        // A trial student has no weekly timetable, so MakeupSchedule has no slot
        // to offer and returns a null auto date. The modal forces manual entry
        // in that case, but the endpoint is a plain POST and used to accept the
        // null: the class was written postponed with nowhere to come back to and
        // appeared on no roster, ever.
        $trial = User::factory()->student()->create(['name' => 'A101 No Timetable']);

        StudentProfile::factory()->create([
            'user_id' => $trial->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'is_regular' => false,
            'sessions_remaining' => 5,
        ]);

        $this->assertSame(0, StudentSchedule::where('student_id', $trial->id)->count());

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $trial->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'student',
                'reason' => 'Cannot make it today',
            ])
            ->assertSessionHasErrors('rescheduled_date');

        $this->assertSame(0, ClassSession::where('student_id', $trial->id)->count());
    }

    #[Test]
    public function a_student_with_no_timetable_can_still_be_postponed_to_a_chosen_date(): void
    {
        $trial = User::factory()->student()->create(['name' => 'A102 Manual Makeup']);

        StudentProfile::factory()->create([
            'user_id' => $trial->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'is_regular' => false,
            'sessions_remaining' => 5,
        ]);

        $makeupDate = CarbonImmutable::parse($this->friday)->addWeek()->toDateString();

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $trial->id,
                'date' => $this->friday,
                'status' => 'postponed',
                'party' => 'student',
                'reason' => 'Cannot make it today',
                'reschedule' => 'manual',
                'rescheduled_date' => $makeupDate,
            ])
            ->assertSessionHasNoErrors();

        $session = ClassSession::where('student_id', $trial->id)->firstOrFail();

        $this->assertSame(SessionStatus::Postponed, $session->status);
        $this->assertSame($makeupDate, $session->rescheduled_date->toDateString());
    }
}
