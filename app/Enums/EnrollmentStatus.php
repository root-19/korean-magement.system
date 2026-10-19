<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting approval'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-warning',
            self::Approved => 'badge-success',
            self::Rejected => 'badge-danger',
        };
    }
}
