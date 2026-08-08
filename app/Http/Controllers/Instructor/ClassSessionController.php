<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Support\MakeupSchedule;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The instructor's day view: who is scheduled today and what happened.
 *
 * Replaces app/views/instructor/classes.php — 4,209 lines of interleaved SQL,
 * HTML, inline CSS and jQuery in a single file.
 */
class ClassSessionController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): View
    {
        $instructor = $request->user();

        $date = $this->resolveDate($request->query('date'));

        return view('instructor.classes.index', [
            'date' => $date,
            'roster' => $this->rosterFor($instructor->id, $date),
            'statuses' => SessionStatus::cases(),
        ]);
    }

    /**
     * Record attendance for one slot.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:present,absent,postponed'],
            'party' => ['nullable', 'string', 'in:student,teacher,other'],
            'reason' => ['nullable', 'string', 'max:1000'],

            // Postponement only: when the class comes back. `auto` lets
            // MakeupSchedule pick the slot after the student's remaining
            // classes; `manual` takes the date below. `after:date` names the
            // field above, so a makeup can never land on or before the class it
            // replaces.
            'reschedule' => ['nullable', 'string', 'in:auto,manual'],
            'rescheduled_date' => ['nullable', 'required_if:reschedule,manual', 'date', 'after:date'],
            'rescheduled_time' => ['nullable', 'date_format:H:i'],
        ]);

        $student = $this->authorizedStudent($instructor, (int) $data['student_id']);
        $status = SessionStatus::from($data['status']);

        $this->assertClassIsOpen($instructor, $student, $data['date']);

        // `validate()` returns only the keys the request actually contained, so a
        // nullable field that was never submitted is ABSENT, not null — the
        // "Present" button posts no `party` at all. tryFrom on a coalesced string
        // covers absent, empty and null alike; the `in:` rule above already
        // guaranteed the value is valid when one was sent.
        $party = Party::tryFrom((string) ($data['party'] ?? ''));

        if ($status === SessionStatus::Postponed) {
            $makeup = $this->makeupFor($student, $data);

            $session = $this->attendance->postpone(
                instructor: $instructor,
                student: $student,
                scheduledDate: $data['date'],
                postponedBy: $party ?? Party::Other,
                reason: $data['reason'] ?? null,
                rescheduledDate: $makeup['date'],
                rescheduledTime: $makeup['time'],
            );
        } else {
            $session = $this->attendance->mark(
                instructor: $instructor,
                student: $student,
                scheduledDate: $data['date'],
                status: $status,
                absentBy: $party,
                reason: $data['reason'] ?? null,
            );
        }

        // Say when the class comes back, not just that it moved — "postponed" on
        // its own is the answer to half the question.
        $message = $session->rescheduled_date
            ? sprintf(
                '%s marked %s — back on %s%s.',
                $student->name,
                $status->label(),
                $session->rescheduled_date->format('D, M j'),
                $session->rescheduled_time
                    ? ' at '.CarbonImmutable::parse($session->rescheduled_time)->format('g:i A')
                    : '',
            )
            : "{$student->name} marked {$status->label()}.";

        // A reopened late class pays into the week it happened, not this one, and
        // the earnings page opens on the CURRENT week -- so without this the
        // instructor marks it, sees an empty payslip, and thinks it was lost.
        $window = PayoutWindow::forDate($data['date']);

        if (! $window->isCurrent()) {
            $message .= " It counts toward the {$window->label()} payslip.";
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'session' => [
                    'id' => $session->id,
                    'status' => $session->status?->value,
                    'absent_by' => $session->absent_by?->value,
                ],
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Pull a future session forward: taught today, credited against a later slot.
     */
    public function storeEarly(Request $request): RedirectResponse|JsonResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'held_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after:held_date'],
        ]);

        $student = $this->authorizedStudent($instructor, (int) $data['student_id']);

        $session = $this->attendance->markEarly(
            instructor: $instructor,
            student: $student,
            heldDate: $data['held_date'],
            targetDate: $data['target_date'],
        );

        $message = sprintf(
            'Early class recorded: the %s session for %s was taught on %s.',
            $session->scheduled_date->format('F j, Y'),
            $student->name,
            $session->held_date->format('F j, Y'),
        );

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : back()->with('success', $message);
    }

    /**
     * Clear a marking and roll back the student's session counters.
     */
    public function destroy(Request $request, ClassSession $session): RedirectResponse|JsonResponse
    {
        $instructor = $request->user();

        abort_unless($session->instructor_id === $instructor->id, 403);

        $this->attendance->unmark($instructor, $session);

        $message = 'Attendance cleared.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : back()->with('success', $message);
    }

    /**
     * Ask an admin to reopen a class that has already passed.
     *
     * Idempotent on the class: asking again after a rejection reuses the row and
     * puts it back to pending, so the decision history stays in one place.
     */
    public function requestEvaluation(Request $request): RedirectResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            // Bound to the app's clock: `before_or_equal:today` resolves through
            // strtotime(), which ignores Carbon's test time and any app timezone.
            'date' => ['required', 'date', 'before_or_equal:'.CarbonImmutable::today()->toDateString()],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $student = $this->authorizedStudent($instructor, (int) $data['student_id']);
        $date = CarbonImmutable::parse($data['date'])->toDateString();

        $existing = AttendanceRequest::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->whereDate('class_date', $date)
            ->first();

        if ($existing?->isApproved()) {
            return back()->with('success', 'That class is already approved — you can mark it now.');
        }

        AttendanceRequest::updateOrCreate(
            [
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'class_date' => $date,
            ],
            [
                'reason' => $data['reason'],
                'status' => AttendanceRequest::PENDING,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ],
        );

        return back()->with('success', sprintf(
            'Sent for evaluation: %s on %s. You can mark it once an admin approves.',
            $student->name,
            CarbonImmutable::parse($date)->format('M j'),
        ));
    }

    /**
     * Refuse to record attendance on a closed class.
     *
     * Marking a session releases its payment, so a late marking is a payroll
     * edit. Today is open; an earlier date needs an approved AttendanceRequest
     * for that exact class. The button is hidden in the roster too, but this is
     * the check that counts — the form is a plain POST anyone could replay.
     */
    private function assertClassIsOpen(User $instructor, User $student, string $date): void
    {
        $request = AttendanceRequest::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->whereDate('class_date', $date)
            ->first();

        if (AttendanceRequest::classIsOpen($date, $request)) {
            return;
        }

        $when = CarbonImmutable::parse($date);

        throw ValidationException::withMessages([
            'date' => $when->isFuture()
                ? 'That class has not happened yet.'
                : sprintf(
                    'The %s class for %s is closed. Send it for evaluation and an admin can reopen it.',
                    $when->format('M j'),
                    $student->name,
                ),
        ]);
    }

    // ---------------------------------------------------------------- internals

    /**
     * When a postponed class comes back.
     *
     * Defaults to auto rather than to nothing. A postponement with no makeup date
     * is a class that disappears from every roster — the legacy modal could not
     * prevent it and warned about it in red capitals instead.
     *
     * @param  array<string, mixed>  $data
     * @return array{date: ?string, time: ?string}
     */
    private function makeupFor(User $student, array $data): array
    {
        $manual = ($data['reschedule'] ?? 'auto') === 'manual';

        $date = $manual
            ? ($data['rescheduled_date'] ?? null)
            : MakeupSchedule::for(
                $student,
                $data['date'],
                (int) ($student->studentProfile?->sessions_remaining ?? 0),
            )->autoDate?->toDateString();

        // An explicit time wins; otherwise the makeup inherits the student's
        // usual slot on that weekday.
        $time = $data['rescheduled_time'] ?? null;

        if ($time === null && $date !== null) {
            $time = MakeupSchedule::usualTimeOn($student, $date);
        }

        return ['date' => $date, 'time' => $time];
    }

    /**
     * The students scheduled on $date, each with that day's session if any.
     *
     * Driven from student_schedules, so "who has class on a Wednesday" is an
     * indexed integer match. The legacy query did
     * `WHERE u.schedule LIKE '%Wednesday%'` against a comma-joined string, then
     * read the matching `<day>_time` column by building the column name in PHP.
     *
     * @return Collection<int, array{student: User, profile: ?StudentProfile, time: ?string, session: ?ClassSession, has_report: bool}>
     */
    private function rosterFor(int $instructorId, CarbonImmutable $date): Collection
    {
        $isoDay = $date->dayOfWeekIso;

        $students = User::query()
            ->select('users.*')
            ->join('student_profiles as sp', 'sp.user_id', '=', 'users.id')
            ->join('student_schedules as ss', function ($join) use ($isoDay) {
                $join->on('ss.student_id', '=', 'users.id')->where('ss.day_of_week', '=', $isoDay);
            })
            ->with(['studentProfile', 'schedules'])
            ->where('sp.instructor_id', $instructorId)
            ->where('sp.enrollment_status', EnrollmentStatus::Approved)
            ->where('users.is_active', true)
            ->addSelect('ss.start_time as slot_time')
            ->orderByRaw('ss.start_time IS NULL, ss.start_time')
            ->orderBy('users.name')
            ->get();

        // One extra query for the whole day's sessions rather than one per row.
        $sessions = ClassSession::query()
            ->with('report:id,class_session_id,instructor_id,student_id,class_date')
            ->where('instructor_id', $instructorId)
            ->whereDate('scheduled_date', $date->toDateString())
            ->get()
            ->keyBy('student_id');

        $dateString = $date->toDateString();

        // Whether each class is still open to marking: today is, an earlier day
        // needs an approved request. Fetched once for the whole roster.
        $requests = AttendanceRequest::query()
            ->where('instructor_id', $instructorId)
            ->whereDate('class_date', $dateString)
            ->get()
            ->keyBy('student_id');

        // Reports are matched on the natural key, the same way the earnings
        // query does, so historical rows with no resolved FK still count.
        $reported = DB::table('session_reports')
            ->where('instructor_id', $instructorId)
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('class_date', 'student_id');

        return $students->map(function (User $student) use ($sessions, $reported, $date, $requests) {
            $session = $sessions->get($student->id);
            $paidDate = $session?->paid_date?->toDateString() ?? $date->toDateString();

            return [
                'student' => $student,
                'profile' => $student->studentProfile,
                'time' => $student->slot_time,
                'session' => $session,
                'has_report' => isset($reported[$student->id])
                    && (string) $reported[$student->id] === $paidDate,
                'request' => $requests->get($student->id),
            ];
        });
    }

    private function resolveDate(?string $raw): CarbonImmutable
    {
        try {
            return $raw ? CarbonImmutable::parse($raw)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    /**
     * Load a student, refusing anyone not assigned to this instructor.
     *
     * The legacy endpoints took student_id straight off $_POST and trusted it,
     * so any instructor could mark attendance — and therefore bill — against
     * another instructor's student.
     */
    private function authorizedStudent(User $instructor, int $studentId): User
    {
        $student = User::query()
            ->whereKey($studentId)
            // Both the makeup lookup and AttendanceService read these, and
            // preventLazyLoading turns an implicit load into an error locally.
            ->with(['schedules', 'studentProfile'])
            ->whereHas('studentProfile', fn ($q) => $q->where('instructor_id', $instructor->id))
            ->first();

        abort_if($student === null, 403, 'That student is not assigned to you.');

        return $student;
    }
}
