<?php

namespace App\Services\Earnings;

use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Support\PayoutWindow;
use Illuminate\Support\Collection;

/**
 * A full payslip for one instructor over one payout window.
 */
final class EarningsSummary
{
    /**
     * @param  Collection<int, EarningsLine>  $lines
     */
    public function __construct(
        public readonly PayoutWindow $window,
        public readonly Collection $lines,
    ) {}

    /**
     * @return Collection<int, EarningsLine>
     *
     * Closures rather than the `->filter->method()` higher-order proxy, because
     * isDeduction is a readonly property on EarningsLine, not a method.
     */
    public function paidLines(): Collection
    {
        return $this->lines->filter(fn (EarningsLine $line) => ! $line->isDeduction);
    }

    /** @return Collection<int, EarningsLine> */
    public function deductionLines(): Collection
    {
        return $this->lines->filter(fn (EarningsLine $line) => $line->isDeduction);
    }

    public function gross(): float
    {
        return round($this->paidLines()->sum('amount'), 2);
    }

    public function deductions(): float
    {
        return round($this->deductionLines()->sum('amount'), 2);
    }

    public function net(): float
    {
        return round($this->gross() - $this->deductions(), 2);
    }

    public function sessionsPaid(): int
    {
        return $this->paidLines()->count();
    }

    public function sessionsDeducted(): int
    {
        return $this->deductionLines()->count();
    }

    /**
     * Gross earnings per teaching method, keyed by enum value.
     *
     * @return array<string, float>
     */
    public function grossByMethod(): array
    {
        $totals = [];

        foreach (TeachingMethod::cases() as $method) {
            $totals[$method->value] = round(
                $this->paidLines()
                    ->filter(fn (EarningsLine $line) => $line->teachingMethod === $method)
                    ->sum('amount'),
                2
            );
        }

        return $totals;
    }

    /**
     * Session counts per teaching method.
     *
     * @return array<string, int>
     */
    public function sessionsByMethod(): array
    {
        $counts = [];

        foreach (TeachingMethod::cases() as $method) {
            $counts[$method->value] = $this->paidLines()
                ->filter(fn (EarningsLine $line) => $line->teachingMethod === $method)
                ->count();
        }

        return $counts;
    }

    /** Audio vs video split, as the legacy earnings page presented it. */
    public function audioSessions(): int
    {
        return $this->paidLines()
            ->filter(fn (EarningsLine $line) => $line->teachingMethod === TeachingMethod::Audio)
            ->count();
    }

    public function videoSessions(): int
    {
        return $this->paidLines()
            ->filter(fn (EarningsLine $line) => $line->teachingMethod?->isVideo() === true)
            ->count();
    }

    /**
     * Distinct students taught in this window.
     */
    public function studentCount(): int
    {
        return $this->lines->pluck('studentId')->unique()->count();
    }

    /**
     * Per-student roll-up for the payslip's summary table.
     *
     * @return Collection<int, array{student_id: int, student_name: string, teaching_method: ?TeachingMethod, learning_time: int, present: int, absent: int, amount: float}>
     */
    public function byStudent(): Collection
    {
        return $this->lines
            ->groupBy('studentId')
            ->map(function (Collection $lines) {
                /** @var EarningsLine $first */
                $first = $lines->first();

                return [
                    'student_id' => $first->studentId,
                    'student_name' => $first->studentName,
                    'teaching_method' => $first->teachingMethod,
                    'learning_time' => $first->learningTime,
                    'present' => $lines
                        ->filter(fn (EarningsLine $l) => $l->status === SessionStatus::Present)
                        ->count(),
                    'absent' => $lines
                        ->filter(fn (EarningsLine $l) => $l->status === SessionStatus::Absent)
                        ->count(),
                    'amount' => round($lines->sum(fn (EarningsLine $l) => $l->signedAmount()), 2),
                ];
            })
            ->sortBy('student_name')
            ->values();
    }

    /**
     * Lines that were paid only because they predate the report requirement.
     * Surfaced so the report can flag them rather than hiding the exception.
     *
     * @return Collection<int, EarningsLine>
     */
    public function historicalLines(): Collection
    {
        return $this->lines->filter(fn (EarningsLine $line) => $line->isHistorical);
    }

    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }
}
