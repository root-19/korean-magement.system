<?php

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin approval of students enrolled by an instructor.
 *
 * Replaces AdminController::approveEnrollment / rejectEnrollment, which flipped
 * the column with a bare UPDATE and recorded nothing about who decided or why.
 */
class EnrollmentService
{
    public function approve(User $admin, StudentProfile $profile): StudentProfile
    {
        return DB::transaction(function () use ($admin, $profile) {
            $profile->refresh();

            if ($profile->enrollment_status === EnrollmentStatus::Approved) {
                throw ValidationException::withMessages([
                    'enrollment' => 'That enrolment has already been approved.',
                ]);
            }

            $profile->update([
                'enrollment_status' => EnrollmentStatus::Approved,
                'enrollment_decided_at' => now(),
                'enrollment_decided_by' => $admin->id,
                'rejection_reason' => null,
            ]);

            AuditLog::record(
                action: 'enrollment.approved',
                subject: $profile->user,
                targetName: $profile->user?->name,
                details: ['instructor_id' => $profile->instructor_id],
                userId: $admin->id,
            );

            return $profile;
        });
    }

    /**
     * Reject an enrolment.
     *
     * The student's account is deactivated rather than deleted — attendance and
     * reports may already exist against it, and destroying the row is what the
     * legacy code did that made instructor earnings so hard to preserve.
     */
    public function reject(User $admin, StudentProfile $profile, ?string $reason = null): StudentProfile
    {
        return DB::transaction(function () use ($admin, $profile, $reason) {
            $profile->refresh();

            if ($profile->enrollment_status === EnrollmentStatus::Rejected) {
                throw ValidationException::withMessages([
                    'enrollment' => 'That enrolment has already been rejected.',
                ]);
            }

            $profile->update([
                'enrollment_status' => EnrollmentStatus::Rejected,
                'enrollment_decided_at' => now(),
                'enrollment_decided_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);

            $profile->user?->update(['is_active' => false]);

            AuditLog::record(
                action: 'enrollment.rejected',
                subject: $profile->user,
                targetName: $profile->user?->name,
                details: [
                    'instructor_id' => $profile->instructor_id,
                    'reason' => $reason,
                ],
                userId: $admin->id,
            );

            return $profile;
        });
    }

    /**
     * Put a rejected or archived student back into circulation.
     */
    public function reinstate(User $admin, StudentProfile $profile): StudentProfile
    {
        return DB::transaction(function () use ($admin, $profile) {
            $profile->update([
                'enrollment_status' => EnrollmentStatus::Approved,
                'enrollment_decided_at' => now(),
                'enrollment_decided_by' => $admin->id,
                'rejection_reason' => null,
            ]);

            $profile->user?->update(['is_active' => true]);

            AuditLog::record(
                action: 'enrollment.reinstated',
                subject: $profile->user,
                targetName: $profile->user?->name,
                userId: $admin->id,
            );

            return $profile;
        });
    }
}
