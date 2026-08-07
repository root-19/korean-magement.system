<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Instructor = 'instructor';

    /** Legacy stored this as 'user'; the importer maps it across. */
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Instructor => 'Instructor',
            self::Student => 'Student',
        };
    }
}
