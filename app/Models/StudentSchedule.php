<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday slot in a student's weekly timetable.
 *
 * `day_of_week` is ISO-8601: 1 = Monday ... 7 = Sunday, matching
 * Carbon::dayOfWeekIso.
 */
class StudentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'day_of_week',
        'start_time',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    /** ISO day number => English name, as the legacy `schedule` string spelled it. */
    public const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function scopeOnDay(Builder $query, int $isoDay): Builder
    {
        return $query->where('day_of_week', $isoDay);
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day_of_week] ?? '—';
    }

    public function shortDayName(): string
    {
        return substr($this->dayName(), 0, 3);
    }

    /** "18:30:00" stored, "6:30 PM" shown. */
    public function formattedTime(): string
    {
        return $this->start_time
            ? date('g:i A', strtotime((string) $this->start_time))
            : '—';
    }

    /** "18:30:00" stored, "18:30" for an <input type="time"> value. */
    public function inputTime(): string
    {
        return $this->start_time
            ? date('H:i', strtotime((string) $this->start_time))
            : '';
    }

    /**
     * Map an English day name to its ISO number. Used by the legacy importer to
     * unpack the comma-joined `users.schedule` string.
     */
    public static function isoDayFromName(string $name): ?int
    {
        $needle = ucfirst(strtolower(trim($name)));

        return array_search($needle, self::DAYS, true) ?: null;
    }
}
