<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Post-class reports. Legacy name: "feedback".
 *
 * Filing one is what releases payment for the session, so the form is reachable
 * directly from any unpaid session on the dashboard and the class list.
 *
 * Two ways in, because they authorise differently. Filing a new report is keyed
 * on (student, date) and requires the student to be assigned to the instructor
 * right now. Re-opening a filed one is keyed on the report, and the report
 * itself is the authorisation — a student who has since been archived or handed
 * to another instructor must not lock the instructor out of what they wrote.
 */
class SessionReportController extends Controller
{
    public function index(Request $request): View
    {
        $instructor = $request->user();

        $reports = SessionReport::query()
            // withTrashed on the student: this list is history, and archiving a
            // student must not take the instructor's own reports down with it.
            // Without it the relation resolves to null and the page 500s.
            ->with([
                'student' => fn ($q) => $q->withTrashed()->select('id', 'name', 'avatar_path', 'deleted_at'),
            ])
            ->forInstructor($instructor->id)
            ->orderByDesc('class_date')
            ->orderByDesc('id')
            ->paginate(25);

        return view('instructor.reports.index', ['reports' => $reports]);
    }

    /**
     * The form for one session. `date` is the date the class was TAUGHT, which
     * for an early class is its held date — the same value the earnings
     * calculation matches on.
     */
    public function create(Request $request): View
    {
        $instructor = $request->user();

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
        ]);

        $student = $this->authorizedStudent($instructor, (int) $data['student_id']);
        $date = CarbonImmutable::parse($data['date'])->toDateString();

        $report = SessionReport::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->where('class_date', $date)
            ->first();

        return $this->form($instructor->id, $student, $date, $report);
    }

    /**
     * Re-open a filed report.
     *
     * Bound to the report rather than to (student, date) so the page survives
     * everything that can happen to the enrolment afterwards.
     */
    public function edit(Request $request, SessionReport $report): View
    {
        abort_unless($report->instructor_id === $request->user()->id, 403);

        $student = User::query()
            ->withTrashed()
            ->with(['studentProfile', 'schedules'])
            ->findOrFail($report->student_id);

        return $this->form(
            $report->instructor_id,
            $student,
            $report->class_date->toDateString(),
            $report,
        );
    }

    /**
     * Create or update the report for a session.
     *
     * upsert rather than insert: the unique key on
     * (instructor, student, class_date) makes one report per class, and an
     * instructor revisiting the form is editing, not duplicating. The legacy
     * save() inserted unconditionally.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $instructor = $request->user();

        $data = $request->validate(array_merge([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'class_date' => ['required', 'date'],
        ], $this->contentRules()));

        $student = $this->authorizedStudent($instructor, (int) $data['student_id']);
        $date = CarbonImmutable::parse($data['class_date'])->toDateString();

        $report = SessionReport::updateOrCreate(
            [
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'class_date' => $date,
            ],
            $this->attributes($data, $instructor->id, $student->id, $date),
        );

        return $this->saved($request, $report, $student->name);
    }

    /**
     * Save an edit to an already-filed report.
     *
     * The natural key — instructor, student, class_date — is deliberately not
     * writable here: earnings match a report to its session on exactly those
     * three columns, so moving one would silently re-point a payment.
     */
    public function update(Request $request, SessionReport $report): RedirectResponse|JsonResponse
    {
        abort_unless($report->instructor_id === $request->user()->id, 403);

        $data = $request->validate($this->contentRules());

        $date = $report->class_date->toDateString();

        $report->fill($this->attributes($data, $report->instructor_id, $report->student_id, $date))->save();

        $student = User::withTrashed()->find($report->student_id);

        return $this->saved($request, $report, $student?->name ?? 'this student');
    }

    /**
     * JSON lookup used by the class list to show a filed report in a drawer.
     */
    public function show(Request $request, SessionReport $report): JsonResponse
    {
        abort_unless($report->instructor_id === $request->user()->id, 403);

        return response()->json([
            'success' => true,
            'report' => $report->only(array_merge(
                ['id', 'class_date', 'today_lesson', 'next_lesson', 'grammar_section',
                    'pronunciation_section', 'vocab_section', 'teacher_comments'],
                array_keys(SessionReport::SCORE_FIELDS),
            )),
            'average' => $report->averageScore(),
        ]);
    }

    // ---------------------------------------------------------------- internals

    /**
     * The form view, shared by filing and editing.
     */
    private function form(int $instructorId, User $student, string $date, ?SessionReport $report): View
    {
        $profile = $student->studentProfile;

        return view('instructor.reports.create', [
            'student' => $student,
            'profile' => $profile,
            'date' => $date,
            'session' => $this->linkedSession($instructorId, $student->id, $date),
            'report' => $report,
            'previous' => $this->previousReport($instructorId, $student->id, $date),

            // Where the student is in their plan — "5 of 15 taught, 10 left".
            // sessionsPurchased() owns the accounting identity (attended +
            // student-absent + remaining + deducted), so it is not restated here.
            'progress' => array_merge([
                'attended' => (int) ($profile?->sessions_attended ?? 0),
                'purchased' => (int) ($profile?->sessionsPurchased() ?? 0),
                'remaining' => (int) ($profile?->sessions_remaining ?? 0),
            ], $this->attendanceCounts($instructorId, $student->id)),
        ]);
    }

    /**
     * Absences and postponements for this student, split by who is
     * responsible — the breakdown the Copy All text and the summary card
     * both need, so it is asked for once and reused.
     *
     * @return array<string, int>
     */
    private function attendanceCounts(int $instructorId, int $studentId): array
    {
        $counts = ClassSession::query()
            ->selectRaw('
                SUM(status = ? AND absent_by = ?) as student_absent,
                SUM(status = ? AND absent_by = ?) as teacher_absent,
                SUM(status = ? AND postponed_by = ?) as student_postponed,
                SUM(status = ? AND postponed_by = ?) as teacher_postponed
            ', [
                SessionStatus::Absent->value, Party::Student->value,
                SessionStatus::Absent->value, Party::Teacher->value,
                SessionStatus::Postponed->value, Party::Student->value,
                SessionStatus::Postponed->value, Party::Teacher->value,
            ])
            ->where('instructor_id', $instructorId)
            ->where('student_id', $studentId)
            ->first();

        return [
            'student_absent' => (int) ($counts->student_absent ?? 0),
            'teacher_absent' => (int) ($counts->teacher_absent ?? 0),
            'student_postponed' => (int) ($counts->student_postponed ?? 0),
            'teacher_postponed' => (int) ($counts->teacher_postponed ?? 0),
        ];
    }

    /**
     * Validation for the report body. Shared so filing and editing cannot drift
     * apart — a field editable on one and not the other would be silently
     * dropped on save.
     *
     * @return array<string, array<int, string>>
     */
    private function contentRules(): array
    {
        return [
            'today_lesson' => ['nullable', 'string', 'max:5000'],
            'next_lesson' => ['nullable', 'string', 'max:5000'],
            'teacher_comments' => ['nullable', 'string', 'max:5000'],

            // The three correction sections arrive as repeatable rows and are
            // stored as JSON in one TEXT column each — the format legacy wrote
            // and the importer preserved. See SessionReport::ROW_SECTIONS.
            'grammar' => ['nullable', 'array'],
            'grammar.*.yourSentence' => ['nullable', 'string', 'max:1000'],
            'grammar.*.betterSay' => ['nullable', 'string', 'max:1000'],
            'pronunciation' => ['nullable', 'array'],
            'pronunciation.*.word' => ['nullable', 'string', 'max:1000'],
            'pronunciation.*.comment' => ['nullable', 'string', 'max:1000'],
            'vocabulary' => ['nullable', 'array'],
            'vocabulary.*.vocab' => ['nullable', 'string', 'max:1000'],
            'vocabulary.*.example' => ['nullable', 'string', 'max:1000'],

            // The form offers 1-5, the scale legacy used. The ceiling stays at 10
            // so a report saved while this form offered 1-10 can still be edited
            // without its score being rejected.
            'listening_score' => ['nullable', 'integer', 'between:1,10'],
            'speaking_score' => ['nullable', 'integer', 'between:1,10'],
            'pronunciation_score' => ['nullable', 'integer', 'between:1,10'],
            'vocabulary_score' => ['nullable', 'integer', 'between:1,10'],
            'grammar_score' => ['nullable', 'integer', 'between:1,10'],
        ];
    }

    /**
     * The writable columns for a validated payload: the repeatable rows folded
     * back into their one column each, plus the session link.
     *
     * The link is re-resolved on every save rather than only on insert, so a
     * report filed before attendance was marked picks up its session on the
     * next edit instead of staying orphaned.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, int $instructorId, int $studentId, string $date): array
    {
        $sections = [];

        foreach (SessionReport::ROW_SECTIONS as $column => $section) {
            $sections[$column] = SessionReport::encodeRows(
                $data[$section['input']] ?? [],
                $section['fields'],
            );
        }

        return array_merge(
            collect($data)
                ->except(array_merge(
                    ['student_id', 'class_date'],
                    array_column(SessionReport::ROW_SECTIONS, 'input'),
                ))
                ->all(),
            $sections,
            ['class_session_id' => $this->linkedSession($instructorId, $studentId, $date)?->id],
        );
    }

    /**
     * The attendance row this report describes, if it has been marked.
     * Matched on paid_date, which for an early class is its held date.
     */
    private function linkedSession(int $instructorId, int $studentId, string $date): ?ClassSession
    {
        return ClassSession::query()
            ->where('instructor_id', $instructorId)
            ->where('student_id', $studentId)
            ->where('paid_date', $date)
            ->first();
    }

    private function saved(Request $request, SessionReport $report, string $studentName): RedirectResponse|JsonResponse
    {
        $message = "Report saved for {$studentName}.";

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'id' => $report->id]);
        }

        return redirect()
            ->route('instructor.reports.index')
            ->with('success', $message);
    }

    /**
     * The report filed before this one, so "next lesson" from last time is
     * visible while writing "today's lesson".
     */
    private function previousReport(int $instructorId, int $studentId, string $date): ?SessionReport
    {
        return SessionReport::query()
            ->where('instructor_id', $instructorId)
            ->where('student_id', $studentId)
            ->where('class_date', '<', $date)
            ->orderByDesc('class_date')
            ->first();
    }

    private function authorizedStudent(User $instructor, int $studentId): User
    {
        $student = User::query()
            ->with(['studentProfile', 'schedules'])
            ->whereKey($studentId)
            ->whereHas('studentProfile', fn ($q) => $q->where('instructor_id', $instructor->id))
            ->first();

        abort_if($student === null, 403, 'That student is not assigned to you.');

        return $student;
    }
}
