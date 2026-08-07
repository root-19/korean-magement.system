<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Postponed = 'postponed';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Postponed => 'Postponed',
        };
    }

    /** Tailwind badge class for this status — see resources/css/app.css. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Present => 'badge-success',
            self::Absent => 'badge-danger',
            self::Postponed => 'badge-warning',
        };
    }

    /**
     * Statuses that represent a settled session and can therefore appear on a
     * payslip. A postponed class was never taught, and NULL means not yet
     * marked — the legacy queries expressed this as
     * `status IN ('present','absent')`.
     *
     * @return array<int, string>
     */
    public static function payableValues(): array
    {
        return [self::Present->value, self::Absent->value];
    }
}
