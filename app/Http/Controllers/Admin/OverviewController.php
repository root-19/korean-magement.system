<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use App\Support\WeeklyScheduleGrid;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only admin views that back the remaining legacy sidebar entries:
 *
 *   admin/teacher_schedules.php  -> schedules()
 *   admin/bookings.php           -> bookings()
 *   (no legacy equivalent)       -> auditLog(), replacing the ad-hoc
 *                                   admin/backup.php screens now that soft
 *                                   deletes made the backup tables redundant
 */
class OverviewController extends Controller
{
    /**
     * Every instructor's published week, side by side.
     */
    public function schedules(Request $request): View
    {
        $instructors = User::query()
            ->instructors()
            ->active()
            ->with('availabilities')
            ->withCount([
                // Both counts are needed: availabilities_count drives the ordering
                // below and is shown on each chip, student_count labels the chip.
                'availabilities',
                'students as student_count' => fn ($q) => $q
                    ->where('enrollment_status', EnrollmentStatus::Approved)
                    ->whereHas('user', fn ($q) => $q->where('is_active', true)),
            ])
            // Instructors who published hours first — they are the ones with
            // something to show.
            ->orderByDesc('availabilities_count')
            ->orderBy('name')
            ->get();

        $selected = $request->filled('instructor')
            ? $instructors->firstWhere('id', (int) $request->query('instructor'))
            : $instructors->first();

        return view('admin.schedules.index', [
            'instructors' => $instructors,
            'selected' => $selected,
            'grid' => $selected ? WeeklyScheduleGrid::forInstructor($selected) : null,
            'days' => WeeklyScheduleGrid::days(),
            'publishedCount' => $instructors->filter(fn (User $i) => $i->availabilities->isNotEmpty())->count(),
        ]);
    }

    /**
     * Trial requests across every instructor.
     */
    public function bookings(Request $request): View
    {
        $status = BookingStatus::tryFrom((string) $request->query('status'));

        $bookings = Booking::query()
            ->with('instructor:id,name,avatar_path')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('instructor'), fn ($q) => $q
                ->where('instructor_id', (int) $request->query('instructor')))
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $counts = Booking::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.bookings.index', [
            'bookings' => $bookings,
            'status' => $status,
            'counts' => collect(BookingStatus::cases())
                ->mapWithKeys(fn (BookingStatus $c) => [$c->value => (int) ($counts[$c->value] ?? 0)])
                ->put('all', (int) $counts->sum())
                ->all(),
            'instructors' => User::query()->instructors()->orderBy('name')->get(['id', 'name']),
            'selectedInstructor' => $request->query('instructor'),
        ]);
    }

    /**
     * The audit trail.
     *
     * Replaces the legacy backup/restore screens: those existed because rows were
     * hard-deleted, and six backup tables were the only record of what had gone.
     * Nothing is hard-deleted now, so what is actually useful is a log of who did
     * what.
     */
    public function auditLog(Request $request): View
    {
        $action = trim((string) $request->query('action'));

        $entries = AuditLog::query()
            ->with('user:id,name,avatar_path')
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', (int) $request->query('user')))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit.index', [
            'entries' => $entries,
            'action' => $action,
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
