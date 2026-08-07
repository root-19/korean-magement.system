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

        CarbonImmutable::setTestNow($this->friday.' 09:00:00');

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

        parent::tearDown();
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
}
