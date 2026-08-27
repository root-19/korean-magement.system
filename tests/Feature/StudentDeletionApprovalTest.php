<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentDeletionRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deleting a student, as a request and an approval.
 *
 * Two rules are under test. An instructor can ask but never delete — the button
 * posts a request and nothing else. And an approved deletion must not move a
 * single peso of anybody's pay: the classes taught to the student are what the
 * instructor is paid for, so they survive the deletion that removes the student
 * from every list.
 */
class StudentDeletionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $admin;

    private User $student;

    private StudentProfile $profile;

    /** A Wednesday. */
    private string $today = '2026-08-12';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->today.' 14:00:00');
        Carbon::setTestNow($this->today.' 14:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $this->student = User::factory()->student()->create(['name' => 'A501 Leaving Student']);

        $this->profile = StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'teaching_method' => TeachingMethod::VideoKids,
            'learning_time' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pendingRequest(): StudentDeletionRequest
    {
        return StudentDeletionRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'student_name' => $this->student->name,
            'reason' => 'Enrolled twice by mistake.',
        ]);
    }

    /** A taught, reported class inside the current payout window. */
    private function taughtClass(string $date): ClassSession
    {
        $session = ClassSession::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $date,
            'status' => SessionStatus::Present,
            'marked_by' => $this->instructor->id,
            'marked_at' => now(),
        ]);

        SessionReport::create([
            'class_session_id' => $session->id,
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $date,
            'today_lesson' => 'Unit 3',
        ]);

        return $session;
    }

    // -------------------------------------------------------------- the ask

    #[Test]
    public function an_instructor_can_request_a_deletion_with_a_reason(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.students.deletion', $this->student), [
                'reason' => 'Enrolled twice by mistake — every class is on the other account.',
            ])
            ->assertRedirect(route('instructor.students.index'))
            ->assertSessionHasNoErrors();

        $request = StudentDeletionRequest::firstOrFail();

        $this->assertTrue($request->isPending());
        $this->assertSame($this->student->id, $request->student_id);
        $this->assertSame('A501 Leaving Student', $request->student_name);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.students.deletion', $this->student), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, StudentDeletionRequest::count());
    }

    #[Test]
    public function requesting_deletes_nothing_by_itself(): void
    {
        $this->actingAs($this->instructor)->post(
            route('instructor.students.deletion', $this->student),
            ['reason' => 'Please remove this duplicate account.'],
        );

        // The point of the whole flow: asking is not doing.
        $this->assertNotNull($this->student->fresh(), 'the student is untouched until an admin decides');
        $this->assertTrue($this->student->fresh()->is_active);
    }

    #[Test]
    public function a_second_request_while_one_is_pending_is_refused(): void
    {
        $this->pendingRequest();

        $this->actingAs($this->instructor)
            ->post(route('instructor.students.deletion', $this->student), ['reason' => 'Asking again.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, StudentDeletionRequest::count());
    }

    #[Test]
    public function asking_again_after_a_rejection_reuses_the_same_row(): void
    {
        StudentDeletionRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'student_name' => $this->student->name,
            'reason' => 'Not needed.',
            'status' => StudentDeletionRequest::REJECTED,
            'decision_note' => 'Archive them instead.',
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.students.deletion', $this->student), [
                'reason' => 'Adding detail: the account was created twice on the same day.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, StudentDeletionRequest::count(), 'one row per student, not one per attempt');

        $request = StudentDeletionRequest::firstOrFail();
        $this->assertTrue($request->isPending());
        $this->assertNull($request->decision_note, 'the old rejection note is cleared');
    }

    // --------------------------------------------------------- the decision

    #[Test]
    public function approval_removes_the_student_from_the_instructors_list(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->patch(route('admin.deletions.decide', $request), ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($request->refresh()->isApproved());
        $this->assertSame($this->admin->id, $request->decided_by);

        $this->assertSoftDeleted('users', ['id' => $this->student->id]);
        $this->assertSame($this->admin->id, User::withTrashed()->find($this->student->id)->deleted_by);

        // The admin's confirmation names the student, and it is still sitting in
        // the shared test session — drop it, or the next page renders that flash
        // and the assertions below match on it instead of on a table row.
        $this->flushSession();

        $this->actingAs($this->instructor)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->assertDontSee('A501 Leaving Student');

        // Nor on the archived tab: deleted is not archived.
        $this->actingAs($this->instructor)
            ->get(route('instructor.students.index', ['status' => 'archived']))
            ->assertOk()
            ->assertDontSee('A501 Leaving Student');
    }

    #[Test]
    public function a_deleted_student_cannot_sign_in(): void
    {
        $student = User::factory()->student()->create([
            'email' => 'leaving@example.test',
            'password' => 'secret-password',
        ]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);

        $request = StudentDeletionRequest::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'student_name' => $student->name,
            'reason' => 'Duplicate account.',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.deletions.decide', $request), ['decision' => 'approved']);

        // Sign the admin out first: the login route is behind `guest`, so an
        // authenticated session would be redirected before it got there.
        Auth::logout();
        $this->flushSession();

        $this->post(route('login.store'), [
            'login' => 'leaving@example.test',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    #[Test]
    public function an_admin_can_put_a_deleted_student_back(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->patch(route('admin.deletions.decide', $request), ['decision' => 'approved']);

        // One button on the student page undoes both halves of the deletion —
        // restoring is_active alone would leave them invisible everywhere.
        $this->actingAs($this->admin)
            ->patch(route('admin.students.status', $this->student), [])
            ->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);

        $restored = User::find($this->student->id);
        $this->assertNotNull($restored);
        $this->assertTrue($restored->is_active);
        $this->assertNull($restored->deleted_by);

        $this->flushSession();

        $this->actingAs($this->instructor)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->assertSee('A501 Leaving Student');
    }

    #[Test]
    public function rejection_leaves_the_student_alone(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)->patch(route('admin.deletions.decide', $request), [
            'decision' => 'rejected',
            'decision_note' => 'Archive them instead.',
        ]);

        $this->assertTrue($request->refresh()->isRejected());
        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);
        $this->assertTrue($this->student->fresh()->is_active);
    }

    #[Test]
    public function a_decided_request_cannot_be_decided_twice(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)->patch(route('admin.deletions.decide', $request), ['decision' => 'approved']);

        $this->actingAs($this->admin)
            ->patch(route('admin.deletions.decide', $request), ['decision' => 'rejected'])
            ->assertSessionHasErrors('decision');

        $this->assertTrue($request->refresh()->isApproved());
    }

    // ------------------------------------------------------------- payroll

    #[Test]
    public function deleting_a_student_does_not_change_the_instructors_payout(): void
    {
        // Three classes inside the current window, each with a report filed, so
        // every one of them pays under the feedback rule.
        foreach (['2026-08-10', '2026-08-11', '2026-08-12'] as $date) {
            $this->taughtClass($date);
        }

        $calculator = app(EarningsCalculator::class);
        $window = PayoutWindow::forDate($this->today);

        $before = $calculator->forWindow($this->instructor->id, $window);

        $this->assertGreaterThan(0.0, $before->net(), 'the fixture has to pay something for this test to mean anything');

        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->patch(route('admin.deletions.decide', $request), ['decision' => 'approved'])
            ->assertSessionHasNoErrors();

        $after = $calculator->forWindow($this->instructor->id, $window);

        $this->assertSame($before->net(), $after->net(), 'a deletion must never restate pay');
        $this->assertCount(3, $after->lines, 'every taught class still appears on the payslip');

        // The rows themselves are still there: a cascade would have taken them.
        $this->assertSame(3, ClassSession::where('student_id', $this->student->id)->count());
        $this->assertSame(3, SessionReport::where('student_id', $this->student->id)->count());
    }

    #[Test]
    public function the_deleted_students_name_still_reads_on_the_payslip(): void
    {
        $this->taughtClass('2026-08-11');

        $request = $this->pendingRequest();

        $this->actingAs($this->admin)->patch(route('admin.deletions.decide', $request), ['decision' => 'approved']);

        $this->actingAs($this->instructor)
            ->get(route('instructor.earnings.index'))
            ->assertOk()
            ->assertSee('A501 Leaving Student');
    }

    // ------------------------------------------------- the admin's own delete

    #[Test]
    public function an_admin_can_delete_a_student_outright(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.students.destroy', $this->student), ['reason' => 'Left the academy.'])
            ->assertRedirect(route('admin.students.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('users', ['id' => $this->student->id]);

        // Both halves, as an approval does them: invisible everywhere AND unable
        // to sign in. Traceable to the admin who did it.
        $deleted = User::withTrashed()->find($this->student->id);
        $this->assertFalse((bool) $deleted->is_active);
        $this->assertSame($this->admin->id, $deleted->deleted_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.deleted',
            'user_id' => $this->admin->id,
            'target_name' => 'A501 Leaving Student',
        ]);

        // No request was ever filed — this path does not need one.
        $this->assertSame(0, StudentDeletionRequest::count());
    }

    #[Test]
    public function a_directly_deleted_student_can_be_restored(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.students.destroy', $this->student));

        $this->actingAs($this->admin)
            ->patch(route('admin.students.status', $this->student), [])
            ->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);

        $restored = User::find($this->student->id);
        $this->assertTrue($restored->is_active);
        $this->assertNull($restored->deleted_by);
    }

    #[Test]
    public function deleting_directly_settles_a_request_already_in_the_queue(): void
    {
        // Otherwise the queue would go on asking an admin to do what they have
        // already done, on a student who is no longer there to delete.
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->delete(route('admin.students.destroy', $this->student), ['reason' => 'Agreed, removing now.'])
            ->assertSessionHasNoErrors();

        $request->refresh();

        $this->assertTrue($request->isApproved());
        $this->assertSame($this->admin->id, $request->decided_by);
        $this->assertSame('Agreed, removing now.', $request->decision_note);
    }

    #[Test]
    public function an_already_deleted_student_cannot_be_deleted_again(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.students.destroy', $this->student));

        // The binding does not resolve trashed students, so there is nothing to
        // act on a second time.
        $this->actingAs($this->admin)
            ->delete(route('admin.students.destroy', $this->student))
            ->assertNotFound();

        $this->assertSame(1, AuditLog::where('action', 'student.deleted')->count());
    }

    #[Test]
    public function deleting_directly_does_not_change_the_instructors_payout(): void
    {
        foreach (['2026-08-10', '2026-08-11', '2026-08-12'] as $date) {
            $this->taughtClass($date);
        }

        $calculator = app(EarningsCalculator::class);
        $window = PayoutWindow::forDate($this->today);

        $before = $calculator->forWindow($this->instructor->id, $window);
        $this->assertGreaterThan(0.0, $before->net());

        $this->actingAs($this->admin)
            ->delete(route('admin.students.destroy', $this->student))
            ->assertSessionHasNoErrors();

        $after = $calculator->forWindow($this->instructor->id, $window);

        $this->assertSame($before->net(), $after->net(), 'a deletion must never restate pay');
        $this->assertCount(3, $after->lines);
        $this->assertSame(3, ClassSession::where('student_id', $this->student->id)->count());
        $this->assertSame(3, SessionReport::where('student_id', $this->student->id)->count());
    }

    #[Test]
    public function an_instructor_cannot_use_the_admin_delete(): void
    {
        $this->actingAs($this->instructor)
            ->delete(route('admin.students.destroy', $this->student))
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);
    }

    #[Test]
    public function the_admin_student_pages_offer_the_delete_button(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->assertSee('Delete A501 Leaving Student');

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $this->student))
            ->assertOk()
            ->assertSee('Delete student')
            ->assertSee('Reason (optional)');
    }

    #[Test]
    public function the_delete_button_is_gone_once_the_student_is_deleted(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.students.destroy', $this->student));

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $this->student))
            ->assertOk()
            ->assertSee('Restore student')
            ->assertDontSee('Delete student');

        $this->actingAs($this->admin)
            ->get(route('admin.students.index', ['filter' => 'archived']))
            ->assertOk()
            ->assertSee('A501 Leaving Student')
            ->assertDontSee('Delete A501 Leaving Student');
    }

    // -------------------------------------------------------------- access

    #[Test]
    public function an_instructor_cannot_request_someone_elses_student(): void
    {
        $other = User::factory()->student()->create();
        StudentProfile::factory()->create([
            'user_id' => $other->id,
            'instructor_id' => User::factory()->instructor()->create()->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.students.deletion', $other), ['reason' => 'Trying it on.'])
            ->assertForbidden();

        $this->assertSame(0, StudentDeletionRequest::count());
    }

    #[Test]
    public function an_instructor_cannot_decide_their_own_request(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->instructor)
            ->patch(route('admin.deletions.decide', $request), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertTrue($request->refresh()->isPending());
        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);
    }

    // ------------------------------------------------------------------ UI

    #[Test]
    public function the_students_list_offers_the_delete_button(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->assertSee('Request deletion of A501 Leaving Student')
            ->assertSee('open-delete-modal', false)
            ->assertSee('Why should this student be deleted?');
    }

    #[Test]
    public function a_pending_request_replaces_the_button_on_the_row(): void
    {
        $this->pendingRequest();

        $this->actingAs($this->instructor)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->assertSee('Deletion pending')
            ->assertDontSee('Request deletion of A501 Leaving Student');
    }

    #[Test]
    public function the_admin_queue_lists_the_request_and_its_reason(): void
    {
        $this->taughtClass('2026-08-11');
        $this->pendingRequest();

        $this->actingAs($this->admin)
            ->get(route('admin.deletions.index'))
            ->assertOk()
            ->assertSee('A501 Leaving Student')
            ->assertSee('Enrolled twice by mistake.')
            ->assertSee('Delete student')
            ->assertSee('Reject')
            // What the deletion carries, so nobody approves one blind.
            ->assertSee('1 class')
            ->assertSee('1 report')
            ->assertSee('recorded pay');
    }
}
