<?php

namespace App\Enums;

/**
 * Who is responsible for an absence or a postponement.
 *
 * This distinction is money: a student-absent class still pays the instructor
 * (they showed up and waited), while a teacher-absent class is deducted from
 * their payout.
 */
enum Party: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Teacher => 'Teacher',
            self::Other => 'Other',
        };
    }
}
