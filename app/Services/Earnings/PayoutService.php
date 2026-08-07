<?php

namespace App\Services\Earnings;

use App\Enums\PayoutStatus;
use App\Enums\TeachingMethod;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\User;
use App\Support\PayoutWindow;
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
                    'payout' => 'That week is already marked paid. Restating it requires an explicit override.',
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
                    'week' => $window->label(),
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
                    'payout' => 'That payout is already marked paid.',
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
