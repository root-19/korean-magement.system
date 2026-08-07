<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Every class across every instructor, for one day.
 *
 * Replaces admin/all_classes.php, admin/data_classes.php and
 * admin/teacher-student.php — three pages over the same table.
 */
class ClassSessionController extends Controller
{
    public function index(Request $request): View
    {
        $date = $this->resolveDate($request->query('date'));

        $sessions = ClassSession::query()
            ->with([
                'instructor:id,name,avatar_path',
                'student:id,name,avatar_path',
                'student.studentProfile:id,user_id,teaching_method,learning_time',
            ])
            // Keyed on paid_date, so a class taught early appears on the day it
            // was actually taught rather than on its future slot.
            ->where('paid_date', $date->toDateString())
            ->when($request->filled('instructor'), fn ($q) => $q
                ->where('instructor_id', (int) $request->query('instructor')))
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->query('status');

                return $status === 'unmarked'
                    ? $q->whereNull('status')
                    : $q->where('status', $status);
            })
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        // Report existence, matched on the natural key the earnings query uses.
        $reported = DB::table('session_reports')
            ->whereIn('instructor_id', $sessions->pluck('instructor_id')->unique())
            ->where('class_date', $date->toDateString())
            ->get(['instructor_id', 'student_id'])
            ->map(fn ($r) => $r->instructor_id.'|'.$r->student_id)
            ->flip();

        return view('admin.classes.index', [
            'date' => $date,
            'sessions' => $sessions,
            'reported' => $reported,
            'totals' => $this->totals($date),
            'instructors' => User::query()->instructors()->active()->orderBy('name')->get(['id', 'name']),
            'selectedInstructor' => $request->query('instructor'),
            'selectedStatus' => $request->query('status'),
        ]);
    }

    /**
     * Academy-wide counts for the day.
     *
     * @return array<string, int>
     */
    private function totals(CarbonImmutable $date): array
    {
        $row = ClassSession::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(status = ?) as present,
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ? AND absent_by = ?) as teacher_absent,
                SUM(status = ?) as postponed,
                SUM(status IS NULL) as unmarked
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Absent->value, Party::Teacher->value,
                SessionStatus::Postponed->value,
            ])
            ->where('paid_date', $date->toDateString())
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'present' => (int) ($row->present ?? 0),
            'student_absent' => (int) ($row->student_absent ?? 0),
            'teacher_absent' => (int) ($row->teacher_absent ?? 0),
            'postponed' => (int) ($row->postponed ?? 0),
            'unmarked' => (int) ($row->unmarked ?? 0),
        ];
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
