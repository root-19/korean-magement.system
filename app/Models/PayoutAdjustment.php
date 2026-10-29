<?php

namespace App\Models;

use App\Enums\TeachingMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid class credited by an admin without touching the student's sessions.
 */
class PayoutAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'student_id',
        'student_name',
        'paid_date',
        'teaching_method',
        'learning_time',
        'amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'paid_date' => 'date',
        'teaching_method' => TeachingMethod::class,
        'learning_time' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
