<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The instructor's payslip. Replaces app/views/instructor/earnings.php (998
 * lines) plus the ~100-line SQL statement behind it.
 */
class EarningsController extends Controller
{
    public function __construct(private readonly EarningsCalculator $earnings) {}

    public function index(Request $request): View
    {
        $instructor = $request->user();

        $window = $request->filled('week')
            ? PayoutWindow::forDate($request->query('week'))
            : PayoutWindow::current();

        $summary = $this->earnings->forWindow($instructor->id, $window);

        return view('instructor.earnings.index', [
            // Named on the printed payslip, where the app header is hidden.
            'instructor' => $instructor,
            'window' => $window,
            'summary' => $summary,
            // Selectable weeks for the period picker, newest first.
            'windows' => PayoutWindow::current()->recent(12),
            'payout' => $instructor->payouts()
                ->where('week_start', $window->startDate())
                ->first(),
        ]);
    }
}
