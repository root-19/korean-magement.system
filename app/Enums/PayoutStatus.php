<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-warning',
            self::Paid => 'badge-success',
        };
    }
}
