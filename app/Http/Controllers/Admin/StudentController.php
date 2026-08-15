<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\Role;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentRequest;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Services\Enrollment\StudentEnroller;
use App\Services\Enrollment\StudentUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student roster across every instructor.
 *
 * Consolidates admin/student.php, admin/user_table.php, admin/free_students.php
 * and admin/re_enrolled.php into one list with filters, rather than four pages
 * running four variants of the same query.
 */
class StudentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'active');

        $students = StudentProfile::query()
            // withTrashed: the join below does not apply the soft-delete scope, so
            // an archived or deleted student's row IS returned. Without it here
            // the relation would come back null for exactly those rows and the
            // view could not even build a link to them. Admins are the people who
            // need to see archived students.
            ->with([
                'user' => fn ($q) => $q->withTrashed()
                    ->select('id', 'name', 'email', 'phone', 'avatar_path', 'is_active', 'deleted_at'),
                'user.schedules',
                'instructor:id,name',
            ])
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->select('student_profiles.*')
            ->when($search !== '', fn ($q) => $q->where('users.name', 'like', "%{$search}%"))
            ->when($request->filled('instructor'), fn ($q) => $q
                ->where('student_profiles.instructor_id', (int) $request->query('instructor')))
            ->tap(fn ($q) => $this->applyFilter($q, $filter))
            ->orderBy('users.name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.students.index', [
            'students' => $students,
            'search' => $search,
            'filter' => $filter,
            'filters' => $this->filterCounts(),
            'instructors' => User::query()->instructors()->active()->orderBy('name')->get(['id', 'name']),
            'selectedInstructor' => $request->query('instructor'),
        ]);
    }

    /**
     * The enrol form. The instructor equivalent is instructor/students/create.
     */
    public function create(): View
    {
        return view('admin.students.create', $this->formOptions());
    }

    /**
     * Enrol a student from the admin area.
     *
     * StudentEnroller approves an admin's enrolment outright — there is nobody
     * left to approve it — so the student is teachable and billable on save.
     */
    public function store(StoreStudentRequest $request, StudentEnroller $enroller): RedirectResponse
    {
        $data = $request->studentData();

        $result = $enroller->enrol(
            actor: $request->user(),
            data: $data,
            instructor: ($data['instructor_id'] ?? null)
                ? User::query()->instructors()->find($data['instructor_id'])
                : null,
        );

        return redirect()
            ->route('admin.students.show', $result['student'])
            ->with('success', sprintf(
                '%s enrolled and approved. Temporary password: %s',
                $result['student']->name,
                $result['password'],
            ));
    }

    public function show(User $student): View
    {
        abort_unless($student->role === Role::Student, 404);

        // The Account card names who deleted them; preventLazyLoading would turn
        // reading it in the view into an error locally.
        $student->loadMissing('deletedBy:id,name');

        $profile = StudentProfile::query()
            ->with(['instructor:id,name', 'user' => fn ($q) => $q->withTrashed(), 'user.schedules'])
            ->where('user_id', $student->id)
            ->firstOrFail();

        $sessions = ClassSession::query()
            ->with(['instructor:id,name', 'report:id,class_session_id'])
            ->where('student_id', $student->id)
            ->orderByDesc('scheduled_date')
            ->paginate(30);

        return view('admin.students.show', [
            'student' => $student,
            'profile' => $profile,
            'sessions' => $sessions,
            'stats' => $this->stats($student->id, $profile),
            'instructors' => User::query()->instructors()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The edit form: every detail of one student on one page.
     */
    public function edit(User $student): View
    {
        abort_unless($student->role === Role::Student, 404);

        $profile = $this->profileFor($student);

        // preventLazyLoading would turn reading the timetable into an error
        // locally, and the counters card needs the same figures as the profile.
        $student->loadMissing('schedules');

        return view('admin.students.edit', $this->formOptions() + [
            'student' => $student,
            'profile' => $profile,
            'stats' => $this->stats($student->id, $profile),
            'statuses' => $this->statusOptions($profile),
            'schedule' => $student->schedules
                ->mapWithKeys(fn (StudentSchedule $slot) => [$slot->day_of_week => $slot->inputTime()])
                ->all(),
        ]);
    }

    public function update(UpdateStudentRequest $request, User $student, StudentUpdater $updater): RedirectResponse
    {
        abort_unless($student->role === Role::Student, 404);

        $updater->update(
            admin: $request->user(),
            student: $student,
            profile: $this->profileFor($student),
            data: $request->studentData(),
        );

        return redirect()
            ->route('admin.students.show', $student)
            ->with('success', "{$student->name} updated.");
    }

    /**
     * Reassign a student to a different instructor.
     *
     * Past sessions keep the instructor who taught them — class_sessions carries
     * its own instructor_id — so reassigning never moves historical earnings.
     */
    public function reassign(Request $request, User $student, StudentUpdater $updater): RedirectResponse
    {
        abort_unless($student->role === Role::Student, 404);

        $data = $request->validate([
            'instructor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Absent key, not just null: see the note in the instructor
        // ClassSessionController — validate() omits fields the request never sent.
        $instructorId = $data['instructor_id'] ?? null;

        $instructor = $instructorId
            ? User::query()->instructors()->findOrFail($instructorId)
            : null;

        $updater->reassign($request->user(), $student, $this->profileFor($student), $instructor);

        return back()->with(
            'success',
            $instructor
                ? "{$student->name} reassigned to {$instructor->name}."
                : "{$student->name} is now unassigned."
        );
    }

    /**
     * Archive or restore a student.
     *
     * A soft delete, never a hard one: attendance and reports reference this row,
     * and preserving it is what keeps the instructor's earnings intact.
     *
     * Restoring also undoes an approved deletion. The two states are recorded
     * separately — archived is is_active, deleted is deleted_at — but there is
     * one way back for both, otherwise a restored student would still be
     * invisible everywhere and nothing on this page would say why.
     */
    public function toggleStatus(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->role === Role::Student, 404);

        $wasDeleted = $student->trashed();

        $student->update(['is_active' => ! $student->is_active]);

        if ($student->is_active && $wasDeleted) {
            $student->deleted_by = null;
            $student->save();
            $student->restore();
        }

        AuditLog::record(
            action: $student->is_active ? 'student.restored' : 'student.archived',
            subject: $student,
            targetName: $student->name,
            details: $wasDeleted ? ['undeleted' => true] : [],
            userId: $request->user()->id,
        );

        return back()->with(
            'success',
            "{$student->name} ".($student->is_active ? 'restored' : 'archived').'.'
        );
    }

    // ---------------------------------------------------------------- internals

    /**
     * Every student has exactly one profile; the pages that edit one cannot
     * work without it, so a missing row is a 404 rather than a blank form.
     */
    private function profileFor(User $student): StudentProfile
    {
        return StudentProfile::query()
            ->with('instructor:id,name')
            ->where('user_id', $student->id)
            ->firstOrFail();
    }

    /**
     * The pickers both the enrol and edit forms render.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'days' => StudentSchedule::DAYS,
            'methods' => TeachingMethod::cases(),
            'learningTimes' => config('academy.learning_times'),
            'instructors' => User::query()->instructors()->active()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Statuses the edit form may offer.
     *
     * Approving or rejecting is a decision and nothing un-decides one, so
     * "awaiting approval" is only listed while it still applies.
     *
     * @return array<int, EnrollmentStatus>
     */
    private function statusOptions(StudentProfile $profile): array
    {
        return array_values(array_filter(
            EnrollmentStatus::cases(),
            fn (EnrollmentStatus $status) => $status !== EnrollmentStatus::Pending
                || $profile->enrollment_status === EnrollmentStatus::Pending,
        ));
    }

    /**
     * The four legacy pages, as filters on one query.
     */
    private function applyFilter(mixed $query, string $filter): void
    {
        match ($filter) {
            // admin/free_students.php — enrolled but nobody teaching them.
            'unassigned' => $query
                ->whereNull('student_profiles.instructor_id')
                ->where('users.is_active', true),

            // Plan exhausted; the legacy re_enrolled page listed these.
            'no_sessions' => $query
                ->where('student_profiles.sessions_remaining', 0)
                ->where('users.is_active', true),

            'archived' => $query->where('users.is_active', false),

            'pending' => $query
                ->where('student_profiles.enrollment_status', EnrollmentStatus::Pending),

            'all' => $query,

            default => $query
                ->where('users.is_active', true)
                ->where('student_profiles.enrollment_status', EnrollmentStatus::Approved),
        };
    }

    /**
     * @return array<string, int>
     */
    private function filterCounts(): array
    {
        // A fresh builder per count — reusing one would accumulate the where
        // clauses of every preceding filter.
        $base = fn () => StudentProfile::query()
            ->join('users', 'users.id', '=', 'student_profiles.user_id');

        return [
            'active' => $base()
                ->where('users.is_active', true)
                ->where('student_profiles.enrollment_status', EnrollmentStatus::Approved)
                ->count(),
            'unassigned' => $base()
                ->whereNull('student_profiles.instructor_id')
                ->where('users.is_active', true)
                ->count(),
            'no_sessions' => $base()
                ->where('student_profiles.sessions_remaining', 0)
                ->where('users.is_active', true)
                ->count(),
            'pending' => $base()
                ->where('student_profiles.enrollment_status', EnrollmentStatus::Pending)
                ->count(),
            'archived' => $base()->where('users.is_active', false)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $studentId, StudentProfile $profile): array
    {
        $counts = ClassSession::query()
            ->selectRaw('
                SUM(status = ?) as present,
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ? AND absent_by = ?) as teacher_absent,
                SUM(status = ?) as postponed
            ', [
                SessionStatus::Present->value,
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Absent->value, Party::Teacher->value,
                SessionStatus::Postponed->value,
            ])
            ->where('student_id', $studentId)
            ->first();

        $studentAbsent = (int) ($counts->student_absent ?? 0);

        return [
            'present' => (int) ($counts->present ?? 0),
            'student_absent' => $studentAbsent,
            'teacher_absent' => (int) ($counts->teacher_absent ?? 0),
            'postponed' => (int) ($counts->postponed ?? 0),
            'remaining' => $profile->sessions_remaining,
            'deducted' => $profile->sessions_deducted,
            'purchased' => $profile->sessionsPurchased($studentAbsent),
            'counter_attended' => $profile->sessions_attended,
        ];
    }
}
