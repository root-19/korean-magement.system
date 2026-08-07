<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_available' => 'boolean',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    public function dayName(): string
    {
        return StudentSchedule::DAYS[$this->day_of_week] ?? '—';
    }

    public function formattedRange(): string
    {
        return sprintf(
            '%s – %s',
            date('g:i A', strtotime((string) $this->start_time)),
            date('g:i A', strtotime((string) $this->end_time))
        );
    }

    /**
     * Whether another slot on the same day would overlap this one.
     * Touching endpoints (10:00-11:00 and 11:00-12:00) do not overlap.
     */
    public function overlaps(string $startTime, string $endTime): bool
    {
        return $startTime < $this->end_time && $endTime > $this->start_time;
    }
}
