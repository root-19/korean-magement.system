<?php

namespace App\Console\Commands;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pays out the make-good class for teacher absences marked before the rule
 * existed.
 *
 *     php artisan sessions:credit-teacher-absences            # dry run
 *     php artisan sessions:credit-teacher-absences --apply
 *     php artisan sessions:credit-teacher-absences --student=754
 *     php artisan sessions:credit-teacher-absences --include-archived
 *
 * A class the teacher missed adds one to the student's plan — legacy did this
 * with `semester = semester + 1` and the rewrite lost it (see
 * AttendanceService::creditsSession). Every teacher absence recorded between the
 * cutover and that fix is therefore short by one class, and no amount of
 * re-marking will find it: the counters only ever move by the DELTA of a status
 * change, so a row that is already absent-by-teacher will never be credited by
 * being saved again.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply.
 *
 * Archived students are left out unless --include-archived is passed. Most of
 * the backlog belongs to them — the absences go back months and the students
 * have since finished and been archived — and handing a class to someone who
 * has left the school puts a balance on a leaver rather than righting anything.
 * The flag is there because that is a decision for whoever runs this, not for
 * the command.
 *
 * Runs at most once per student. Each credit writes a
 * `student.teacher_absence_credited` audit entry naming the sessions it paid
 * for, and a student who already has one is skipped — so the command is safe to
 * re-run, and a later absence marked through the fixed service is not credited
 * twice.
 */
class CreditTeacherAbsences extends Command
{
    protected $signature = 'sessions:credit-teacher-absences
                            {--apply : Write the credits. Without this the command only reports}
                            {--student= : Restrict to one student, by id or name fragment}
                            {--include-archived : Also credit students who have been archived}';

    protected $description = 'Add the missing make-good class for teacher absences recorded before the rule existed';

    public const AUDIT_ACTION = 'student.teacher_absence_credited';

    public function handle(): int
    {
        $pending = $this->pending();

        if ($pending->isEmpty()) {
            $this->info('✓ Every teacher absence has had its make-good class credited.');

            return self::SUCCESS;
        }

        $this->reportArchived();

        $this->renderTable($pending);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment(sprintf(
                'Dry run — nothing written. %d %s would gain %d %s in total.',
                $pending->count(),
                $pending->count() === 1 ? 'student' : 'students',
                $pending->sum('credit'),
                $pending->sum('credit') === 1 ? 'class' : 'classes',
            ));
            $this->comment('Re-run with --apply to write them.');

            return self::SUCCESS;
        }

        $this->apply($pending);

        return self::SUCCESS;
    }

    /**
     * Students holding an uncredited teacher absence, with what they are owed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pending(): Collection
    {
        // One entry means the whole of that student's backlog was settled, so
        // the check is per student rather than per session. Sessions marked
        // after the fix need no entry — the service credited them as they were
        // saved — which is exactly why a second run must not touch anyone who
        // already has one.
        $credited = AuditLog::query()
            ->where('action', self::AUDIT_ACTION)
            ->pluck('auditable_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $absences = DB::table('class_sessions as cs')
            ->join('users as u', 'u.id', '=', 'cs.student_id')
            ->join('student_profiles as sp', 'sp.user_id', '=', 'cs.student_id')
            ->leftJoin('users as t', 't.id', '=', 'sp.instructor_id')
            ->where('cs.status', SessionStatus::Absent->value)
            ->where('cs.absent_by', Party::Teacher->value)
            ->whereNotIn('cs.student_id', $credited)
            // A raw builder gets no soft-delete scope, so the archived have to
            // be excluded by hand.
            ->when(! $this->option('include-archived'), fn ($q) => $q->whereNull('u.deleted_at'))
            ->when($this->option('student'), fn ($q, $needle) => $q->where(
                fn ($w) => $w->where('u.id', (int) $needle)->orWhere('u.name', 'like', '%'.$needle.'%'),
            ))
            ->groupBy('cs.student_id', 'u.name', 't.name', 'sp.sessions_remaining')
            ->orderBy('t.name')
            ->orderBy('u.name')
            ->get([
                'cs.student_id',
                'u.name as student_name',
                't.name as instructor_name',
                'sp.sessions_remaining',
                DB::raw('COUNT(*) as absences'),
                DB::raw('GROUP_CONCAT(cs.scheduled_date ORDER BY cs.scheduled_date) as dates'),
            ]);

        return $absences->map(fn (object $row) => [
            'student_id' => (int) $row->student_id,
            'student' => (string) $row->student_name,
            'instructor' => $row->instructor_name ?? '(unassigned)',
            'remaining' => (int) $row->sessions_remaining,
            'credit' => (int) $row->absences,
            'dates' => explode(',', (string) $row->dates),
        ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pending
     */
    private function apply(Collection $pending): void
    {
        $credited = 0;

        foreach ($pending as $row) {
            // One transaction per student rather than one for the lot: a profile
            // that has gone missing between the report and the write should cost
            // that student their credit, not everyone else's.
            DB::transaction(function () use ($row, &$credited) {
                $profile = StudentProfile::query()
                    ->where('user_id', $row['student_id'])
                    ->lockForUpdate()
                    ->first();

                if ($profile === null) {
                    $this->warn(sprintf('  skipped %s — no profile', $row['student']));

                    return;
                }

                $profile->sessions_remaining += $row['credit'];
                $profile->save();

                AuditLog::record(
                    action: self::AUDIT_ACTION,
                    // withTrashed: with --include-archived the subject is a
                    // soft-deleted user, and find() would return null, leaving
                    // the entry with nothing to point at — and nothing to stop a
                    // second run crediting them again.
                    subject: User::withTrashed()->find($row['student_id']),
                    targetName: $row['student'],
                    details: [
                        'credited' => $row['credit'],
                        'sessions_remaining_before' => $row['remaining'],
                        'sessions_remaining_after' => $profile->sessions_remaining,
                        'absence_dates' => $row['dates'],
                    ],
                );

                $credited++;
            });
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ Credited %d %s across %d %s.',
            $pending->sum('credit'),
            $pending->sum('credit') === 1 ? 'class' : 'classes',
            $credited,
            $credited === 1 ? 'student' : 'students',
        ));
    }

    /**
     * Say how much of the backlog is being held back, so a run that skips most
     * of it does not look like the whole story.
     */
    private function reportArchived(): void
    {
        if ($this->option('include-archived')) {
            return;
        }

        $held = DB::table('class_sessions as cs')
            ->join('users as u', 'u.id', '=', 'cs.student_id')
            ->where('cs.status', SessionStatus::Absent->value)
            ->where('cs.absent_by', Party::Teacher->value)
            ->whereNotNull('u.deleted_at')
            ->count();

        if ($held === 0) {
            return;
        }

        $this->newLine();
        $this->comment(sprintf(
            '%d further %s %s to archived students and %s not listed. Pass',
            $held,
            $held === 1 ? 'absence' : 'absences',
            $held === 1 ? 'belongs' : 'belong',
            $held === 1 ? 'is' : 'are',
        ));
        $this->comment('--include-archived to credit those too.');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pending
     */
    private function renderTable(Collection $pending): void
    {
        $this->newLine();
        $this->table(
            ['Instructor', 'Student', 'Absences', 'Remaining', 'Becomes', 'Dates'],
            $pending->map(fn (array $row) => [
                mb_substr((string) $row['instructor'], 0, 20),
                mb_substr($row['student'], 0, 22),
                $row['credit'],
                $row['remaining'],
                $row['remaining'] + $row['credit'],
                implode(', ', $row['dates']),
            ])->all(),
        );
    }
}
