<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\CutoffAudit;
use App\Support\PayoutWindow;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per cut-off audit: each teacher's minutes and Present / Absent / Postponed
 * counts for one payout week, with the week charted day by day.
 */
class AttendanceAuditController extends Controller
{
    public function __invoke(Request $request, CutoffAudit $audit): View
    {
        $data = $request->validate([
            'week' => ['nullable', 'date'],
        ]);

        $window = isset($data['week']) ? PayoutWindow::forDate($data['week']) : PayoutWindow::current();

        return view('admin.attendance-audit.index', [
            'window' => $window,
            'windows' => PayoutWindow::current()->recent(12),
            ...$audit->for($window),
        ]);
    }
}
