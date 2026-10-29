<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePayoutAdjustmentRequest;
use App\Models\ClassSession;
use App\Models\Payout;
use App\Models\PayoutAdjustment;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Services\Earnings\PayoutService;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Weekly payroll. Replaces admin/instractor_earn.php.
 *
 * Earnings are computed live from attendance until an admin finalises the week,
 * which freezes the figures into a `payouts` row. The legacy app only ever
 * displayed the live figure — nothing was written down, so editing an old
 * attendance record silently restated what had already been paid.
 */
class PayoutController extends Controller
{
    public function __construct(
        private readonly EarningsCalculator $earnings,
        private readonly PayoutService $payouts,
    ) {}

    public function index(Request $request): View
    {
        $window = $request->filled('week')
            ? PayoutWindow::forDate($request->query('week'))
            : PayoutWindow::current();

        // Only instructors with a settled session in the window; computing a
        // payslip for all 33 to render a handful of rows would be wasteful.
        $activeIds = ClassSession::query()
            ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
            ->whereNotNull('status')
            ->distinct()
            ->pluck('instructor_id')
            ->merge(
                PayoutAdjustment::query()
                    ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
                    ->distinct()
                    ->pluck('instructor_id')
            )
            ->unique();

        $frozen = Payout::query()
            ->where('week_start', $window->startDate())
            ->get()
            ->keyBy('instructor_id');

        $rows = User::query()
            ->instructors()
            ->whereIn('id', $activeIds)
            ->orderBy('name')
            ->get()
            ->map(function (User $instructor) use ($window, $frozen) {
                $summary = $this->earnings->forWindow($instructor->id, $window);

                return [
                    'instructor' => $instructor,
                    'summary' => $summary,
                    'payout' => $frozen->get($instructor->id),
                ];
            })
            // An instructor whose only sessions were unreported earns nothing;
            // keep them visible so the missing report is obvious.
            ->sortByDesc(fn (array $row) => $row['summary']->net())
            ->values();

        return view('admin.payouts.index', [
            'window' => $window,
            'windows' => PayoutWindow::current()->recent(12),
            'rows' => $rows,
            'totals' => [
                'gross' => round($rows->sum(fn ($r) => $r['summary']->gross()), 2),
                'deductions' => round($rows->sum(fn ($r) => $r['summary']->deductions()), 2),
                'net' => round($rows->sum(fn ($r) => $r['summary']->net()), 2),
                'sessions' => $rows->sum(fn ($r) => $r['summary']->sessionsPaid()),
            ],
        ]);
    }

    /**
     * Freeze one instructor's week.
     */
    public function finalise(Request $request, User $instructor): RedirectResponse
    {
        $data = $request->validate([
            'week' => ['required', 'date'],
            'force' => ['nullable', 'boolean'],
        ]);

        $payout = $this->payouts->finalise(
            admin: $request->user(),
            instructor: $instructor,
            window: PayoutWindow::forDate($data['week']),
            force: (bool) ($data['force'] ?? false),
        );

        return back()->with(
            'success',
            __(':name: :week finalised at :amount.', [
                'name' => $instructor->name,
                'week' => $payout->weekLabel(),
                'amount' => money($payout->net_earnings),
            ])
        );
    }

    /**
     * Freeze every earning instructor for the week in one go.
     */
    public function finaliseWeek(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'week' => ['required', 'date'],
        ]);

        $window = PayoutWindow::forDate($data['week']);
        $result = $this->payouts->finaliseWeek($request->user(), $window);

        return back()->with('success', trans_choice(
            ':week: :count payslip finalised, :skipped skipped.|:week: :count payslips finalised, :skipped skipped.',
            $result['finalised'],
            [
                'week' => $window->label(),
                'skipped' => $result['skipped'],
            ],
        ));
    }

    /**
     * Form to credit one paid class by hand, for a session that is missing or
     * must not be altered.
     */
    public function createAdjustment(Request $request, User $instructor): View
    {
        $window = $request->filled('week')
            ? PayoutWindow::forDate($request->query('week'))
            : PayoutWindow::current();

        return view('admin.payouts.adjustment', [
            'instructor' => $instructor,
            'window' => $window,
            'students' => $instructor->students()
                ->with('user')
                ->get()
                ->filter(fn ($profile) => $profile->user !== null)
                ->sortBy(fn ($profile) => $profile->user->name)
                ->values(),
            'adjustments' => PayoutAdjustment::query()
                ->where('instructor_id', $instructor->id)
                ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
                ->orderByDesc('paid_date')
                ->get(),
        ]);
    }

    public function storeAdjustment(StorePayoutAdjustmentRequest $request, User $instructor): RedirectResponse
    {
        $data = $request->validated();
        $paidDate = CarbonImmutable::parse($data['paid_date']);

        $adjustment = $this->payouts->addAdjustment(
            admin: $request->user(),
            instructor: $instructor,
            student: User::withTrashed()->findOrFail($data['student_id']),
            paidDate: $paidDate,
            note: $data['note'],
        );

        return redirect()
            ->route('admin.payouts.index', ['week' => PayoutWindow::forDate($paidDate)->startDate()])
            ->with('success', __(':name: 1 paid class added for :student (:amount).', [
                'name' => $instructor->name,
                'student' => $adjustment->student_name,
                'amount' => money($adjustment->amount),
            ]));
    }

    public function destroyAdjustment(Request $request, PayoutAdjustment $adjustment): RedirectResponse
    {
        $this->payouts->removeAdjustment($request->user(), $adjustment);

        return back()->with('success', __('Manual adjustment removed.'));
    }

    /**
     * Mark a frozen payslip as paid.
     */
    public function markPaid(Request $request, Payout $payout): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->payouts->markPaid($request->user(), $payout, $data['notes'] ?? null);

        return back()->with('success', __('Marked paid: :name, :week.', [
            'name' => $payout->instructor?->name,
            'week' => $payout->weekLabel(),
        ]));
    }
}
