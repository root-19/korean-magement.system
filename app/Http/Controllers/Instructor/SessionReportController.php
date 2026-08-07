<?php

namespace App\Http\Controllers\Instructor;

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
 */
class SessionReportController extends Controller
{
    public function index(Request $request): View
    {
        $instructor = $request->user();

        $reports = SessionReport::query()
            ->with(['student:id,name,avatar_path'])
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

        $session = ClassSession::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->where('paid_date', $date)
            ->first();

        $report = SessionReport::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->where('class_date', $date)
            ->first();

        $profile = $student->studentProfile;

        return view('instructor.reports.create', [
            'student' => $student,
            'profile' => $profile,
            'date' => $date,
            'session' => $session,
            'report' => $report,
            'previous' => $this->previousReport($instructor->id, $student->id, $date),

            // Where the student is in their plan — "5 of 15 taught, 10 left".
            // sessionsPurchased() owns the accounting identity (attended +
            // student-absent + remaining + deducted), so it is not restated here.
            'progress' => [
                'attended' => (int) ($profile?->sessions_attended ?? 0),
                'purchased' => (int) ($profile?->sessionsPurchased() ?? 0),
                'remaining' => (int) ($profile?->sessions_remaining ?? 0),
            ],
        ]);
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

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'class_date' => ['required', 'date'],
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
        ]);

        $student = $this->authorizedStudent($instructor, (int) $data['student_id']);
        $date = CarbonImmutable::parse($data['class_date'])->toDateString();

        // Link the report to its session where one exists, so the relation is
        // available going forward even though earnings match on the natural key.
        $session = ClassSession::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->where('paid_date', $date)
            ->first();

        // Fold the repeatable rows back into their one column each.
        $sections = [];

        foreach (SessionReport::ROW_SECTIONS as $column => $section) {
            $sections[$column] = SessionReport::encodeRows(
                $data[$section['input']] ?? [],
                $section['fields'],
            );
        }

        $report = SessionReport::updateOrCreate(
            [
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'class_date' => $date,
            ],
            array_merge(
                collect($data)
                    ->except(array_merge(
                        ['student_id', 'class_date'],
                        array_column(SessionReport::ROW_SECTIONS, 'input'),
                    ))
                    ->all(),
                $sections,
                ['class_session_id' => $session?->id],
            ),
        );

        $message = "Report saved for {$student->name}.";

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'id' => $report->id]);
        }

        return redirect()
            ->route('instructor.reports.index')
            ->with('success', $message);
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
            ->with('studentProfile')
            ->whereKey($studentId)
            ->whereHas('studentProfile', fn ($q) => $q->where('instructor_id', $instructor->id))
            ->first();

        abort_if($student === null, 403, 'That student is not assigned to you.');

        return $student;
    }
}
