<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'birthday',
        'avatar_path',
        'is_active',
        'legacy_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'role' => Role::class,
        'birthday' => 'date',
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    // ---------------------------------------------------------------- relations

    public function instructorProfile(): HasOne
    {
        return $this->hasOne(InstructorProfile::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** Student profiles assigned to this instructor. */
    public function students(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'instructor_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StudentSchedule::class, 'student_id')->orderBy('day_of_week');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(InstructorAvailability::class, 'instructor_id');
    }

    /** Sessions this user taught. */
    public function taughtSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'instructor_id');
    }

    /** Sessions this user attended as a student. */
    public function attendedSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'student_id');
    }

    public function reportsWritten(): HasMany
    {
        return $this->hasMany(SessionReport::class, 'instructor_id');
    }

    public function reportsReceived(): HasMany
    {
        return $this->hasMany(SessionReport::class, 'student_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'instructor_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'instructor_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deleted_by');
    }

    // ------------------------------------------------------------------- scopes

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', Role::Admin);
    }

    public function scopeInstructors(Builder $query): Builder
    {
        return $query->where('role', Role::Instructor);
    }

    public function scopeStudents(Builder $query): Builder
    {
        return $query->where('role', Role::Student);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ------------------------------------------------------------------ helpers

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isInstructor(): bool
    {
        return $this->role === Role::Instructor;
    }

    public function isStudent(): bool
    {
        return $this->role === Role::Student;
    }

    /**
     * The enrolment code embedded in a student's name, if present.
     * Legacy usernames follow an "A540 Hyun Seo" pattern.
     */
    public function enrollmentCode(): ?string
    {
        return preg_match('/^([A-Za-z]\d+)\s/', $this->name, $m) ? $m[1] : null;
    }

    /**
     * Initials for the avatar fallback, skipping the enrolment code so
     * "A540 Hyun Seo" yields "HS" rather than "AH".
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        if (count($parts) > 1 && preg_match('/^[A-Za-z]\d+$/', $parts[0])) {
            array_shift($parts);
        }

        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr($this->name, 0, 1));
    }
}
