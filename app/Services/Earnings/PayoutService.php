<?php

namespace App\Services\Earnings;

use App\Enums\PayoutStatus;
use App\Enums\TeachingMethod;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PayoutAdjustment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Freezes a week's computed earnings into a Payout row.
 *
 * Until an admin finalises a week, the instructor earnings page recomputes from
 * attendance on every view — which means editing an old attendance record
 * silently restates what was already paid. Writing the figures down at the
 * moment of payment is what stops that.
 *
 * The legacy `payouts` table existed but nothing ever inserted into it, and it
 * had no column for deductions even though teacher-absent sessions were being
 * subtracted on screen.
 */
class PayoutService
{
    public function __construct(private readonly EarningsCalculator $earnings) {}

    /**
     * Create or refresh the payslip for one instructor and week.
     *
     * A payout already marked paid is never silently overwritten — that is the
     * whole point of freezing it. Pass $force to restate one deliberately.
     */
    public function finalise(
        User $admin,
        User $instructor,
        PayoutWindow $window,
        bool $force = false,
    ): Payout {
        return DB::transaction(function () use ($admin, $instructor, $window, $force) {
            $existing = Payout::query()
                ->where('instructor_id', $instructor->id)
                ->where('week_start', $window->startDate())
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === PayoutStatus::Paid && ! $force) {
                throw ValidationException::withMessages([
                    'payout' => __('That week is already marked paid. Restating it requires an explicit override.'),
                ]);
            }

            $summary = $this->earnings->forWindow($instructor->id, $window);
            $byMethod = $summary->grossByMethod();

            $attributes = [
                'gross_earnings' => $summary->gross(),
                'deductions' => $summary->deductions(),
                'audio_earnings' => $byMethod[TeachingMethod::Audio->value] ?? 0,
                'video_kids_earnings' => $byMethod[TeachingMethod::VideoKids->value] ?? 0,
                'video_adults_earnings' => $byMethod[TeachingMethod::VideoAdults->value] ?? 0,
                'sessions_paid' => $summary->sessionsPaid(),
                'sessions_deducted' => $summary->sessionsDeducted(),
                'week_end' => $window->endDate(),
            ];

            $payout = Payout::updateOrCreate(
                [
                    'instructor_id' => $instructor->id,
                    'week_start' => $window->startDate(),
                ],
                $attributes,
            );

            AuditLog::record(
                action: $existing ? 'payout.restated' : 'payout.finalised',
                subject: $payout,
                targetName: $instructor->name,
                details: [
                    'week' => $window->label('en'),
                    'gross' => $summary->gross(),
                    'deductions' => $summary->deductions(),
                    'net' => $summary->net(),
                ],
                userId: $admin->id,
            );

            return $payout->refresh();
        });
    }

    /**
     * Mark a finalised payout as paid.
     */
    public function markPaid(User $admin, Payout $payout, ?string $notes = null): Payout
    {
        return DB::transaction(function () use ($admin, $payout, $notes) {
            if ($payout->status === PayoutStatus::Paid) {
                throw ValidationException::withMessages([
                    'payout' => __('That payout is already marked paid.'),
                ]);
            }

            $payout->update([
                'status' => PayoutStatus::Paid,
                'paid_at' => now(),
                'paid_by' => $admin->id,
                'notes' => $notes ?? $payout->notes,
            ]);

            AuditLog::record(
                action: 'payout.paid',
                subject: $payout,
                targetName: $payout->instructor?->name,
                details: ['net' => (float) $payout->net_earnings],
                userId: $admin->id,
            );

            return $payout->refresh();
        });
    }

    /**
     * Credit an instructor one paid class for a student without touching the
     * student's sessions. Priced from the student's current profile, exactly
     * as a settled session would be, and frozen at that amount.
     *
     * Refused once the week it falls in is marked paid: the money has left.
     */
    public function addAdjustment(
        User $admin,
        User $instructor,
        User $student,
        CarbonImmutable $paidDate,
        string $note,
    ): PayoutAdjustment {
        return DB::transaction(function () use ($admin, $instructor, $student, $paidDate, $note) {
            $this->guardNotPaid($instructor, PayoutWindow::forDate($paidDate));

            $profile = StudentProfile::query()->where('user_id', $student->id)->first();

            if ($profile === null) {
                throw ValidationException::withMessages([
                    'student_id' => __('That student has no profile to price the class from.'),
                ]);
            }

            $adjustment = PayoutAdjustment::create([
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'student_name' => $student->name,
                'paid_date' => $paidDate->toDateString(),
                'teaching_method' => $profile->teaching_method,
                'learning_time' => (int) $profile->learning_time,
                'amount' => $this->earnings->amountFor($profile->teaching_method, (int) $profile->learning_time),
                'note' => $note,
                'created_by' => $admin->id,
            ]);

            AuditLog::record(
                action: 'payout.adjusted',
                subject: $adjustment,
                targetName: $instructor->name,
                details: [
                    'student' => $student->name,
                    'paid_date' => $paidDate->toDateString(),
                    'amount' => (float) $adjustment->amount,
                    'note' => $note,
                ],
                userId: $admin->id,
            );

            return $adjustment;
        });
    }

    /**
     * Withdraw a manual adjustment. Refused once its week is marked paid.
     */
    public function removeAdjustment(User $admin, PayoutAdjustment $adjustment): void
    {
        DB::transaction(function () use ($admin, $adjustment) {
            $this->guardNotPaid($adjustment->instructor, PayoutWindow::forDate($adjustment->paid_date));

            AuditLog::record(
                action: 'payout.adjustment_removed',
                subject: $adjustment,
                targetName: $adjustment->instructor?->name,
                details: [
                    'student' => $adjustment->student_name,
                    'paid_date' => $adjustment->paid_date->toDateString(),
                    'amount' => (float) $adjustment->amount,
                ],
                userId: $admin->id,
            );

            $adjustment->delete();
        });
    }

    protected function guardNotPaid(User $instructor, PayoutWindow $window): void
    {
        $paid = Payout::query()
            ->where('instructor_id', $instructor->id)
            ->whereDate('week_start', $window->startDate())
            ->paid()
            ->exists();

        if ($paid) {
            throw ValidationException::withMessages([
                'paid_date' => __('That week is already marked paid and cannot be adjusted.'),
            ]);
        }
    }

    /**
     * Finalise every instructor who earned something in the window.
     *
     * @return array{finalised: int, skipped: int}
     */
    public function finaliseWeek(User $admin, PayoutWindow $window): array
    {
        $instructors = User::query()->instructors()->orderBy('name')->get();

        $finalised = 0;
        $skipped = 0;

        foreach ($instructors as $instructor) {
            $summary = $this->earnings->forWindow($instructor->id, $window);

            // Nothing earned and nothing deducted: no payslip to write.
            if ($summary->isEmpty()) {
                $skipped++;

                continue;
            }

            try {
                $this->finalise($admin, $instructor, $window);
                $finalised++;
            } catch (ValidationException) {
                // Already paid — leave it alone rather than restating it.
                $skipped++;
            }
        }

        return ['finalised' => $finalised, 'skipped' => $skipped];
    }
}
