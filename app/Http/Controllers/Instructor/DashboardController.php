<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Services\Earnings\EarningsCalculator;
use App\Support\DayRoster;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The instructor's home page. Replaces app/views/instructor/dashboard.php.
 *
 * Keeps the legacy layout — welcome header, "set up your schedule" prompt, stat
 * tiles, today's roster, and a month calendar driving a per-date roster with
 * inline attendance marking — on top of the new schema.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly EarningsCalculator $earnings) {}

    public function __invoke(Request $request): View
    {
        $instructor = $request->user();

        $window = PayoutWindow::current();
        $summary = $this->earnings->forWindow($instructor->id, $window);
        $lastWeek = $this->earnings->forWindow($instructor->id, $window->previous());

        $selectedDate = $this->resolveDate($request->query('date'));
        $month = CarbonImmutable::create(
            (int) $request->query('year', $selectedDate->year),
            (int) $request->query('month', $selectedDate->month),
            1,
        ) ?: $selectedDate->startOfMonth();

        return view('instructor.dashboard', [
            'instructor' => $instructor,
            'window' => $window,
            'summary' => $summary,
            'lastWeekNet' => $lastWeek->net(),
            'studentCount' => StudentProfile::forInstructor($instructor->id)->teachable()->count(),

            // Legacy prompted for this on every dashboard load, but the page that
            // fixed it was buried in the menu. The CTA links straight there.
            'hasSchedule' => $instructor->availabilities()->exists(),

            'today' => $this->rosterFor($instructor->id, CarbonImmutable::today()),

            // Tomorrow sits under today so the next day's slots are visible
            // without hunting through the calendar. Read-only in the view:
            // attendance is recorded on the day it happens.
            'tomorrow' => $this->rosterFor($instructor->id, CarbonImmutable::tomorrow()),
            'tomorrowDate' => CarbonImmutable::tomorrow(),

            'selectedDate' => $selectedDate,
            'selectedRoster' => $selectedDate->isSameDay(CarbonImmutable::today())
                ? null // same as `today`, so the view reuses it rather than re-querying
                : $this->rosterFor($instructor->id, $selectedDate),

            'calendar' => $this->calendar($instructor->id, $month),
            'month' => $month,
            'unreported' => $this->unreportedSessions($instructor->id, $window),
            'weekBreakdown' => $this->weekBreakdown($instructor->id, $window),
        ]);
    }

    /**
     * Who is scheduled on a date, with that day's session if one exists.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterFor(int $instructorId, CarbonImmutable $date): Collection
    {
        return DayRoster::for($instructorId, $date);
    }

    /**
     * A month of day cells with per-day session counts, for the calendar.
     *
     * Past and present days are counted from class_sessions — what actually
     * happened. Future days have no session rows yet, so their count is
     * projected from the weekly timetable; without that, next month's calendar
     * would be blank and there would be nothing to click.
     *
     * @return array<int, array<string, mixed>>
     */
    private function calendar(int $instructorId, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $counts = ClassSession::query()
            ->selectRaw('paid_date, COUNT(*) as total, SUM(status IS NULL) as unmarked')
            ->where('instructor_id', $instructorId)
            ->whereBetween('paid_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('paid_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->paid_date->toDateString());

        $timetabled = $this->timetabledStudentsByWeekday($instructorId);
        $makeups = $this->makeupStudentsByDate($instructorId, $start, $end);
        $today = CarbonImmutable::today();

        $cells = [];

        // Leading blanks so the 1st lands under the right weekday (Mon-first).
        for ($i = 1; $i < $start->dayOfWeekIso; $i++) {
            $cells[] = ['date' => null];
        }

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $dateString = $day->toDateString();
            $row = $counts->get($dateString);

            // Union of student ids, not a sum: rosterFor keys by student, so a
            // student with both a regular slot and a makeup on one day is one
            // row there and must be one class here.
            $expected = $day->gt($today)
                ? array_unique(array_merge(
                    $timetabled[$day->dayOfWeekIso] ?? [],
                    $makeups[$dateString] ?? [],
                ))
                : [];

            $cells[] = [
                'date' => $day,
                'total' => (int) ($row->total ?? 0),
                'unmarked' => (int) ($row->unmarked ?? 0),
                'upcoming' => count($expected),
            ];
        }

        return $cells;
    }

    /**
     * Which students are timetabled on each ISO weekday.
     *
     * Same population as group 1 of rosterFor. Ids rather than a count, so days
     * can be merged with the makeups below without double-counting a student.
     *
     * @return array<int, array<int, int>>
     */
    private function timetabledStudentsByWeekday(int $instructorId): array
    {
        return DB::table('student_schedules as ss')
            ->join('student_profiles as sp', 'sp.user_id', '=', 'ss.student_id')
            ->join('users', 'users.id', '=', 'ss.student_id')
            ->select('ss.day_of_week as day', 'ss.student_id')
            ->where('sp.instructor_id', $instructorId)
            ->where('sp.enrollment_status', EnrollmentStatus::Approved->value)
            ->where('users.is_active', true)
            // Follows withoutFinishedStudents: a used-up student is off the
            // roster, so their slot must not put an upcoming dot on a future day
            // that renders empty.
            ->where('sp.sessions_remaining', '>', 0)
            ->distinct()
            ->get()
            ->groupBy('day')
            ->map(fn ($rows) => $rows->pluck('student_id')->map(fn ($id) => (int) $id)->all())
            ->mapWithKeys(fn ($ids, $day) => [(int) $day => $ids])
            ->all();
    }

    /**
     * Makeup classes landing in this month, by date.
     *
     * A postponed class is rescheduled to a date its student may not normally
     * have a slot on, and its own row still sits on the original date. Without
     * this the calendar would show no class on the day the student actually
     * comes back — which is exactly the day they need to see.
     *
     * @return array<string, array<int, int>>
     */
    private function makeupStudentsByDate(int $instructorId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ClassSession::query()
            ->select('rescheduled_date', 'student_id')
            ->where('instructor_id', $instructorId)
            ->whereNotNull('rescheduled_date')
            ->whereBetween('rescheduled_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(fn ($session) => $session->rescheduled_date->toDateString())
            ->map(fn ($sessions) => $sessions->pluck('student_id')->map(fn ($id) => (int) $id)->unique()->all())
            ->all();
    }

    /**
     * Settled sessions in this payout window with no report filed — the
     * instructor's unpaid work, and the most actionable number on the page.
     */
    private function unreportedSessions(int $instructorId, PayoutWindow $window): Collection
    {
        return ClassSession::query()
            ->with('student:id,name,avatar_path')
            ->where('instructor_id', $instructorId)
            ->settled()
            ->paidBetween($window->startDate(), $window->endDate())
            // A teacher-absent session is deducted, not paid, so no report is owed.
            ->where(fn ($q) => $q->where('status', SessionStatus::Present)
                ->orWhere(fn ($q) => $q->where('status', SessionStatus::Absent)
                    ->where('absent_by', Party::Student)))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('session_reports')
                ->whereColumn('session_reports.instructor_id', 'class_sessions.instructor_id')
                ->whereColumn('session_reports.student_id', 'class_sessions.student_id')
                ->whereColumn('session_reports.class_date', 'class_sessions.paid_date'))
            ->orderByDesc('paid_date')
            ->get();
    }

    /**
     * Per-day session counts across the payout week, for the strip chart.
     *
     * @return array<int, array<string, mixed>>
     */
    private function weekBreakdown(int $instructorId, PayoutWindow $window): array
    {
        $counts = ClassSession::query()
            ->selectRaw('paid_date, status, COUNT(*) as total')
            ->where('instructor_id', $instructorId)
            ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
            ->whereNotNull('status')
            ->groupBy('paid_date', 'status')
            ->get()
            ->groupBy(fn ($row) => (string) $row->paid_date->toDateString());

        $days = [];

        for ($day = $window->start; $day->lte($window->end); $day = $day->addDay()) {
            $rows = $counts->get($day->toDateString(), collect());

            $days[] = [
                'date' => $day,
                'label' => $day->format('D'),
                'present' => (int) $rows->firstWhere('status', SessionStatus::Present)?->total,
                'absent' => (int) $rows->firstWhere('status', SessionStatus::Absent)?->total,
                'postponed' => (int) $rows->firstWhere('status', SessionStatus::Postponed)?->total,
            ];
        }

        return $days;
    }

    private function resolveDate(?string $raw): CarbonImmutable
    {
        try {
            return $raw ? CarbonImmutable::parse($raw)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }
}
