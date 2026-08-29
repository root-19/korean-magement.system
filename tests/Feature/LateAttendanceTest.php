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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Marking a class after its day has passed.
 *
 * Marking a session releases its payment, so a late marking is a payroll edit.
 * Today is open; anything earlier is closed until an admin approves that exact
 * class. The button is hidden in the roster, but the rule lives in the endpoint
 * — a form is a plain POST anyone could replay.
 */
class LateAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $admin;

    private User $student;

    /** A Wednesday. */
    private string $today = '2026-08-12';

    private string $yesterday = '2026-08-11';

    protected function setUp(): void
    {
        parent::setUp();

        // Carbon and CarbonImmutable keep separate test clocks; the views read
        // now() while the gate reads CarbonImmutable::today().
        CarbonImmutable::setTestNow($this->today.' 14:00:00');
        Carbon::setTestNow($this->today.' 14:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $this->student = User::factory()->student()->create(['name' => 'A501 Late Student']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);

        foreach (range(1, 5) as $day) {
            StudentSchedule::create([
                'student_id' => $this->student->id,
                'day_of_week' => $day,
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

    private function mark(string $date): TestResponse
    {
        return $this->actingAs($this->instructor)->post(route('instructor.classes.attendance'), [
            'student_id' => $this->student->id,
            'date' => $date,
            'status' => 'present',
        ]);
    }

    // ------------------------------------------------------------- the rule

    #[Test]
    public function todays_class_can_be_marked_freely(): void
    {
        $this->mark($this->today)->assertSessionHasNoErrors();

        $this->assertSame(1, ClassSession::count());
    }

    #[Test]
    public function yesterdays_class_is_closed(): void
    {
        // One day late is already late: the grace period is the day itself.
        $this->mark($this->yesterday)->assertSessionHasErrors('date');

        $this->assertSame(0, ClassSession::count());
    }

    #[Test]
    public function a_closed_class_cannot_be_marked_by_replaying_the_form(): void
    {
        // The roster hides the buttons, but that is not what enforces the rule.
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => '2026-07-01',
                'status' => 'absent',
                'party' => 'student',
            ])
            ->assertSessionHasErrors('date');

        $this->assertSame(0, ClassSession::count());
    }

    // -------------------------------------------------------- the request

    #[Test]
    public function an_instructor_can_send_a_late_class_for_evaluation(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.evaluation'), [
                'student_id' => $this->student->id,
                'date' => $this->yesterday,
                'reason' => 'Internet went down during the class.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $request = AttendanceRequest::firstOrFail();

        $this->assertTrue($request->isPending());
        $this->assertSame($this->yesterday, $request->class_date->toDateString());
        $this->assertSame('Internet went down during the class.', $request->reason);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.evaluation'), [
                'student_id' => $this->student->id,
                'date' => $this->yesterday,
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, AttendanceRequest::count());
    }

    #[Test]
    public function a_pending_request_does_not_open_the_class(): void
    {
        $this->actingAs($this->instructor)->post(route('instructor.classes.evaluation'), [
            'student_id' => $this->student->id,
            'date' => $this->yesterday,
            'reason' => 'Asking nicely.',
        ]);

        $this->mark($this->yesterday)->assertSessionHasErrors('date');

        $this->assertSame(0, ClassSession::count());
    }

    // ------------------------------------------------------- the decision

    #[Test]
    public function approval_reopens_that_one_class(): void
    {
        $request = AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Power cut.',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.evaluations.decide', $request), ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertTrue($request->refresh()->isApproved());
        $this->assertSame($this->admin->id, $request->decided_by);

        $this->mark($this->yesterday)->assertSessionHasNoErrors();
        $this->assertSame(1, ClassSession::count());
    }

    #[Test]
    public function approval_does_not_open_any_other_day(): void
    {
        // One approval, one class — that is the whole point of the audit trail.
        AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Power cut.',
            'status' => AttendanceRequest::APPROVED,
        ]);

        $this->mark('2026-08-10')->assertSessionHasErrors('date');

        $this->assertSame(0, ClassSession::count());
    }

    #[Test]
    public function rejection_leaves_the_class_closed(): void
    {
        $request = AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Forgot.',
        ]);

        $this->actingAs($this->admin)->patch(route('admin.evaluations.decide', $request), [
            'decision' => 'rejected',
            'decision_note' => 'Mark it on the day next time.',
        ]);

        $this->assertTrue($request->refresh()->isRejected());
        $this->mark($this->yesterday)->assertSessionHasErrors('date');
    }

    #[Test]
    public function asking_again_after_a_rejection_reuses_the_same_row(): void
    {
        AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Forgot.',
            'status' => AttendanceRequest::REJECTED,
            'decision_note' => 'Not good enough.',
        ]);

        $this->actingAs($this->instructor)->post(route('instructor.classes.evaluation'), [
            'student_id' => $this->student->id,
            'date' => $this->yesterday,
            'reason' => 'Adding detail: the student joined but my laptop died.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, AttendanceRequest::count(), 'one row per class, not one per attempt');

        $request = AttendanceRequest::firstOrFail();
        $this->assertTrue($request->isPending());
        $this->assertNull($request->decision_note, 'the old rejection note is cleared');
    }

    #[Test]
    public function marking_a_reopened_class_says_which_payslip_it_lands_on(): void
    {
        // Aug 11 is a Tuesday; the payout week runs Saturday to Friday, so a
        // class from the week before does not show on the current payslip.
        AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => '2026-08-07',
            'reason' => 'Power cut.',
            'status' => AttendanceRequest::APPROVED,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => '2026-08-07',
                'status' => 'present',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Aug 1')
                && str_contains($m, 'payslip'));
    }

    #[Test]
    public function marking_todays_class_says_nothing_about_payslips(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->today,
                'status' => 'present',
            ])
            ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'payslip'));
    }

    // ------------------------------------------------------------- access

    #[Test]
    public function an_instructor_cannot_decide_their_own_request(): void
    {
        $request = AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Please.',
        ]);

        $this->actingAs($this->instructor)
            ->patch(route('admin.evaluations.decide', $request), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertTrue($request->refresh()->isPending());
    }

    #[Test]
    public function an_instructor_cannot_request_for_someone_elses_student(): void
    {
        $other = User::factory()->student()->create();
        StudentProfile::factory()->create([
            'user_id' => $other->id,
            'instructor_id' => User::factory()->instructor()->create()->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.evaluation'), [
                'student_id' => $other->id,
                'date' => $this->yesterday,
                'reason' => 'Trying it on.',
            ])
            ->assertForbidden();

        $this->assertSame(0, AttendanceRequest::count());
    }

    // ----------------------------------------------------------------- UI

    #[Test]
    public function the_roster_offers_evaluation_instead_of_marking_on_a_closed_day(): void
    {
        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->yesterday]))
            ->assertOk()
            ->assertSee('For evaluation')
            ->getContent();

        $buttons = substr($html, 0, strpos($html, 'Who was absent?') ?: strlen($html));

        $this->assertStringNotContainsString('>Present<', $buttons);
    }

    #[Test]
    public function the_admin_queue_lists_the_request_and_its_reason(): void
    {
        AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Internet went down during the class.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.index'))
            ->assertOk()
            ->assertSee('A501 Late Student')
            ->assertSee('Internet went down during the class.')
            ->assertSee('Approve')
            ->assertSee('Reject');
    }

    // ------------------------------------------- the same rule, the early path

    private function markEarly(string $heldDate): TestResponse
    {
        return $this->actingAs($this->instructor)->post(route('instructor.classes.early'), [
            'student_id' => $this->student->id,
            'held_date' => $heldDate,
            'target_date' => CarbonImmutable::parse($this->today)->addWeeks(3)->toDateString(),
        ]);
    }

    #[Test]
    public function an_early_class_cannot_back_date_its_way_past_the_gate(): void
    {
        // paid_date is COALESCE(held_date, scheduled_date), so held_date decides
        // which payslip this lands in. Recording it into a week that has already
        // been paid is the same payroll edit the direct marking is held back
        // from — and this endpoint used to allow it, which made the button next
        // to it a way around the whole evaluation queue.
        $threeWeeksBack = CarbonImmutable::parse($this->today)->subWeeks(3)->toDateString();

        $this->markEarly($threeWeeksBack)->assertSessionHasErrors('held_date');

        $this->assertSame(0, ClassSession::count());
    }

    #[Test]
    public function an_early_class_held_today_is_still_free(): void
    {
        $this->markEarly($this->today)->assertSessionHasNoErrors();

        $this->assertSame($this->today, ClassSession::firstOrFail()->paid_date->toDateString());
    }

    #[Test]
    public function an_approved_evaluation_reopens_the_early_path_too(): void
    {
        // The gate is not a ban on past dates, it is a ban on unapproved ones.
        AttendanceRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->yesterday,
            'reason' => 'Taught it early and forgot to record it.',
            'status' => AttendanceRequest::APPROVED,
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
        ]);

        $this->markEarly($this->yesterday)->assertSessionHasNoErrors();

        $this->assertSame($this->yesterday, ClassSession::firstOrFail()->paid_date->toDateString());
    }
}
