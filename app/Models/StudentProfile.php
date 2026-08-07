<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\TeachingMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'instructor_id',
        'teaching_method',
        'learning_time',
        'sessions_remaining',
        'sessions_attended',
        'sessions_deducted',
        'is_regular',
        'enrollment_status',
        'enrollment_decided_at',
        'enrollment_decided_by',
        'rejection_reason',
        'start_date',
        'end_date',
        'kakaotalk_id',
    ];

    protected $casts = [
        'teaching_method' => TeachingMethod::class,
        'enrollment_status' => EnrollmentStatus::class,
        'is_regular' => 'boolean',
        'learning_time' => 'integer',
        'sessions_remaining' => 'integer',
        'sessions_attended' => 'integer',
        'sessions_deducted' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'enrollment_decided_at' => 'datetime',
    ];

    // ---------------------------------------------------------------- relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /** The admin who approved or rejected this enrolment. */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrollment_decided_by');
    }

    // ------------------------------------------------------------------- scopes

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('enrollment_status', EnrollmentStatus::Approved);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('enrollment_status', EnrollmentStatus::Pending);
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    /**
     * Only students who should appear in an instructor's working lists:
     * approved, active and not soft-deleted.
     */
    public function scopeTeachable(Builder $query): Builder
    {
        return $query->approved()->whereHas('user', fn (Builder $q) => $q->where('is_active', true));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The pay rate that applies to one of this student's classes.
     *
     * A blank teaching method falls back to the audio rate, matching the legacy
     * behaviour for the rows where the field was never filled in.
     */
    public function sessionValue(): float
    {
        $rate = ($this->teaching_method ?? TeachingMethod::Audio)->hourlyRate();

        return round(($this->learning_time ?? 0) / 60 * $rate, 2);
    }

    /**
     * Total prepaid sessions on the student's plan.
     *
     * The identity the feedback page renders:
     *
     *   purchased = attended + student-absent + remaining + deducted
     *
     * A student-absent class consumes a prepaid session but counts as neither
     * attended nor remaining, so it has to be added back in explicitly.
     */
    public function sessionsPurchased(?int $studentAbsentCount = null): int
    {
        $studentAbsent = $studentAbsentCount ?? ClassSession::query()
            ->where('student_id', $this->user_id)
            ->absentBy(Party::Student)
            ->count();

        return $this->sessions_attended
            + $studentAbsent
            + $this->sessions_remaining
            + $this->sessions_deducted;
    }

    public function hasSessionsRemaining(): bool
    {
        return $this->sessions_remaining > 0;
    }
}
