<?php

namespace App\Services\Earnings;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Models\ClassSession;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out what an instructor is owed for a payout window.
 *
 * THE RULES (unchanged from the legacy Earnings model — this is payroll, the
 * behaviour is deliberately preserved even where the old code was convoluted):
 *
 *   1. Only SETTLED sessions count: status present or absent. Postponed classes
 *      were never taught, and NULL means nobody has marked it yet.
 *
 *   2. A session is PAID when it is present, or absent through the student's
 *      fault — the instructor showed up and waited either way.
 *
 *   3. A session is DEDUCTED when it is absent through the instructor's fault.
 *
 *   4. A report must be filed before a session pays. Sessions taught strictly
 *      before `academy.feedback_required_from` predate the rule and pay
 *      unconditionally; instructors in `feedback_exempt_instructor_ids` bypass
 *      it entirely. Deductions are NOT gated on a report — an instructor cannot
 *      dodge a deduction by not filing.
 *
 *   5. Amount = hourly rate for the teaching method * learning_time / 60,
 *      rounded to 2dp. A blank teaching method bills at the audio rate.
 *
 *   6. Everything keys off `paid_date`, not `scheduled_date`, so a class taught
 *      early is paid in the week the work was done.
 *
 * WHAT IS GONE
 * ------------
 * The legacy query carried two pieces of machinery that the new schema retires:
 *
 *   * A derived table with GROUP BY + MAX(CASE ...) to collapse several
 *     attendance rows per student per day into one status. Redundant — the
 *     unique key on (instructor, student, scheduled_date) means one row per
 *     slot already, so there is nothing to collapse. A legitimate double class
 *     (regular plus an early one pulled forward onto the same day) still pays
 *     twice, because the two rows differ in scheduled_date.
 *
 *   * A NOT EXISTS subquery matching preserved usernames, to stop a
 *     hard-deleted student's negative-id row from double-counting against the
 *     re-enrolled row for the same class. Soft deletes remove the cause: the
 *     student's row survives a deletion, so there is never a second row for one
 *     class. The importer applies the old rule once, on the way in.
 */
class EarningsCalculator
{
    /**
     * Full payslip for one instructor over one window.
     */
    public function forWindow(int $instructorId, ?PayoutWindow $window = null): EarningsSummary
    {
        $window ??= PayoutWindow::current();

        $rows = $this->query($instructorId, $window->startDate(), $window->endDate())->get();

        $lines = $rows
            ->map(fn (object $row) => $this->toLine($row))
            ->filter()
            ->values();

        return new EarningsSummary($window, $lines);
    }

    /**
     * Net earnings for a date range, without building the line items.
     * Used by dashboard tiles where only the number is needed.
     */
    public function netBetween(int $instructorId, string $start, string $end): float
    {
        $summary = new EarningsSummary(
            PayoutWindow::forDate($start),
            $this->query($instructorId, $start, $end)->get()
                ->map(fn (object $row) => $this->toLine($row))
                ->filter()
                ->values()
        );

        return $summary->net();
    }

    /**
     * @return Collection<int, EarningsSummary> keyed by window key, newest first
     */
    public function recentWindows(int $instructorId, int $count = 8): Collection
    {
        return collect(PayoutWindow::current()->recent($count))
            ->mapWithKeys(fn (PayoutWindow $w) => [$w->key() => $this->forWindow($instructorId, $w)]);
    }

    // ---------------------------------------------------------------- internals

    /**
     * The one query. Returns every settled session in range that either pays or
     * deducts, annotated with whether a report exists for it.
     */
    protected function query(int $instructorId, string $start, string $end): Builder
    {
        $requiredFrom = $this->feedbackRequiredFrom();
        $isExempt = $this->isFeedbackExempt($instructorId);

        return ClassSession::query()
            ->select([
                'class_sessions.id',
                'class_sessions.student_id',
                'class_sessions.scheduled_date',
                'class_sessions.paid_date',
                'class_sessions.status',
                'class_sessions.absent_by',
                'students.name as student_name',
                'profiles.teaching_method',
                'profiles.learning_time',
            ])
            // A raw join, so it deliberately does NOT apply the User model's
            // soft-delete scope. Archiving a student must never erase the
            // instructor's record of having taught them — that is the whole
            // reason the legacy code needed snapshot columns and negative ids.
            // Here it falls out of keeping the row.
            //
            // join, not leftJoin: a session with no student row at all is
            // corrupt and must not silently price at the audio-rate default.
            ->join('users as students', 'students.id', '=', 'class_sessions.student_id')
            ->leftJoin('student_profiles as profiles', 'profiles.user_id', '=', 'class_sessions.student_id')

            ->where('class_sessions.instructor_id', $instructorId)
            ->whereIn('class_sessions.status', SessionStatus::payableValues())
            ->whereBetween('class_sessions.paid_date', [$start, $end])

            // Rules 2 and 3: keep only sessions that pay or deduct. An absence
            // marked 'other' (or with no party recorded) does neither.
            ->where(function ($q) {
                $q->where('class_sessions.status', SessionStatus::Present->value)
                    ->orWhere(function ($q) {
                        $q->where('class_sessions.status', SessionStatus::Absent->value)
                            ->whereIn('class_sessions.absent_by', [Party::Student->value, Party::Teacher->value]);
                    });
            })

            // Rule 4. Skipped wholesale for an exempt instructor: every settled
            // session of theirs pays whether or not a report exists.
            ->when(! $isExempt, fn ($query) => $query->where(function ($q) use ($requiredFrom) {
                // A teacher-absent row is a deduction, never gated on a report —
                // an instructor cannot dodge one by not filing.
                $q->where('class_sessions.absent_by', Party::Teacher->value)
                    // Predates the requirement.
                    ->orWhere('class_sessions.paid_date', '<', $requiredFrom)
                    // Report filed.
                    ->orWhereExists($this->reportExists());
            }))

            ->addSelect([
                'has_report' => DB::table('session_reports')
                    ->selectRaw('1')
                    ->whereColumn('session_reports.instructor_id', 'class_sessions.instructor_id')
                    ->whereColumn('session_reports.student_id', 'class_sessions.student_id')
                    ->whereColumn('session_reports.class_date', 'class_sessions.paid_date')
                    ->limit(1),
            ])

            ->orderByDesc('class_sessions.paid_date')
            ->orderBy('students.name');
    }

    /**
     * Correlated existence check for a filed report.
     *
     * Matched on the natural key (instructor, student, class_date = paid_date)
     * rather than on session_reports.class_session_id, because historical rows
     * imported from the legacy `feedback` table cannot always be resolved to a
     * session. The unique index on those three columns makes it an index lookup.
     */
    protected function reportExists(): \Closure
    {
        return function (QueryBuilder $q) {
            $q->select(DB::raw(1))
                ->from('session_reports')
                ->whereColumn('session_reports.instructor_id', 'class_sessions.instructor_id')
                ->whereColumn('session_reports.student_id', 'class_sessions.student_id')
                ->whereColumn('session_reports.class_date', 'class_sessions.paid_date');
        };
    }

    protected function toLine(object $row): ?EarningsLine
    {
        $status = $row->status instanceof SessionStatus
            ? $row->status
            : SessionStatus::tryFrom((string) $row->status);

        if ($status === null) {
            return null;
        }

        $absentBy = $row->absent_by instanceof Party
            ? $row->absent_by->value
            : ($row->absent_by !== null ? (string) $row->absent_by : null);

        $method = $row->teaching_method instanceof TeachingMethod
            ? $row->teaching_method
            : TeachingMethod::tryFrom((string) $row->teaching_method);

        $learningTime = (int) ($row->learning_time ?? 0);
        $paidDate = CarbonImmutable::parse($row->paid_date);

        return new EarningsLine(
            sessionId: (int) $row->id,
            studentId: (int) $row->student_id,
            studentName: (string) $row->student_name,
            teachingMethod: $method,
            learningTime: $learningTime,
            status: $status,
            absentBy: $absentBy,
            paidDate: $paidDate,
            scheduledDate: CarbonImmutable::parse($row->scheduled_date),
            amount: $this->amountFor($method, $learningTime),
            isDeduction: $status === SessionStatus::Absent && $absentBy === Party::Teacher->value,
            hasReport: (bool) ($row->has_report ?? false),
            isHistorical: $paidDate->toDateString() < $this->feedbackRequiredFrom(),
        );
    }

    /**
     * Rule 5. A NULL teaching method bills at the audio rate — the legacy rate
     * lookup fell through to audio for the rows where the field was blank, and
     * changing that would restate historical pay.
     */
    public function amountFor(?TeachingMethod $method, int $learningTimeMinutes): float
    {
        $rate = ($method ?? TeachingMethod::Audio)->hourlyRate();

        return round($learningTimeMinutes / 60 * $rate, 2);
    }

    protected function feedbackRequiredFrom(): string
    {
        return (string) config('academy.feedback_required_from', '2024-01-01');
    }

    protected function isFeedbackExempt(int $instructorId): bool
    {
        return in_array(
            $instructorId,
            (array) config('academy.feedback_exempt_instructor_ids', []),
            true
        );
    }
}
