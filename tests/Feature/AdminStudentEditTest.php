<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\TeachingMethod;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adding a student from the admin area, and editing every detail of one.
 */
class AdminStudentEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructor;

    private User $student;

    private StudentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create();

        $this->profile = StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'teaching_method' => TeachingMethod::Audio,
            'learning_time' => 25,
            'sessions_remaining' => 10,
            'sessions_attended' => 4,
            'sessions_deducted' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->student->name,
            'email' => null,
            'phone' => null,
            'birthday' => null,
            'kakaotalk_id' => null,
            'instructor_id' => $this->instructor->id,
            'teaching_method' => TeachingMethod::Audio->value,
            'learning_time' => 25,
            'is_regular' => '1',
            'sessions_remaining' => 10,
            'sessions_attended' => 4,
            'sessions_deducted' => 0,
            'enrollment_status' => EnrollmentStatus::Approved->value,
            'start_date' => '2026-01-05',
            'end_date' => null,
            'schedule' => [],
        ], $overrides);
    }

    #[Test]
    public function an_admin_can_open_the_edit_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students.edit', $this->student))
            ->assertOk()
            ->assertSee($this->student->name);
    }

    #[Test]
    public function an_instructor_cannot_open_the_admin_edit_form(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('admin.students.edit', $this->student))
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_can_edit_every_detail_in_one_save(): void
    {
        $newInstructor = User::factory()->instructor()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'name' => 'A999 Renamed',
                'email' => 'renamed@example.com',
                'phone' => '010-1234-5678',
                'birthday' => '2010-04-02',
                'kakaotalk_id' => 'renamed_kakao',
                'instructor_id' => $newInstructor->id,
                'teaching_method' => TeachingMethod::VideoKids->value,
                'learning_time' => 30,
                'is_regular' => '0',
                'sessions_remaining' => 12,
                'sessions_attended' => 7,
                'sessions_deducted' => 2,
                'end_date' => '2026-12-31',
                'schedule' => [1 => '18:30', 3 => '19:00'],
            ]))
            ->assertRedirect(route('admin.students.show', $this->student));

        $this->student->refresh();
        $this->profile->refresh();

        $this->assertSame('A999 Renamed', $this->student->name);
        $this->assertSame('renamed@example.com', $this->student->email);
        $this->assertSame('010-1234-5678', $this->student->phone);
        $this->assertSame('2010-04-02', $this->student->birthday->toDateString());

        $this->assertSame($newInstructor->id, $this->profile->instructor_id);
        $this->assertSame(TeachingMethod::VideoKids, $this->profile->teaching_method);
        $this->assertSame(30, $this->profile->learning_time);
        $this->assertFalse($this->profile->is_regular);
        $this->assertSame(12, $this->profile->sessions_remaining);
        $this->assertSame(7, $this->profile->sessions_attended);
        $this->assertSame(2, $this->profile->sessions_deducted);
        $this->assertSame('renamed_kakao', $this->profile->kakaotalk_id);
        $this->assertSame('2026-12-31', $this->profile->end_date->toDateString());
    }

    #[Test]
    public function saving_rewrites_the_weekly_timetable(): void
    {
        StudentSchedule::create([
            'student_id' => $this->student->id,
            'day_of_week' => 5,
            'start_time' => '17:00',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'schedule' => [1 => '18:30'],
            ]));

        // Unticking Friday removes it; the day that stayed keeps its new time.
        $this->assertDatabaseMissing('student_schedules', [
            'student_id' => $this->student->id,
            'day_of_week' => 5,
        ]);
        $this->assertDatabaseHas('student_schedules', [
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '18:30:00',
        ]);
    }

    #[Test]
    public function an_edit_records_what_changed(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'sessions_remaining' => 3,
            ]));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.updated',
            'user_id' => $this->admin->id,
            'auditable_id' => $this->student->id,
        ]);
    }

    #[Test]
    public function reassigning_through_the_edit_form_is_logged_as_a_reassignment(): void
    {
        $newInstructor = User::factory()->instructor()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'instructor_id' => $newInstructor->id,
            ]));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.reassigned',
            'user_id' => $this->admin->id,
        ]);
    }

    #[Test]
    public function keeping_the_same_instructor_does_not_log_a_reassignment(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'name' => 'A999 Renamed',
            ]));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'student.reassigned']);
    }

    #[Test]
    public function rejecting_from_the_edit_form_archives_the_student(): void
    {
        // The rejection goes through EnrollmentService, so it carries the same
        // side effect as the approval queue: deactivated, never deleted.
        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'enrollment_status' => EnrollmentStatus::Rejected->value,
                'rejection_reason' => 'Duplicate enrolment',
            ]));

        $this->profile->refresh();
        $this->student->refresh();

        $this->assertSame(EnrollmentStatus::Rejected, $this->profile->enrollment_status);
        $this->assertSame('Duplicate enrolment', $this->profile->rejection_reason);
        $this->assertFalse($this->student->is_active);
        $this->assertNotNull($this->student->fresh());
    }

    #[Test]
    public function a_decided_enrolment_cannot_be_moved_back_to_pending(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.students.update', $this->student), $this->payload([
                'name' => 'A999 Renamed',
                'enrollment_status' => EnrollmentStatus::Pending->value,
            ]))
            ->assertSessionHasErrors('enrollment_status');

        // The whole save is one transaction, so the rename rolled back with it.
        $this->assertSame($this->student->name, $this->student->fresh()->name);
    }

    #[Test]
    public function an_admin_can_add_a_student_and_it_is_approved_on_save(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students.store'), [
                'name' => 'A777 New Student',
                'kakaotalk_id' => 'new_kakao',
                'instructor_id' => $this->instructor->id,
                'teaching_method' => TeachingMethod::VideoAdults->value,
                'learning_time' => 25,
                'sessions_purchased' => 30,
                'sessions_deducted' => 5,
                'is_regular' => '1',
                'start_date' => '2026-02-01',
                'schedule' => [2 => '20:00'],
            ])
            ->assertRedirect();

        $student = User::query()->where('name', 'A777 New Student')->sole();
        $profile = StudentProfile::query()->where('user_id', $student->id)->sole();

        $this->assertSame(EnrollmentStatus::Approved, $profile->enrollment_status);
        $this->assertSame($this->instructor->id, $profile->instructor_id);
        // Purchased minus what was written off at enrolment.
        $this->assertSame(25, $profile->sessions_remaining);
        $this->assertSame(5, $profile->sessions_deducted);

        $this->assertDatabaseHas('student_schedules', [
            'student_id' => $student->id,
            'day_of_week' => 2,
            'start_time' => '20:00:00',
        ]);
    }

    #[Test]
    public function sessions_written_off_cannot_exceed_sessions_purchased(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students.store'), [
                'name' => 'A778 Over Deducted',
                'teaching_method' => TeachingMethod::Audio->value,
                'learning_time' => 25,
                'sessions_purchased' => 10,
                'sessions_deducted' => 11,
                'is_regular' => '1',
            ])
            ->assertSessionHasErrors('sessions_deducted');

        $this->assertDatabaseMissing('users', ['name' => 'A778 Over Deducted']);
    }
}
