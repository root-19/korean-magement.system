<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Models\AuditLog;
use App\Services\Attendance\UnmarkedClasses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The evaluation queue: instructors asking to mark a class that has passed.
 *
 * Approving one reopens exactly that class — one student, one date — because
 * marking it releases its payment.
 */
class AttendanceRequestController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('status', 'pending');

        $requests = AttendanceRequest::query()
            ->with(['instructor:id,name', 'student:id,name', 'decidedBy:id,name'])
            ->when(
                in_array($filter, [AttendanceRequest::PENDING, AttendanceRequest::APPROVED, AttendanceRequest::REJECTED], true),
                fn ($q) => $q->where('status', $filter),
            )
            // Oldest pending first: someone is waiting on it to get paid.
            ->orderByRaw("FIELD(status, 'pending') DESC")
            ->orderBy('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.evaluations.index', [
            'requests' => $requests,
            'filter' => $filter,
            'pendingCount' => AttendanceRequest::query()->pending()->count(),
        ]);
    }

    /**
     * Teachers with past classes still showing "For evaluation" on their roster —
     * unmarked, and not yet asked about or asked and rejected.
     */
    public function teachers(UnmarkedClasses $unmarkedClasses): View
    {
        return view('admin.evaluations.teachers', [
            'teachers' => $unmarkedClasses->awaitingEvaluation(),
            'lookbackDays' => UnmarkedClasses::LOOKBACK_DAYS,
        ]);
    }

    public function decide(Request $request, AttendanceRequest $evaluation): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $evaluation->update([
            'status' => $data['decision'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $data['decision_note'] ?? null,
        ]);

        AuditLog::record(
            action: 'attendance_request.'.$data['decision'],
            subject: $evaluation,
            targetName: $evaluation->student?->name,
            details: [
                'class_date' => $evaluation->class_date->toDateString(),
                'instructor' => $evaluation->instructor?->name,
            ],
            userId: $request->user()->id,
        );

        $replace = [
            'date' => $evaluation->class_date->translatedFormat('M j'),
            'student' => $evaluation->student?->name ?? __('that student'),
        ];

        return back()->with('success', $data['decision'] === AttendanceRequest::APPROVED
            ? __('Approved the :date class for :student.', $replace)
            : __('Rejected the :date class for :student.', $replace));
    }
}
