<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $students = $this->students($instructor->id);

        // Ignored rather than refused when it is not one of theirs: the filter
        // is a query string anyone can edit, and a stale bookmark should fall
        // back to the full history instead of an error page. Membership of
        // $students is also what scopes the filter to this instructor.
        $studentId = (int) $request->query('student_id') ?: null;
        $studentId = $students->has($studentId) ? $studentId : null;

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
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('paid_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('instructor.history.index', [
            'sessions' => $sessions,
            'totals' => $this->totals($instructor->id, $from, $to, $studentId),
            'byStudent' => $this->byStudent($instructor->id, $from, $to, $studentId, $students),
            'students' => $students,
            'from' => $from,
            'to' => $to,
            'selectedStatus' => $request->query('status'),
            'selectedStudentId' => $studentId,
        ]);
    }

    /**
     * Lifetime (or filtered) counts.
     *
     * @return array<string, int|string|null>
     */
    private function totals(int $instructorId, ?string $from, ?string $to, ?int $studentId): array
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
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->first();

        $reports = DB::table('session_reports')
            ->where('instructor_id', $instructorId)
            ->when($from, fn ($q) => $q->where('class_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('class_date', '<=', $to))
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
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

    /**
     * The same counts broken down per student, so how one student's attendance
     * has gone can be read off without paging through every class.
     *
     * Absences are split by party because they mean different things to the
     * student: a student-absent class burnt one of their prepaid sessions, a
     * teacher-absent one did not.
     *
     * The status filter is deliberately not applied — this table is what says
     * which status is worth filtering the list below by.
     *
     * @param  Collection<int, User>  $students
     * @return Collection<int, array<string, mixed>>
     */
    private function byStudent(
        int $instructorId,
        ?string $from,
        ?string $to,
        ?int $studentId,
        Collection $students,
    ): Collection {
        return ClassSession::query()
            ->selectRaw('
                student_id,
                COUNT(*) as total,
                SUM(status = ?) as present,
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ? AND absent_by = ?) as teacher_absent,
                SUM(status = ?) as postponed,
                MAX(paid_date) as last_class
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Absent->value, Party::Teacher->value,
                SessionStatus::Postponed->value,
            ])
            ->where('instructor_id', $instructorId)
            ->whereNotNull('status')
            ->when($from, fn ($q) => $q->where('paid_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_date', '<=', $to))
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->groupBy('student_id')
            ->get()
            ->map(fn ($row) => [
                'student' => $students->get((int) $row->student_id),
                'total' => (int) $row->total,
                'present' => (int) $row->present,
                'student_absent' => (int) $row->student_absent,
                'teacher_absent' => (int) $row->teacher_absent,
                'postponed' => (int) $row->postponed,
                'last_class' => $row->last_class,
            ])
            ->sortBy(fn (array $row) => $row['student']?->name ?? '')
            ->values();
    }

    /**
     * Every student this instructor has a marked class for, keyed by id.
     *
     * Read off class_sessions rather than the current roster: a student who has
     * been archived or reassigned still has history here, and would otherwise
     * disappear from the filter that is the only way to reach it.
     *
     * @return Collection<int, User>
     */
    private function students(int $instructorId): Collection
    {
        return User::query()
            ->withTrashed()
            ->select('id', 'name', 'avatar_path', 'deleted_at')
            ->whereIn('id', ClassSession::query()
                ->select('student_id')
                ->where('instructor_id', $instructorId)
                ->whereNotNull('status'))
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }
}
