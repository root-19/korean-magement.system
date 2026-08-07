<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\TeachingMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trial-class request from the public instructor profile page. The prospect
 * has no account until an instructor confirms and enrols them.
 */
class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'student_name',
        'kakaotalk_id',
        'email',
        'phone',
        'session_date',
        'session_time',
        'sessions',
        'teaching_method',
        'learning_time',
        'requested_schedule',
        'start_date',
        'status',
        'notes',
        'converted_student_id',
    ];

    protected $casts = [
        'session_date' => 'date',
        'start_date' => 'date',
        'requested_schedule' => 'array',
        'teaching_method' => TeachingMethod::class,
        'status' => BookingStatus::class,
        'sessions' => 'integer',
        'learning_time' => 'integer',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_student_id');
    }

    public function scopeStatus(Builder $query, BookingStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('session_date', '>=', now()->toDateString());
    }

    public function isConverted(): bool
    {
        return $this->converted_student_id !== null;
    }
}
