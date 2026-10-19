<?php

namespace App\Services\Attendance;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
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
 * EarningsCalculator pays on. Minutes are also split by class type — Audio,
 * Video (Kids), Video (Adults) — from the student's `teaching_method`; a blank
 * one counts as Audio, the rate payroll bills it at.
 *
 * Earnings come from EarningsCalculator, not from the counts here: a class pays
 * only once its report is filed, trials and teacher absences have their own
 * rules, and the Payroll page shows the same live figure.
 */
class CutoffAudit
{
    public function __construct(private readonly EarningsCalculator $earnings) {}

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
            ->groupBy('class_sessions.instructor_id', 'class_sessions.paid_date', 'class_sessions.status', 'class_sessions.absent_by', 'sp.teaching_method')
            ->selectRaw('class_sessions.instructor_id, class_sessions.paid_date, class_sessions.status, class_sessions.absent_by, sp.teaching_method')
            ->selectRaw('COUNT(*) as classes, SUM(COALESCE(sp.learning_time, 0)) as minutes')
            ->toBase()
            ->get();

        $teachers = $this->byTeacher($groups, $window);

        return [
            'teachers' => $teachers,
            'totals' => $this->totals($teachers),
            'daily' => $this->byDay($groups, $window),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function byTeacher(Collection $groups, PayoutWindow $window): Collection
    {
        // withTrashed: a teacher removed since still taught these classes.
        $names = User::withTrashed()
            ->whereIn('id', $groups->pluck('instructor_id')->unique())
            ->pluck('name', 'id');

        return $groups
            ->groupBy('instructor_id')
            ->map(function (Collection $rows, int $instructorId) use ($names, $window) {
                $summary = $this->earnings->forWindow($instructorId, $window);

                return array_merge(
                    ['instructor_id' => $instructorId, 'name' => $names->get($instructorId, '(removed)')],
                    $this->tally($rows),
                    ['gross' => $summary->gross(), 'deductions' => $summary->deductions(), 'earned' => $summary->net()],
                );
            })
            ->sortByDesc('total_minutes')
            ->values();
    }

    /**
     * @return array<string, int|float>
     */
    private function totals(Collection $teachers): array
    {
        $counts = collect(['present', 'absent', 'student_absent', 'teacher_absent', 'postponed', 'total_minutes', 'used_minutes'])
            ->mapWithKeys(fn (string $key) => [$key => (int) $teachers->sum($key)]);

        $money = collect(['gross', 'deductions', 'earned'])
            ->mapWithKeys(fn (string $key) => [$key => round((float) $teachers->sum($key), 2)]);

        $byMethod = collect(TeachingMethod::cases())->mapWithKeys(fn (TeachingMethod $method) => [
            $method->value => [
                'total' => (int) $teachers->sum("by_method.{$method->value}.total"),
                'used' => (int) $teachers->sum("by_method.{$method->value}.used"),
            ],
        ]);

        return $counts->merge($money)->put('by_method', $byMethod->all())->all();
    }

    /**
     * One entry per day of the cut-off, so the chart's x-axis is the whole week.
     *
     * A day that has not come yet is null, not zero: nothing can have been marked
     * on it, and plotting it as 0 drew every line down to the floor mid-week.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byDay(Collection $groups, PayoutWindow $window): array
    {
        $byDate = $groups->groupBy(fn (object $row) => substr((string) $row->paid_date, 0, 10));
        $today = CarbonImmutable::today();
        $days = [];

        for ($day = $window->start; $day->lte($window->end); $day = $day->addDay()) {
            $tally = $this->tally($byDate->get($day->toDateString(), collect()));
            $upcoming = $day->gt($today);

            $days[] = [
                'date' => $day,
                'present' => $upcoming ? null : $tally['present'],
                'absent' => $upcoming ? null : $tally['absent'],
                'postponed' => $upcoming ? null : $tally['postponed'],
            ];
        }

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function tally(Collection $rows): array
    {
        $method = fn (object $row) => (TeachingMethod::tryFrom((string) $row->teaching_method) ?? TeachingMethod::Audio)->value;
        $present = fn (object $row) => $row->status === SessionStatus::Present->value;

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
            'used_minutes' => (int) $rows->filter($present)->sum('minutes'),
            'by_method' => collect(TeachingMethod::cases())->mapWithKeys(function (TeachingMethod $case) use ($rows, $method, $present) {
                $ofType = $rows->filter(fn (object $row) => $method($row) === $case->value);

                return [$case->value => [
                    'total' => (int) $ofType->sum('minutes'),
                    'used' => (int) $ofType->filter($present)->sum('minutes'),
                ]];
            })->all(),
        ];
    }
}
