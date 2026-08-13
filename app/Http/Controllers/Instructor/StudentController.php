<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\StudentDeletionRequest;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Services\Enrollment\StudentDeletionService;
use App\Services\Enrollment\StudentEnroller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $request): View
    {
        $instructor = $request->user();

        $search = trim((string) $request->query('q'));

        $students = StudentProfile::query()
            ->with(['user:id,name,email,avatar_path,is_active', 'user.schedules'])
            ->forInstructor($instructor->id)
            ->when($search !== '', fn ($q) => $q->whereHas(
                'user',
                fn ($q) => $q->where('name', 'like', "%{$search}%")
            ))
            ->when(
                $request->query('status') === 'archived',
                fn ($q) => $q->whereHas('user', fn ($q) => $q->where('is_active', false)),
                fn ($q) => $q->teachable(),
            )
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->orderBy('users.name')
            ->select('student_profiles.*')
            ->paginate(25)
            ->withQueryString();

        $studentIds = $students->pluck('user_id')->all();

        return view('instructor.students.index', [
            'students' => $students,
            'search' => $search,
            'showingArchived' => $request->query('status') === 'archived',
            'deletionRequests' => $this->deletionRequestsFor($instructor->id, $studentIds),
            // What a deletion carries with it, so the modal can say so before the
            // instructor asks for one.
            'sessionCounts' => $this->sessionCountsFor($studentIds),
        ]);
    }

    /**
     * Open delete requests for the students on this page, keyed by student id.
     *
     * One query for the page: the row needs to know whether to offer the Delete
     * button, say it is already waiting on an admin, or show why it was refused.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, StudentDeletionRequest>
     */
    private function deletionRequestsFor(int $instructorId, array $studentIds)
    {
        if ($studentIds === []) {
            return collect();
        }

        return StudentDeletionRequest::query()
            ->forInstructor($instructorId)
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', [StudentDeletionRequest::PENDING, StudentDeletionRequest::REJECTED])
            ->get()
            ->keyBy('student_id');
    }

    /**
     * Recorded classes per student on this page, keyed by student id.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, int>
     */
    private function sessionCountsFor(array $studentIds)
    {
        if ($studentIds === []) {
            return collect();
        }

        return ClassSession::query()
            ->selectRaw('student_id, COUNT(*) as total')
            ->whereIn('student_id', $studentIds)
            ->groupBy('student_id')
            ->pluck('total', 'student_id');
    }

    /**
     * The enrol form. Legacy: instructor/add_student.php.
     */
    public function create(): View
    {
        return view('instructor.students.create', [
            'days' => StudentSchedule::DAYS,
            'methods' => TeachingMethod::cases(),
            'learningTimes' => config('academy.learning_times'),
        ]);
    }

    /**
     * Enrol a student under the signed-in instructor.
     *
     * Starts pending: an admin approves before the student is teachable or
     * billable.
     */
    public function store(Request $request, StudentEnroller $enroller): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'kakaotalk_id' => ['nullable', 'string', 'max:100'],
            'teaching_method' => ['required', 'string', 'in:audio,video_kids,video_adults'],
            'learning_time' => ['required', 'integer', 'in:'.implode(',', config('academy.learning_times'))],
            'sessions_purchased' => ['required', 'integer', 'min:1', 'max:500'],
            'sessions_deducted' => ['nullable', 'integer', 'min:0', 'max:500'],
            'is_regular' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'schedule' => ['nullable', 'array'],
            'schedule.*' => ['nullable', 'date_format:H:i'],
        ]);

        $result = $enroller->enrol(
            actor: $request->user(),
            data: array_merge($data, [
                'is_regular' => $request->boolean('is_regular', true),
                'schedule' => array_filter($data['schedule'] ?? []),
            ]),
        );

        return redirect()
            ->route('instructor.students.index')
            ->with('success', sprintf(
                '%s enrolled and sent for approval. Temporary password: %s',
                $result['student']->name,
                $result['password'],
            ));
    }

    public function show(Request $request, User $student): View
    {
        $instructor = $request->user();

        $profile = StudentProfile::query()
            ->with(['user.schedules', 'instructor:id,name'])
            ->where('user_id', $student->id)
            ->where('instructor_id', $instructor->id)
            ->firstOrFail();

        $sessions = ClassSession::query()
            ->with('report:id,class_session_id,instructor_id,student_id,class_date')
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->orderByDesc('scheduled_date')
            ->paginate(30);

        return view('instructor.students.show', [
            'student' => $student,
            'profile' => $profile,
            'sessions' => $sessions,
            'stats' => $this->stats($instructor->id, $student->id, $profile),
        ]);
    }

    /**
     * Ask an admin to delete a student.
     *
     * The instructor never deletes anyone: an admin approval is what removes the
     * student. All this endpoint does is record the request and the reason given
     * for it.
     */
    public function requestDeletion(
        Request $request,
        User $student,
        StudentDeletionService $deletions,
    ): RedirectResponse {
        $instructor = $request->user();

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->assertTeaches($instructor, $student);

        $deletions->request($instructor, $student, $data['reason']);

        return redirect()
            ->route('instructor.students.index')
            ->with('success', sprintf(
                'Delete request sent for %s. Nothing is removed until an admin approves it.',
                $student->name,
            ));
    }

    /**
     * Refuse anyone not assigned to this instructor.
     *
     * The button is only rendered on the instructor's own rows, but that is not
     * what enforces it — the form is a plain POST anyone could replay.
     */
    private function assertTeaches(User $instructor, User $student): void
    {
        $teaches = StudentProfile::query()
            ->where('user_id', $student->id)
            ->forInstructor($instructor->id)
            ->exists();

        abort_unless($teaches, 403, 'That student is not assigned to you.');
    }

    /**
     * Session accounting for one student.
     *
     * Reproduces the identity the legacy feedback page rendered:
     *
     *   purchased = attended + student-absent + remaining + deducted
     *
     * `attended` and `remaining` are the denormalised counters on the profile;
     * the absent counts are derived from class_sessions so the two can be
     * compared and any drift in the legacy counters is visible rather than
     * hidden.
     *
     * @return array<string, int>
     */
    private function stats(int $instructorId, int $studentId, StudentProfile $profile): array
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
            ->where('instructor_id', $instructorId)
            ->where('student_id', $studentId)
            ->first();

        $present = (int) ($counts->present ?? 0);
        $studentAbsent = (int) ($counts->student_absent ?? 0);

        return [
            'present' => $present,
            'student_absent' => $studentAbsent,
            'teacher_absent' => (int) ($counts->teacher_absent ?? 0),
            'postponed' => (int) ($counts->postponed ?? 0),
            'remaining' => $profile->sessions_remaining,
            'deducted' => $profile->sessions_deducted,
            'purchased' => $profile->sessionsPurchased($studentAbsent),
            // The counter the legacy code maintained by hand, kept alongside the
            // derived figure so a mismatch is visible instead of silent.
            'counter_attended' => $profile->sessions_attended,
        ];
    }
}
