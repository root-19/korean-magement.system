<?php

namespace App\Services\Enrollment;

use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentDeletionRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deleting a student, as a request and a decision rather than a button.
 *
 * An instructor may ask; only an admin may carry it out.
 *
 * WHY THE ROW SURVIVES
 * --------------------
 * An approved deletion removes the student from the application completely:
 * every instructor list, the roster, the class lists, and login. What it does
 * not do is drop the `users` row, because `class_sessions.student_id` and
 * `session_reports.student_id` are both ON DELETE CASCADE — dropping it would
 * take every class taught to that student and every report filed for them, and
 * those rows ARE the instructor's payout. EarningsCalculator joins `users` with
 * a raw join precisely so a deleted student still prices and still pays.
 *
 * So the deletion is recorded on the row (deleted_at, deleted_by, is_active) and
 * the payslip is untouched. This is the trade the schema was designed around;
 * the legacy code did the opposite and had to keep snapshot columns and negative
 * ids to put the earnings back together afterwards.
 */
class StudentDeletionService
{
    /**
     * Ask for a student to be deleted.
     *
     * Idempotent on the student: asking again after a rejection reuses the row
     * and puts it back to pending, so the decision history stays in one place.
     */
    public function request(User $instructor, User $student, string $reason): StudentDeletionRequest
    {
        $existing = StudentDeletionRequest::query()
            ->forInstructor($instructor->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing?->isPending()) {
            throw ValidationException::withMessages([
                'reason' => "A delete request for {$student->name} is already waiting for an admin.",
            ]);
        }

        $request = StudentDeletionRequest::updateOrCreate(
            [
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
            ],
            [
                'student_name' => $student->name,
                'reason' => $reason,
                'status' => StudentDeletionRequest::PENDING,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ],
        );

        AuditLog::record(
            action: 'student.deletion_requested',
            subject: $student,
            targetName: $student->name,
            details: ['instructor_id' => $instructor->id, 'reason' => $reason],
            userId: $instructor->id,
        );

        return $request;
    }

    /**
     * Approve a request and delete the student.
     *
     * Deactivated and soft-deleted in one step, so the student is gone from every
     * list, roster and login — see the class comment for why the row itself
     * stays. Payroll reads the row through a raw join and is unaffected.
     */
    public function approve(User $admin, StudentDeletionRequest $request, ?string $note = null): StudentDeletionRequest
    {
        return DB::transaction(function () use ($admin, $request, $note) {
            $request->refresh();

            $this->assertPending($request);

            $student = User::query()->with('studentProfile')->find($request->student_id);

            if ($student === null) {
                throw ValidationException::withMessages([
                    'decision' => "{$request->student_name} no longer exists.",
                ]);
            }

            $request->update([
                'status' => StudentDeletionRequest::APPROVED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $this->remove($admin, $student, $request->student_name, [
                'instructor_id' => $request->instructor_id,
                'reason' => $request->reason,
                'decision_note' => $note,
            ]);

            return $request->refresh();
        });
    }

    /**
     * Delete a student outright, with no request to approve.
     *
     * The queue exists for what an INSTRUCTOR asks for; an admin is already the
     * approver, so making them file a request against themselves would only add
     * a round trip. The outcome is identical to approving one — see remove().
     *
     * A pending request for the same student is settled by the same action, so
     * the queue does not go on asking for something already done.
     */
    public function delete(User $admin, User $student, ?string $reason = null): User
    {
        return DB::transaction(function () use ($admin, $student, $reason) {
            if ($student->trashed()) {
                throw ValidationException::withMessages([
                    'student' => "{$student->name} is already deleted.",
                ]);
            }

            $student->loadMissing('studentProfile');

            StudentDeletionRequest::query()
                ->where('student_id', $student->id)
                ->pending()
                ->update([
                    'status' => StudentDeletionRequest::APPROVED,
                    'decided_by' => $admin->id,
                    'decided_at' => now(),
                    'decision_note' => $reason,
                ]);

            $this->remove($admin, $student, $student->name, [
                'reason' => $reason,
                'requested' => false,
            ]);

            return $student;
        });
    }

    /**
     * Refuse a request. The student is untouched.
     */
    public function reject(User $admin, StudentDeletionRequest $request, ?string $note = null): StudentDeletionRequest
    {
        return DB::transaction(function () use ($admin, $request, $note) {
            $request->refresh();

            $this->assertPending($request);

            $request->update([
                'status' => StudentDeletionRequest::REJECTED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            AuditLog::record(
                action: 'student.deletion_rejected',
                subject: $request->student,
                targetName: $request->student_name,
                details: [
                    'instructor_id' => $request->instructor_id,
                    'reason' => $request->reason,
                    'decision_note' => $note,
                ],
                userId: $admin->id,
            );

            return $request;
        });
    }

    /**
     * The payroll record attached to each of these students.
     *
     * Shown in the queue so the admin can see what the deletion is carrying —
     * these rows survive it, and the instructor keeps being paid for them.
     *
     * Two grouped queries for the whole page rather than two per row, so the
     * queue costs the same whether it holds one request or twenty-five.
     *
     * `earnings` is the pay recorded against those classes at the student's
     * current rate: an indication of scale, not a payslip. EarningsCalculator
     * applies the report and deduction rules on top.
     *
     * @param  Collection<int, User>  $students  each with `studentProfile` loaded
     * @return array<int, array{sessions: int, payable: int, reports: int, earnings: float}>
     */
    public function recordsFor(Collection $students): array
    {
        $ids = $students->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $sessions = ClassSession::query()
            ->selectRaw('student_id, COUNT(*) as sessions, SUM(status IN (?, ?)) as payable', SessionStatus::payableValues())
            ->whereIn('student_id', $ids)
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $reports = SessionReport::query()
            ->selectRaw('student_id, COUNT(*) as reports')
            ->whereIn('student_id', $ids)
            ->groupBy('student_id')
            ->pluck('reports', 'student_id');

        return $students
            ->mapWithKeys(function (User $student) use ($sessions, $reports) {
                $row = $sessions->get($student->id);
                $payable = (int) ($row->payable ?? 0);

                return [$student->id => [
                    'sessions' => (int) ($row->sessions ?? 0),
                    'payable' => $payable,
                    'reports' => (int) ($reports[$student->id] ?? 0),
                    'earnings' => round($payable * ($student->studentProfile?->sessionValue() ?? 0), 2),
                ]];
            })
            ->all();
    }

    /**
     * Take the student out of the application.
     *
     * Deactivated and soft-deleted in one step, so they are gone from every
     * list, roster and login — see the class comment for why the row itself
     * stays. Payroll reads that row through a raw join and is unaffected.
     *
     * $targetName is passed in because an approval names the student as they
     * were when the request was filed, not as they are now.
     *
     * @param  array<string, mixed>  $details  what the audit entry should carry
     */
    private function remove(User $admin, User $student, string $targetName, array $details): void
    {
        AuditLog::record(
            action: 'student.deleted',
            subject: $student,
            targetName: $targetName,
            details: $details + ['preserved' => $this->recordsFor(collect([$student]))[$student->id]],
            userId: $admin->id,
        );

        // `deleted_by` is not fillable — it is written here and nowhere else, so
        // a deletion can always be traced to the admin who carried it out.
        $student->is_active = false;
        $student->deleted_by = $admin->id;
        $student->save();

        $student->delete();
    }

    private function assertPending(StudentDeletionRequest $request): void
    {
        if ($request->isPending()) {
            return;
        }

        throw ValidationException::withMessages([
            'decision' => "That request was already {$request->status}.",
        ]);
    }
}
