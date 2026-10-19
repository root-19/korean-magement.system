<?php

namespace App\Services\Attendance;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\User;
use App\Support\PayoutWindow;
use Illuminate\Support\Collection;

/**
 * Attendance and teaching minutes for one cut-off, per teacher and per day.
 *
 * A cut-off is the payout week (PayoutWindow), and classes are placed on their
 * PAID date, so a class taught early lands in the week the payslip puts it in.
 * Only marked classes count: Present, Absent or Postponed.
 *
 *   total minutes = every marked class × the student's class length
 *   minutes used  = Present classes × the student's class length
 *
 * The class length is the student's current `learning_time`, the same figure
 * EarningsCalculator pays on.
 */
class CutoffAudit
{
    /**
     * @return array{
     *     teachers: Collection<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     daily: array<int, array<string, mixed>>,
     * }
     */
    public function for(PayoutWindow $window): array
    {
        $groups = ClassSession::query()
            ->leftJoin('student_profiles as sp', 'sp.user_id', '=', 'class_sessions.student_id')
            ->whereBetween('class_sessions.paid_date', [$window->startDate(), $window->endDate()])
            ->whereNotNull('class_sessions.status')
            ->groupBy('class_sessions.instructor_id', 'class_sessions.paid_date', 'class_sessions.status', 'class_sessions.absent_by')
            ->selectRaw('class_sessions.instructor_id, class_sessions.paid_date, class_sessions.status, class_sessions.absent_by')
            ->selectRaw('COUNT(*) as classes, SUM(COALESCE(sp.learning_time, 0)) as minutes')
            ->toBase()
            ->get();

        $teachers = $this->byTeacher($groups);

        return [
            'teachers' => $teachers,
            'totals' => $this->totals($teachers),
            'daily' => $this->byDay($groups, $window),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function byTeacher(Collection $groups): Collection
    {
        // withTrashed: a teacher removed since still taught these classes.
        $names = User::withTrashed()
            ->whereIn('id', $groups->pluck('instructor_id')->unique())
            ->pluck('name', 'id');

        return $groups
            ->groupBy('instructor_id')
            ->map(fn (Collection $rows, int $instructorId) => array_merge(
                ['instructor_id' => $instructorId, 'name' => $names->get($instructorId, '(removed)')],
                $this->tally($rows),
            ))
            ->sortByDesc('total_minutes')
            ->values();
    }

    /**
     * @return array<string, int>
     */
    private function totals(Collection $teachers): array
    {
        return collect(['present', 'absent', 'student_absent', 'teacher_absent', 'postponed', 'total_minutes', 'used_minutes'])
            ->mapWithKeys(fn (string $key) => [$key => (int) $teachers->sum($key)])
            ->all();
    }

    /**
     * One entry per day of the cut-off, days without classes included, so the
     * chart's x-axis is the whole week.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byDay(Collection $groups, PayoutWindow $window): array
    {
        $byDate = $groups->groupBy(fn (object $row) => substr((string) $row->paid_date, 0, 10));
        $days = [];

        for ($day = $window->start; $day->lte($window->end); $day = $day->addDay()) {
            $tally = $this->tally($byDate->get($day->toDateString(), collect()));

            $days[] = [
                'date' => $day,
                'present' => $tally['present'],
                'absent' => $tally['absent'],
                'postponed' => $tally['postponed'],
            ];
        }

        return $days;
    }

    /**
     * @return array<string, int>
     */
    private function tally(Collection $rows): array
    {
        $count = fn (SessionStatus $status, ?Party $by = null) => (int) $rows
            ->filter(fn (object $row) => $row->status === $status->value
                && ($by === null || $row->absent_by === $by->value))
            ->sum('classes');

        return [
            'present' => $count(SessionStatus::Present),
            'absent' => $count(SessionStatus::Absent),
            'student_absent' => $count(SessionStatus::Absent, Party::Student),
            'teacher_absent' => $count(SessionStatus::Absent, Party::Teacher),
            'postponed' => $count(SessionStatus::Postponed),
            'total_minutes' => (int) $rows->sum('minutes'),
            'used_minutes' => (int) $rows->where('status', SessionStatus::Present->value)->sum('minutes'),
        ];
    }
}
