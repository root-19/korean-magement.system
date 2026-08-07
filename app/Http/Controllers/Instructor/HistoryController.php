<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Every class this instructor has ever taught.
 *
 * Replaces app/views/instructor/history.php. The legacy page read from
 * `instructor_attendance_history`, an append-only mirror of teacher_presence
 * that existed because rows could be hard-deleted out from under the instructor.
 * Sessions are never hard-deleted now, so this reads the real table.
 */
class HistoryController extends Controller
{
    public function index(Request $request): View
    {
        $instructor = $request->user();

        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $sessions = ClassSession::query()
            // withTrashed on the student: archiving a student must not erase the
            // instructor's record of having taught them.
            ->with([
                'student' => fn ($q) => $q->withTrashed()->select('id', 'name', 'avatar_path'),
                'student.studentProfile:id,user_id,teaching_method,learning_time',
                'report:id,class_session_id',
            ])
            ->where('instructor_id', $instructor->id)
            ->whereNotNull('status')
            ->when($from, fn ($q) => $q->where('paid_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_date', '<=', $to))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('paid_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('instructor.history.index', [
            'sessions' => $sessions,
            'totals' => $this->totals($instructor->id, $from, $to),
            'from' => $from,
            'to' => $to,
            'selectedStatus' => $request->query('status'),
        ]);
    }

    /**
     * Lifetime (or filtered) counts.
     *
     * @return array<string, int>
     */
    private function totals(int $instructorId, ?string $from, ?string $to): array
    {
        $row = ClassSession::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(status = ?) as present,
                SUM(status = ?) as absent,
                SUM(status = ?) as postponed,
                COUNT(DISTINCT student_id) as students,
                MIN(paid_date) as first_class
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value,
                SessionStatus::Postponed->value,
            ])
            ->where('instructor_id', $instructorId)
            ->whereNotNull('status')
            ->when($from, fn ($q) => $q->where('paid_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_date', '<=', $to))
            ->first();

        $reports = DB::table('session_reports')
            ->where('instructor_id', $instructorId)
            ->when($from, fn ($q) => $q->where('class_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('class_date', '<=', $to))
            ->count();

        return [
            'total' => (int) ($row->total ?? 0),
            'present' => (int) ($row->present ?? 0),
            'absent' => (int) ($row->absent ?? 0),
            'postponed' => (int) ($row->postponed ?? 0),
            'students' => (int) ($row->students ?? 0),
            'reports' => $reports,
            'first_class' => $row->first_class,
        ];
    }
}
