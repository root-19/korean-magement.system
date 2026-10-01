<?php

namespace App\Services\Students;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;

/**
 * Where a student is in their plan: classes attended, the plan total, what is
 * left, and the absence and postponement breakdown by who was responsible.
 *
 * Counted across every instructor who has taught the student, because the plan
 * sits on the student's profile and none of it is per-teacher.
 */
class ClassProgress
{
    /**
     * @return array<string, int>
     */
    public function for(StudentProfile $profile): array
    {
        $counts = ClassSession::query()
            ->selectRaw('
                SUM(status = ?) as attended,
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ? AND absent_by = ?) as teacher_absent,
                SUM(status = ? AND postponed_by = ?) as student_postponed,
                SUM(status = ? AND postponed_by = ?) as teacher_postponed
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Absent->value, Party::Teacher->value,
                SessionStatus::Postponed->value, Party::Student->value,
                SessionStatus::Postponed->value, Party::Teacher->value,
            ])
            ->where('student_id', $profile->user_id)
            ->first();

        $studentAbsent = (int) ($counts->student_absent ?? 0);

        return [
            'attended' => (int) ($counts->attended ?? 0),
            'student_absent' => $studentAbsent,
            'teacher_absent' => (int) ($counts->teacher_absent ?? 0),
            'student_postponed' => (int) ($counts->student_postponed ?? 0),
            'teacher_postponed' => (int) ($counts->teacher_postponed ?? 0),
            'remaining' => (int) $profile->sessions_remaining,
            'deducted' => (int) $profile->sessions_deducted,
            'purchased' => $profile->sessionsPurchased($studentAbsent),
        ];
    }
}
