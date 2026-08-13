<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentDeletionRequest;
use App\Services\Enrollment\StudentDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The deletion queue: instructors asking for a student to be removed.
 *
 * Approving one removes the student from the whole application. The classes
 * taught to them are kept — deleting those would restate the instructor's pay —
 * so each row shows what the deletion carries with it before anyone decides.
 */
class StudentDeletionController extends Controller
{
    public function __construct(private readonly StudentDeletionService $deletions) {}

    public function index(Request $request): View
    {
        $filter = $request->query('status', 'pending');

        $requests = StudentDeletionRequest::query()
            ->with([
                'instructor:id,name',
                // withTrashed: an approved row's student is deleted by
                // definition, and the queue still has to name them.
                'student' => fn ($q) => $q->withTrashed()
                    ->select('id', 'name', 'email', 'avatar_path', 'deleted_at'),
                'student.studentProfile',
                'decidedBy:id,name',
            ])
            ->when(
                in_array($filter, [
                    StudentDeletionRequest::PENDING,
                    StudentDeletionRequest::APPROVED,
                    StudentDeletionRequest::REJECTED,
                ], true),
                fn ($q) => $q->where('status', $filter),
            )
            // Oldest pending first: an instructor is waiting on the answer.
            ->orderByRaw("FIELD(status, 'pending') DESC")
            ->orderBy('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.deletions.index', [
            'requests' => $requests,
            'filter' => $filter,
            'pendingCount' => StudentDeletionRequest::query()->pending()->count(),
            'records' => $this->deletions->recordsFor($requests->pluck('student')->filter()->values()),
        ]);
    }

    public function decide(Request $request, StudentDeletionRequest $deletion): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user();
        $note = $data['decision_note'] ?? null;
        $name = $deletion->student_name;

        if ($data['decision'] === StudentDeletionRequest::APPROVED) {
            $this->deletions->approve($admin, $deletion, $note);

            return back()->with('success', "{$name} deleted. Instructor payouts are unchanged — the classes taught to them are kept.");
        }

        $this->deletions->reject($admin, $deletion, $note);

        return back()->with('success', "Delete request for {$name} rejected. Nothing was removed.");
    }
}
