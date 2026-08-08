<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission to mark attendance on a class that has already passed.
 *
 * The rule this exists to enforce: an instructor may mark today's classes
 * freely, but a class from any earlier day is closed. Reopening it is an admin
 * decision, because marking a session is what releases its payment.
 */
class AttendanceRequest extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'instructor_id',
        'student_id',
        'class_date',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'class_date' => 'date',
        'decided_at' => 'datetime',
    ];

    // ---------------------------------------------------------------- relations

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // ------------------------------------------------------------------- scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    // ------------------------------------------------------------------ helpers

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    /**
     * Whether attendance for this class may be recorded directly.
     *
     * Today is open to everyone. A past date needs an approved request for that
     * exact class. A future date is never markable — attendance belongs to the
     * day it happened, and the early-class flow covers teaching ahead.
     */
    public static function classIsOpen(mixed $date, ?self $request = null): bool
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $today = CarbonImmutable::today();

        if ($day->equalTo($today)) {
            return true;
        }

        if ($day->greaterThan($today)) {
            return false;
        }

        return $request?->isApproved() ?? false;
    }
}
