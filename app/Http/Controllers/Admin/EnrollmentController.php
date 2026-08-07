<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The approval queue for students an instructor enrolled.
 *
 * Replaces admin/pending_enrollments.php plus AdminController::approveEnrollment
 * and ::rejectEnrollment.
 */
class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request): View
    {
        $status = EnrollmentStatus::tryFrom((string) $request->query('status'))
            ?? EnrollmentStatus::Pending;

        $enrollments = StudentProfile::query()
            ->with([
                'user:id,name,email,phone,avatar_path,is_active,created_at',
                'user.schedules',
                'instructor:id,name',
                // Eager-loaded so the "decided by" line is not a query per row.
                'decidedBy:id,name',
            ])
            ->where('enrollment_status', $status)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.enrollments.index', [
            'enrollments' => $enrollments,
            'status' => $status,
            'counts' => $this->counts(),
        ]);
    }

    public function approve(Request $request, StudentProfile $enrollment): RedirectResponse
    {
        $this->enrollments->approve($request->user(), $enrollment);

        return back()->with('success', "{$enrollment->user?->name} approved.");
    }

    public function reject(Request $request, StudentProfile $enrollment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->enrollments->reject($request->user(), $enrollment, $data['reason'] ?? null);

        return back()->with('success', "{$enrollment->user?->name} rejected and deactivated.");
    }

    public function reinstate(Request $request, StudentProfile $enrollment): RedirectResponse
    {
        $this->enrollments->reinstate($request->user(), $enrollment);

        return back()->with('success', "{$enrollment->user?->name} reinstated.");
    }

    /**
     * Tab counts, so the queue shows what is waiting without a second page load.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $rows = StudentProfile::query()
            ->selectRaw('enrollment_status, COUNT(*) as total')
            ->groupBy('enrollment_status')
            ->pluck('total', 'enrollment_status');

        $counts = [];

        foreach (EnrollmentStatus::cases() as $case) {
            $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return $counts;
    }
}
