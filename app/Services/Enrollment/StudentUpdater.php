<?php

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Enums\TeachingMethod;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits an existing student: account, plan, timetable and instructor.
 *
 * The counterpart to StudentEnroller, which only ever creates. One save is one
 * transaction and one audit entry naming the fields that moved — the legacy
 * admin pages updated the users god-table a column at a time and recorded
 * nothing, so a changed session count could not be traced to anyone.
 *
 * Enrolment status is not written here. Approving, rejecting and reinstating
 * carry side effects (the student is deactivated by a rejection) that already
 * live in EnrollmentService, so this delegates rather than restating them.
 */
class StudentUpdater
{
    public function __construct(
        private readonly StudentEnroller $enroller,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     email?: ?string,
     *     phone?: ?string,
     *     birthday?: ?string,
     *     kakaotalk_id?: ?string,
     *     instructor_id?: ?int,
     *     teaching_method?: ?string,
     *     learning_time?: ?int,
     *     sessions_remaining: int,
     *     sessions_attended: int,
     *     sessions_deducted: int,
     *     is_regular: bool,
     *     enrollment_status: string,
     *     rejection_reason?: ?string,
     *     start_date?: ?string,
     *     end_date?: ?string,
     *     schedule?: array<int, string>,
     * }  $data
     */
    public function update(User $admin, User $student, StudentProfile $profile, array $data): void
    {
        DB::transaction(function () use ($admin, $student, $profile, $data) {
            // EnrollmentService reads $profile->user to deactivate a rejected
            // student and to name its audit entry. Handing it the instance we
            // already hold keeps that a shared object rather than a second query
            // — and, under preventLazyLoading, an error.
            $profile->setRelation('user', $student);

            $student->fill([
                'name' => $data['name'],
                // Normalised to NULL so blanks never collide under the unique
                // index — most students have no email at all.
                'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
                'phone' => $data['phone'] ?? null,
                'birthday' => $data['birthday'] ?? null,
            ]);

            $changes = $this->pendingChanges($student);
            $student->save();

            $profile->fill([
                'teaching_method' => TeachingMethod::tryFrom((string) ($data['teaching_method'] ?? '')),
                'learning_time' => $data['learning_time'] ?? null,
                'sessions_remaining' => $data['sessions_remaining'],
                'sessions_attended' => $data['sessions_attended'],
                'sessions_deducted' => $data['sessions_deducted'],
                'is_regular' => $data['is_regular'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'kakaotalk_id' => $data['kakaotalk_id'] ?? null,
            ]);

            $changes = array_merge($changes, $this->pendingChanges($profile));
            $profile->save();

            $this->enroller->syncSchedule($student, $data['schedule'] ?? []);

            $this->applyInstructor($admin, $student, $profile, $data['instructor_id'] ?? null);

            $this->applyStatus(
                $admin,
                $profile,
                EnrollmentStatus::from($data['enrollment_status']),
                $data['rejection_reason'] ?? null,
            );

            if ($changes !== []) {
                AuditLog::record(
                    action: 'student.updated',
                    subject: $student,
                    targetName: $student->name,
                    details: $changes,
                    userId: $admin->id,
                );
            }
        });
    }

    /**
     * Move a student to another instructor, or to nobody.
     *
     * Past sessions keep the instructor who taught them — class_sessions carries
     * its own instructor_id — so reassigning never moves historical earnings.
     */
    public function reassign(User $admin, User $student, StudentProfile $profile, ?User $instructor): void
    {
        $previous = $profile->instructor?->name;

        $profile->update(['instructor_id' => $instructor?->id]);

        AuditLog::record(
            action: 'student.reassigned',
            subject: $student,
            targetName: $student->name,
            details: ['from' => $previous, 'to' => $instructor?->name],
            userId: $admin->id,
        );
    }

    // ---------------------------------------------------------------- internals

    /**
     * Reassign only when the picker actually moved, so saving an unrelated field
     * does not fill the audit log with reassignments to the same instructor.
     */
    private function applyInstructor(User $admin, User $student, StudentProfile $profile, ?int $instructorId): void
    {
        if ((int) $instructorId === (int) $profile->instructor_id) {
            return;
        }

        $this->reassign(
            $admin,
            $student,
            $profile,
            $instructorId ? User::query()->instructors()->find($instructorId) : null,
        );
    }

    /**
     * Hand a changed enrolment decision to the service that owns it.
     */
    private function applyStatus(
        User $admin,
        StudentProfile $profile,
        EnrollmentStatus $target,
        ?string $reason,
    ): void {
        if ($target === $profile->enrollment_status) {
            return;
        }

        match ($target) {
            // A rejection deactivated the student, so coming back from it is a
            // reinstatement — approving alone would leave them archived.
            EnrollmentStatus::Approved => $profile->enrollment_status === EnrollmentStatus::Rejected
                ? $this->enrollments->reinstate($admin, $profile)
                : $this->enrollments->approve($admin, $profile),

            EnrollmentStatus::Rejected => $this->enrollments->reject($admin, $profile, $reason),

            // Nothing un-decides an enrolment, and the form never offers it.
            EnrollmentStatus::Pending => throw ValidationException::withMessages([
                'enrollment_status' => 'A decided enrolment cannot be moved back to awaiting approval.',
            ]),
        };
    }

    /**
     * Dirty attributes as `field => [from, to]`, read before save() while
     * getDirty() still has something to say.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function pendingChanges(Model $model): array
    {
        $changes = [];

        foreach ($model->getDirty() as $field => $value) {
            // Raw, not cast: the audit `details` column is JSON, and a Carbon or
            // enum instance would only be stored as its scalar anyway.
            $changes[$field] = ['from' => $model->getRawOriginal($field), 'to' => $value];
        }

        return $changes;
    }
}
