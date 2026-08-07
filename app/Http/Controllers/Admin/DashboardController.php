<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Dotenv\Store\File\Reader;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Admin overview. Replaces app/views/admin/dashboard.php.
 */
class DashboardController extends Controller
{
    public function __construct
    (private readonly EarningsCalculator $earnings) {}

    public function __invoke(): View
    {
        $window = PayoutWindow::current();

        return view('admin.dashboard', [
            'window' => $window,
            'counts' => $this->counts(),
            'weekTotals' => $this->weekTotals($window),
            'topInstructors' => $this->topInstructors($window),
            'pending' => StudentProfile::query()
                ->with(['user:id,name,avatar_path,created_at', 'instructor:id,name'])
                ->where('enrollment_status', EnrollmentStatus::Pending)
                ->latest('created_at')
                ->limit(6)
                ->get(),
            'recentActivity' => AuditLog::query()
                ->with('user:id,name')
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Headline counts.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'instructors' => User::query()->instructors()->active()->count(),
            'students' => StudentProfile::query()->approved()
                ->whereHas('user', fn ($q) => $q->where('is_active', true))
                ->count(),
            'pending' => StudentProfile::query()
                ->where('enrollment_status', EnrollmentStatus::Pending)
                ->count(),
            'unassigned' => StudentProfile::query()->approved()
                ->whereNull('instructor_id')
                ->whereHas('user', fn ($q) => $q->where('is_active', true))
                ->count(),
        ];
    }

    /**
     * Sessions and money across the whole academy for the current pay week.
     *
     * Counts come from one grouped query rather than a loop over instructors;
     * only the money needs the calculator.
     *
     * @return array<string, int|float>
     */
    private function weekTotals(PayoutWindow $window): array
    {
        $rows = ClassSession::query()
            ->selectRaw('
                SUM(status = ?) as present,
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ? AND absent_by = ?) as teacher_absent,
                SUM(status = ?) as postponed,
                COUNT(*) as total
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Absent->value, Party::Teacher->value,
                SessionStatus::Postponed->value,
            ])
            ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
            ->first();

        return [
            'present' => (int) ($rows->present ?? 0),
            'student_absent' => (int) ($rows->student_absent ?? 0),
            'teacher_absent' => (int) ($rows->teacher_absent ?? 0),
            'postponed' => (int) ($rows->postponed ?? 0),
            'total' => (int) ($rows->total ?? 0),
        ];
    }

    /**
     * The week's earners, highest first. Also yields the academy-wide payroll
     * figure, so it is computed once here rather than twice.
     *
     * @return Collection<int, array{instructor: User, net: float, sessions: int}>
     */
    private function topInstructors(PayoutWindow $window): Collection
    {
        // Restricted to instructors who actually have a session in the window,
        // so this is not 33 payslip computations to show 8 rows.
        $active = ClassSession::query()
            ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
            ->whereNotNull('status')
            ->distinct()
            ->pluck('instructor_id');

        return User::query()
            ->instructors()
            ->whereIn('id', $active)
            ->get()
            ->map(function (User $instructor) use ($window) {
                $summary = $this->earnings->forWindow($instructor->id, $window);

                return [
                    'instructor' => $instructor,
                    'net' => $summary->net(),
                    'sessions' => $summary->sessionsPaid(),
                ];
            })
            ->sortByDesc('net')
            ->values();
    }
}
