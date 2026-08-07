<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Payout;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Services\Earnings\PayoutService;
use App\Support\PayoutWindow;
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
            ->pluck('instructor_id');

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
            "{$instructor->name}: ".$payout->weekLabel().' finalised at '.money($payout->net_earnings).'.'
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

        return back()->with('success', sprintf(
            '%s: %d payslip%s finalised, %d skipped.',
            $window->label(),
            $result['finalised'],
            $result['finalised'] === 1 ? '' : 's',
            $result['skipped'],
        ));
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

        return back()->with('success', "Marked paid: {$payout->instructor?->name}, {$payout->weekLabel()}.");
    }
}
