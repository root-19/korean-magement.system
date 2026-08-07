<?php

namespace App\Services\Earnings;

use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use Carbon\CarbonImmutable;

/**
 * One settled session as it appears on a payslip.
 */
final class EarningsLine
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $studentId,
        public readonly string $studentName,
        public readonly ?TeachingMethod $teachingMethod,
        public readonly int $learningTime,
        public readonly SessionStatus $status,
        /** Party responsible, when the session was an absence. */
        public readonly ?string $absentBy,
        /** The date this line is paid for — held date for an early class. */
        public readonly CarbonImmutable $paidDate,
        /** The timetable slot; differs from paidDate only for an early class. */
        public readonly CarbonImmutable $scheduledDate,
        public readonly float $amount,
        /** True when this line is subtracted rather than added. */
        public readonly bool $isDeduction,
        /** Whether a report has been filed for this session. */
        public readonly bool $hasReport,
        /** Paid without a report because it predates the requirement. */
        public readonly bool $isHistorical,
    ) {}

    public function isEarly(): bool
    {
        return ! $this->paidDate->isSameDay($this->scheduledDate);
    }

    /** "Audio Class", "Video (Kids) Class (Early)" */
    public function description(): string
    {
        $label = $this->teachingMethod?->label() ?? 'Unspecified';

        return $label.' Class'.($this->isEarly() ? ' (Early)' : '');
    }

    public function statusLabel(): string
    {
        if ($this->status === SessionStatus::Present) {
            return 'Present';
        }

        return match ($this->absentBy) {
            'student' => 'Student absent',
            'teacher' => 'Teacher absent',
            default => 'Absent',
        };
    }

    public function statusBadgeClass(): string
    {
        if ($this->status === SessionStatus::Present) {
            return 'badge-success';
        }

        return $this->isDeduction ? 'badge-danger' : 'badge-warning';
    }

    /** Signed amount: deductions are negative. */
    public function signedAmount(): float
    {
        return $this->isDeduction ? -$this->amount : $this->amount;
    }
}
