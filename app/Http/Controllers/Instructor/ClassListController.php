<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The three legacy "list of classes" pages, which differed only in which
 * students they selected:
 *
 *   regular_classes.php     students on a fixed weekly timetable
 *   demo_classes.php        "Data Classes" — video students
 *   irregular_students.php  "Trial Student" — no timetable / one-offs
 *
 * Kept as separate routes so the sidebar matches the legacy menu, but they share
 * one controller and one view because the only real difference is a scope.
 */
class ClassListController extends Controller
{
    public function regular(Request $request): View
    {
        return $this->render(
            $request,
            key: 'regular',
            title: 'Regular Classes',
            subtitle: 'Students on a fixed weekly timetable',
            filter: fn ($q) => $q->where('student_profiles.is_regular', true)
                ->whereHas('user.schedules'),
        );
    }

    public function demo(Request $request): View
    {
        return $this->render(
            $request,
            key: 'demo',
            title: 'Data Classes',
            subtitle: 'Video students',
            filter: fn ($q) => $q->whereIn('student_profiles.teaching_method', [
                TeachingMethod::VideoKids->value,
                TeachingMethod::VideoAdults->value,
            ]),
        );
    }

    public function trials(Request $request): View
    {
        return $this->render(
            $request,
            key: 'trials',
            title: 'Trial Students',
            // Legacy getIrregularUsers() keyed off having postponed classes; the
            // more useful definition for a teacher is "no fixed timetable yet".
            subtitle: 'No fixed weekly timetable yet',
            filter: fn ($q) => $q->where(function ($q) {
                $q->where('student_profiles.is_regular', false)
                    ->orWhereDoesntHave('user.schedules');
            }),
        );
    }

    /**
     * @param  \Closure(Builder): mixed  $filter
     */
    private function render(
        Request $request,
        string $key,
        string $title,
        string $subtitle,
        \Closure $filter,
    ): View {
        $instructor = $request->user();

        $students = StudentProfile::query()
            ->with(['user:id,name,email,avatar_path,is_active', 'user.schedules'])
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->select('student_profiles.*')
            ->where('student_profiles.instructor_id', $instructor->id)
            ->where('student_profiles.enrollment_status', EnrollmentStatus::Approved)
            ->where('users.is_active', true)
            // The join is raw, so the User model's soft-delete scope never runs
            // and is_active does not stand in for it. An archived student whose
            // flag was left on listed a row here with no name on it: the `user`
            // relation DOES apply the scope, so it came back null.
            ->whereNull('users.deleted_at')
            ->tap($filter)
            ->orderBy('users.name')
            ->paginate(30)
            ->withQueryString();

        return view('instructor.classes.list', [
            'listKey' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'students' => $students,
            'stats' => $this->statsFor($instructor->id, $students->pluck('user_id')->all()),
        ]);
    }

    /**
     * Attendance totals per student, in one query rather than one per row.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, object>
     */
    private function statsFor(int $instructorId, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return ClassSession::query()
            ->selectRaw('
                student_id,
                SUM(status = ?) as present,
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ?) as postponed,
                MAX(paid_date) as last_class
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Postponed->value,
            ])
            ->where('instructor_id', $instructorId)
            ->whereIn('student_id', $studentIds)
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');
    }
}
