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
 * Admin approval of students that instructors enrolled.
 */
class EnrollmentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StudentProfile $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $student = User::factory()->student()->create();
        $instructor = User::factory()->instructor()->create();

        $this->enrollment = StudentProfile::factory()->pending()->create([
            'user_id' => $student->id,
            'instructor_id' => $instructor->id,
        ]);
    }

    #[Test]
    public function an_admin_can_approve_an_enrolment(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.approve', $this->enrollment))
            ->assertRedirect();

        $this->enrollment->refresh();

        $this->assertSame(EnrollmentStatus::Approved, $this->enrollment->enrollment_status);
        $this->assertNotNull($this->enrollment->enrollment_decided_at);
        $this->assertSame($this->admin->id, $this->enrollment->enrollment_decided_by);
    }

    #[Test]
    public function approving_records_who_decided_it(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.approve', $this->enrollment));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'enrollment.approved',
            'user_id' => $this->admin->id,
        ]);
    }

    #[Test]
    public function rejecting_deactivates_the_student_but_never_deletes_them(): void
    {
        // Destroying the row is what made instructor earnings so hard to
        // preserve in the legacy app.
        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.reject', $this->enrollment), [
                'reason' => 'Duplicate enrolment',
            ])
            ->assertRedirect();

        $this->enrollment->refresh();

        $this->assertSame(EnrollmentStatus::Rejected, $this->enrollment->enrollment_status);
        $this->assertSame('Duplicate enrolment', $this->enrollment->rejection_reason);
        $this->assertFalse($this->enrollment->user->is_active);

        // Still there, and not soft-deleted either.
        $this->assertDatabaseHas('users', [
            'id' => $this->enrollment->user_id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function a_rejected_enrolment_can_be_reinstated(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.reject', $this->enrollment));

        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.reinstate', $this->enrollment));

        $this->enrollment->refresh();

        $this->assertSame(EnrollmentStatus::Approved, $this->enrollment->enrollment_status);
        $this->assertTrue($this->enrollment->user->is_active);
        $this->assertNull($this->enrollment->rejection_reason);
    }

    #[Test]
    public function approving_twice_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.approve', $this->enrollment));

        $this->actingAs($this->admin)
            ->patch(route('admin.enrollments.approve', $this->enrollment->refresh()))
            ->assertSessionHasErrors('enrollment');
    }

    #[Test]
    public function an_instructor_cannot_approve_an_enrolment(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->patch(route('admin.enrollments.approve', $this->enrollment))
            ->assertForbidden();

        $this->assertSame(
            EnrollmentStatus::Pending,
            $this->enrollment->refresh()->enrollment_status,
            'an instructor must not be able to approve their own enrolment'
        );
    }

    #[Test]
    public function a_pending_student_does_not_appear_in_their_instructors_class_roster(): void
    {
        // Until approved, the student must not be teachable or billable.
        $instructor = $this->enrollment->instructor;

        StudentSchedule::create([
            'student_id' => $this->enrollment->user_id,
            'day_of_week' => now()->dayOfWeekIso,
            'start_time' => '18:30:00',
        ]);

        $this->actingAs($instructor)
            ->get(route('instructor.classes.index'))
            ->assertOk()
            ->assertDontSee($this->enrollment->user->name);
    }

    #[Test]
    public function the_pending_queue_is_surfaced_on_the_admin_dashboard(): void
    {
        // The sidebar label matches the legacy menu ("Pending Enrollments"), and
        // the dashboard banner states the count.
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pending Enrollments')
            ->assertSee('waiting on you');
    }
}
