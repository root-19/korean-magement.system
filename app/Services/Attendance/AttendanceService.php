<?php

namespace App\Services\Attendance;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marking attendance, postponing, and pulling a class forward.
 *
 * Every write goes through a transaction because attendance and the student's
 * prepaid session counters must move together. The legacy code updated them in
 * separate un-transacted statements (ClassModel::adjustStudentMetrics), so an
 * error between the two left a student's counters permanently wrong.
 */
class AttendanceService
{
    /**
     * Record attendance for a slot, creating the session row if needed.
     *
     * Idempotent on (instructor, student, scheduledDate): re-marking the same
     * slot updates it in place and adjusts the counters by the difference,
     * rather than double-counting.
     */
    public function mark(
        User $instructor,
        User $student,
        string $scheduledDate,
        SessionStatus $status,
        ?Party $absentBy = null,
        ?string $reason = null,
    ): ClassSession {
        if ($status === SessionStatus::Absent && $absentBy === null) {
            throw ValidationException::withMessages([
                'absent_by' => 'Record who was absent — it decides whether the session is paid or deducted.',
            ]);
        }

        return DB::transaction(function () use ($instructor, $student, $scheduledDate, $status, $absentBy, $reason) {
            $session = ClassSession::query()
                ->where('instructor_id', $instructor->id)
                ->where('student_id', $student->id)
                ->where('scheduled_date', $scheduledDate)
                ->lockForUpdate()
                ->first();

            $previousStatus = $session?->status;

            if ($session === null) {
                $session = new ClassSession([
                    'instructor_id' => $instructor->id,
                    'student_id' => $student->id,
                    'scheduled_date' => $scheduledDate,
                    'scheduled_time' => $this->scheduledTimeFor($student, $scheduledDate),
                ]);
            }

            $session->fill([
                'status' => $status,
                'absent_by' => $status === SessionStatus::Absent ? $absentBy : null,
                'postponed_by' => $status === SessionStatus::Postponed ? $absentBy : null,
                // A makeup row keeps the class it replaces in postpone_reason —
                // the legacy shape, and still how nearly every makeup is stored
                // (ClassSession::MAKEUP_MARKER). Teaching it is no reason to
                // forget that, and the "Present" button posts no reason at all,
                // so the marker has to survive a null. Legacy was careful about
                // this too; see the comment in ClassModel::adjustStudentMetrics.
                'postpone_reason' => $reason ?? ($session->makeupOrigin() ? $session->postpone_reason : null),
                // Marking the slot present or absent settles it on its own date,
                // so it is no longer coming back later and the makeup pointer
                // goes with the postponement. Left behind, it keeps putting the
                // student on a roster for a class that has already happened.
                'rescheduled_date' => $status === SessionStatus::Postponed ? $session->rescheduled_date : null,
                'rescheduled_time' => $status === SessionStatus::Postponed ? $session->rescheduled_time : null,
                'marked_by' => $instructor->id,
                'marked_at' => now(),
            ]);

            $session->save();

            $this->syncCounters($student, $previousStatus, $status, $session->absent_by);

            return $session->refresh();
        });
    }

    /**
     * Record a class taught ahead of its scheduled slot.
     *
     * `$heldDate` is when the work was done, `$targetDate` the future slot it
     * covers. The row sits on `$targetDate` so the timetable stays honest, and
     * `held_date` carries the real date so the instructor is paid in the week
     * they worked.
     *
     * In the legacy schema this could not be expressed: the unique key on
     * (teacher, student, date) meant the held date was already taken by the
     * student's regular class, so the date was smuggled into `postpone_reason`
     * as the string 'Early class held on YYYY-MM-DD' and parsed back out with
     * RIGHT(...,10) in every earnings query.
     */
    public function markEarly(
        User $instructor,
        User $student,
        string $heldDate,
        string $targetDate,
    ): ClassSession {
        $held = CarbonImmutable::parse($heldDate)->startOfDay();
        $target = CarbonImmutable::parse($targetDate)->startOfDay();

        if ($held->isFuture()) {
            throw ValidationException::withMessages([
                'held_date' => 'The date the class was held cannot be in the future.',
            ]);
        }

        if ($target->lte($held)) {
            throw ValidationException::withMessages([
                'target_date' => 'The session being pulled forward must fall after the date it was held.',
            ]);
        }

        return DB::transaction(function () use ($instructor, $student, $held, $target) {
            $profile = $this->lockedProfile($student);

            if ($profile === null || $profile->sessions_remaining < 1) {
                throw ValidationException::withMessages([
                    'target_date' => 'This student has no remaining sessions to pull forward.',
                ]);
            }

            $existing = ClassSession::query()
                ->where('instructor_id', $instructor->id)
                ->where('student_id', $student->id)
                ->where('scheduled_date', $target->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isSettled()) {
                throw ValidationException::withMessages([
                    'target_date' => 'That session has already been marked.',
                ]);
            }

            $session = $existing ?? new ClassSession([
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'scheduled_date' => $target->toDateString(),
                'scheduled_time' => $this->scheduledTimeFor($student, $target->toDateString()),
            ]);

            $previousStatus = $session->status;

            $session->fill([
                'held_date' => $held->toDateString(),
                'status' => SessionStatus::Present,
                'absent_by' => null,
                'postponed_by' => null,
                // Taught, so whatever postponement occupied this slot is over.
                'rescheduled_date' => null,
                'rescheduled_time' => null,
                'marked_by' => $instructor->id,
                'marked_at' => now(),
            ]);

            $session->save();

            $this->syncCounters($student, $previousStatus, SessionStatus::Present, null);

            AuditLog::record(
                action: 'class.marked_early',
                subject: $session,
                targetName: $student->name,
                details: [
                    'held_date' => $held->toDateString(),
                    'scheduled_date' => $target->toDateString(),
                ],
                userId: $instructor->id,
            );

            return $session->refresh();
        });
    }

    /**
     * Postpone a slot. A postponed class is never paid and does not consume a
     * prepaid session, so any counter movement from a previous marking is undone.
     */
    public function postpone(
        User $instructor,
        User $student,
        string $scheduledDate,
        Party $postponedBy,
        ?string $reason = null,
        ?string $rescheduledDate = null,
        ?string $rescheduledTime = null,
    ): ClassSession {
        return DB::transaction(function () use (
            $instructor, $student, $scheduledDate, $postponedBy, $reason, $rescheduledDate, $rescheduledTime
        ) {
            $session = ClassSession::query()
                ->where('instructor_id', $instructor->id)
                ->where('student_id', $student->id)
                ->where('scheduled_date', $scheduledDate)
                ->lockForUpdate()
                ->first();

            $previousStatus = $session?->status;
            $previousAbsentBy = $session?->absent_by;

            $session ??= new ClassSession([
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $this->scheduledTimeFor($student, $scheduledDate),
            ]);

            $session->fill([
                'status' => SessionStatus::Postponed,
                'absent_by' => null,
                'postponed_by' => $postponedBy,
                'postpone_reason' => $reason,
                'rescheduled_date' => $rescheduledDate,
                'rescheduled_time' => $rescheduledTime,
                'marked_by' => $instructor->id,
                'marked_at' => now(),
            ]);

            $session->save();

            $this->syncCounters($student, $previousStatus, SessionStatus::Postponed, null, $previousAbsentBy);

            return $session->refresh();
        });
    }

    /**
     * Clear a marking, returning the slot to unmarked and rolling back counters.
     */
    public function unmark(User $instructor, ClassSession $session): ClassSession
    {
        return DB::transaction(function () use ($instructor, $session) {
            $student = $session->student;
            $previousStatus = $session->status;
            $previousAbsentBy = $session->absent_by;

            $session->fill([
                'status' => null,
                'absent_by' => null,
                'postponed_by' => null,
                'held_date' => null,
                // An unmarked slot is not a postponed one, so the makeup it was
                // pointing at is no longer scheduled. Keeping the pointer would
                // strand a class on the makeup date with nothing left to clear
                // it from there.
                'rescheduled_date' => null,
                'rescheduled_time' => null,
                'marked_by' => $instructor->id,
                'marked_at' => now(),
            ])->save();

            if ($student) {
                $this->syncCounters($student, $previousStatus, null, null, $previousAbsentBy);
            }

            AuditLog::record(
                action: 'class.unmarked',
                subject: $session,
                targetName: $student?->name,
                details: ['previous_status' => $previousStatus?->value],
                userId: $instructor->id,
            );

            return $session->refresh();
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * Move the student's prepaid counters to match a status change.
     *
     * A session is CONSUMED when it is present or the student was absent — both
     * burn a prepaid class. Teacher-absent and postponed do not: the student
     * keeps the credit.
     *
     * Expressed as a delta between the old and new state so re-marking a slot
     * (present -> absent, absent -> postponed, ...) stays correct instead of
     * incrementing blindly the way the legacy helpers did.
     */
    protected function syncCounters(
        User $student,
        ?SessionStatus $from,
        ?SessionStatus $to,
        ?Party $toAbsentBy,
        ?Party $fromAbsentBy = null,
    ): void {
        $profile = $this->lockedProfile($student);

        if ($profile === null) {
            return;
        }

        $wasConsumed = $this->consumesSession($from, $fromAbsentBy);
        $isConsumed = $this->consumesSession($to, $toAbsentBy);

        $wasPresent = $from === SessionStatus::Present;
        $isPresent = $to === SessionStatus::Present;

        $consumedDelta = ($isConsumed ? 1 : 0) - ($wasConsumed ? 1 : 0);
        $attendedDelta = ($isPresent ? 1 : 0) - ($wasPresent ? 1 : 0);

        if ($consumedDelta === 0 && $attendedDelta === 0) {
            return;
        }

        // Clamped at zero, mirroring the legacy GREATEST(x - 1, 0): the counters
        // are denormalised and some legacy rows are already inconsistent, so a
        // correction must never drive one negative.
        $profile->sessions_attended = max(0, $profile->sessions_attended + $attendedDelta);
        $profile->sessions_remaining = max(0, $profile->sessions_remaining - $consumedDelta);
        $profile->save();
    }

    /**
     * Whether a status burns one of the student's prepaid sessions.
     */
    protected function consumesSession(?SessionStatus $status, ?Party $absentBy): bool
    {
        return $status === SessionStatus::Present
            || ($status === SessionStatus::Absent && $absentBy === Party::Student);
    }

    protected function lockedProfile(User $student): ?StudentProfile
    {
        return StudentProfile::query()
            ->where('user_id', $student->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The student's usual time on that weekday, from their timetable.
     */
    protected function scheduledTimeFor(User $student, string $date): ?string
    {
        $isoDay = CarbonImmutable::parse($date)->dayOfWeekIso;

        return $student->schedules
            ->firstWhere('day_of_week', $isoDay)
            ?->start_time;
    }
}
