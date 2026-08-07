<?php

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Enums\TeachingMethod;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a student account, profile and weekly timetable in one transaction.
 *
 * Replaces AuthController::registerStudent / InstructorController::registerStudent
 * — two near-identical 130-line methods that each did a 21-column INSERT into the
 * users god-table, then adjusted `semester` by `deduction_days` in PHP.
 *
 * A student enrolled by an INSTRUCTOR starts pending, awaiting admin approval.
 * One created by an ADMIN is approved immediately.
 */
class StudentEnroller
{
    /**
     * @param  array{
     *     name: string,
     *     email?: ?string,
     *     phone?: ?string,
     *     password?: ?string,
     *     teaching_method?: ?string,
     *     learning_time?: ?int,
     *     sessions_purchased?: ?int,
     *     sessions_deducted?: ?int,
     *     is_regular?: bool,
     *     start_date?: ?string,
     *     kakaotalk_id?: ?string,
     *     schedule?: array<int, string>,
     * }  $data
     * @return array{student: User, profile: StudentProfile, password: string}
     */
    public function enrol(User $actor, array $data, ?User $instructor = null): array
    {
        $instructor ??= $actor->isInstructor() ? $actor : null;

        return DB::transaction(function () use ($actor, $data, $instructor) {
            // Students are enrolled on their behalf and rarely have an email, so
            // a password is generated and handed to whoever enrolled them.
            $password = $data['password'] ?? Str::upper(Str::random(8));

            $email = trim((string) ($data['email'] ?? ''));

            $student = User::create([
                'name' => $data['name'],
                'email' => $email !== '' ? $email : null,
                'password' => $password,
                'role' => Role::Student,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            // Sessions purchased, minus any written off at enrolment, is what the
            // student has left to use. The legacy code did this subtraction in the
            // controller and stored only the result, losing the original figure.
            $purchased = max(0, (int) ($data['sessions_purchased'] ?? 0));
            $deducted = min($purchased, max(0, (int) ($data['sessions_deducted'] ?? 0)));

            $profile = StudentProfile::create([
                'user_id' => $student->id,
                'instructor_id' => $instructor?->id,
                'teaching_method' => TeachingMethod::tryFrom((string) ($data['teaching_method'] ?? '')),
                'learning_time' => $data['learning_time'] ?? null,
                'sessions_remaining' => $purchased - $deducted,
                'sessions_attended' => 0,
                'sessions_deducted' => $deducted,
                'is_regular' => (bool) ($data['is_regular'] ?? true),
                // An instructor's enrolment needs approval; an admin's does not.
                'enrollment_status' => $actor->isAdmin()
                    ? EnrollmentStatus::Approved
                    : EnrollmentStatus::Pending,
                'enrollment_decided_at' => $actor->isAdmin() ? now() : null,
                'enrollment_decided_by' => $actor->isAdmin() ? $actor->id : null,
                'start_date' => $data['start_date'] ?? null,
                'kakaotalk_id' => $data['kakaotalk_id'] ?? null,
            ]);

            $this->syncSchedule($student, $data['schedule'] ?? []);

            AuditLog::record(
                action: 'student.enrolled',
                subject: $student,
                targetName: $student->name,
                details: [
                    'instructor_id' => $instructor?->id,
                    'sessions_purchased' => $purchased,
                    'sessions_deducted' => $deducted,
                    'enrollment_status' => $profile->enrollment_status->value,
                ],
                userId: $actor->id,
            );

            return ['student' => $student, 'profile' => $profile, 'password' => $password];
        });
    }

    /**
     * Write the weekly timetable as one row per day.
     *
     * `$schedule` maps an ISO day number to a time: [1 => '18:30', 3 => '18:30'].
     * A day with no time is skipped — a schedule row cannot exist without one.
     *
     * @param  array<int, string>  $schedule
     */
    public function syncSchedule(User $student, array $schedule): void
    {
        $keep = [];

        foreach ($schedule as $isoDay => $time) {
            $isoDay = (int) $isoDay;
            $time = trim((string) $time);

            if ($isoDay < 1 || $isoDay > 7 || $time === '') {
                continue;
            }

            StudentSchedule::updateOrCreate(
                ['student_id' => $student->id, 'day_of_week' => $isoDay],
                ['start_time' => $time],
            );

            $keep[] = $isoDay;
        }

        // Days no longer selected are dropped, so unticking a day removes it.
        StudentSchedule::query()
            ->where('student_id', $student->id)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('day_of_week', $keep))
            ->delete();
    }
}
