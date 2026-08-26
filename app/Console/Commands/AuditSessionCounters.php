<?php

namespace App\Console\Commands;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds students whose session counters disagree with their class history.
 *
 *     php artisan sessions:audit-counters
 *     php artisan sessions:audit-counters --instructor=Grace --show-unverifiable
 *     php artisan sessions:audit-counters --csv=storage/app/counter-audit.csv
 *
 * WRITES NOTHING. It reports, per instructor, what `sessions_attended` and
 * `sessions_remaining` should be and by how much they are out, so the numbers
 * can be corrected deliberately rather than guessed at.
 *
 * The two counters are checkable to very different degrees, and the report says
 * which basis it used for each row:
 *
 *   sessions_attended is derivable outright. It is the number of class_sessions
 *   rows marked present, nothing else.
 *
 *   sessions_remaining is NOT, because the purchased total is never stored — it
 *   is derived back out of the counters (StudentProfile::sessionsPurchased), so
 *   a wrong `remaining` silently drags the apparent purchase down with it and
 *   the identity still balances. The only independent record of what a student
 *   bought is the `student.enrolled` audit entry, which keeps
 *   `sessions_purchased` and `sessions_deducted` as they were at enrolment:
 *
 *       expected_remaining = purchased - deducted - (present + student-absent)
 *
 * A student is therefore only reported as verifiable when that anchor exists and
 * still applies. Two things retire it, and both are listed separately by
 * --show-unverifiable rather than being quietly counted as agreeing:
 *
 *   - no enrolment entry at all, because the student came across in the legacy
 *     import and their purchase predates the audit log;
 *   - an admin has edited the counters since (`student.updated`), which makes
 *     the edited figure the intended truth and the enrolment anchor stale.
 *
 * Written for the double-deduction in AttendanceService::mark(), which lost the
 * row's previous `absent_by` before working out the counter delta: correcting a
 * student-absence to present took a second session off `remaining`, and
 * correcting one to teacher-absent never gave the session back. The code is
 * fixed, but nothing replayed the damage it had already done.
 */
class AuditSessionCounters extends Command
{
    protected $signature = 'sessions:audit-counters
                            {--instructor= : Restrict to one instructor, by id or name fragment}
                            {--student= : Restrict to one student, by id or name fragment}
                            {--include-inactive : Also check deactivated and unapproved students}
                            {--show-ok : List students whose counters agree, too}
                            {--show-unverifiable : List students with no usable purchase anchor}
                            {--csv= : Also write every checked student to this path}';

    protected $description = 'Report students whose session counters disagree with class_sessions (read-only)';

    /** How many rows of each kind to print before summarising the rest. */
    private const MAX_ROWS = 60;

    public function handle(): int
    {
        $profiles = $this->profiles();

        if ($profiles->isEmpty()) {
            $this->error('No students matched those filters.');

            return self::FAILURE;
        }

        $history = $this->sessionCounts($profiles->pluck('user_id')->all());
        $anchors = $this->purchaseAnchors($profiles->pluck('user_id')->all());

        $rows = $profiles
            ->map(fn (object $profile) => $this->check(
                $profile,
                $history->get($profile->user_id),
                $anchors->get($profile->user_id),
            ))
            ->values();

        $this->report($rows);

        if ($path = $this->option('csv')) {
            $this->writeCsv($rows, $path);
        }

        return $rows->contains(fn (array $row) => $row['remaining_delta'] !== null && $row['remaining_delta'] !== 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    // ------------------------------------------------------------------ gathering

    /**
     * @return Collection<int, object>
     */
    private function profiles(): Collection
    {
        return DB::table('student_profiles as sp')
            ->join('users as u', 'u.id', '=', 'sp.user_id')
            ->leftJoin('users as t', 't.id', '=', 'sp.instructor_id')
            ->whereNull('u.deleted_at')
            ->when(! $this->option('include-inactive'), fn ($q) => $q
                ->where('u.is_active', true)
                ->where('sp.enrollment_status', EnrollmentStatus::Approved->value))
            ->when($this->option('instructor'), fn ($q, $needle) => $q->where(
                fn ($w) => $w->where('t.id', (int) $needle)->orWhere('t.name', 'like', '%'.$needle.'%'),
            ))
            ->when($this->option('student'), fn ($q, $needle) => $q->where(
                fn ($w) => $w->where('u.id', (int) $needle)->orWhere('u.name', 'like', '%'.$needle.'%'),
            ))
            ->orderBy('t.name')
            ->orderBy('u.name')
            ->get([
                'sp.user_id',
                'sp.sessions_remaining',
                'sp.sessions_attended',
                'sp.sessions_deducted',
                'u.name as student_name',
                't.name as instructor_name',
            ])
            ->map(function (object $row) {
                $row->user_id = (int) $row->user_id;

                return $row;
            })
            ->keyBy('user_id');
    }

    /**
     * Present and student-absent counts per student, in one pass.
     *
     * Not scoped to an instructor: the counters belong to the student, so a class
     * taught by whoever had them before still spent one of their sessions.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, object>
     */
    private function sessionCounts(array $studentIds): Collection
    {
        return DB::table('class_sessions')
            ->whereIn('student_id', $studentIds)
            ->selectRaw('student_id')
            ->selectRaw('SUM(status = ?) as present', [SessionStatus::Present->value])
            ->selectRaw(
                'SUM(status = ? AND absent_by = ?) as student_absent',
                [SessionStatus::Absent->value, Party::Student->value],
            )
            ->groupBy('student_id')
            ->get()
            ->map(function (object $row) {
                $row->student_id = (int) $row->student_id;
                $row->present = (int) $row->present;
                $row->student_absent = (int) $row->student_absent;

                return $row;
            })
            ->keyBy('student_id');
    }

    /**
     * What each student bought, and whether that figure still stands.
     *
     * `student.enrolled` carries the purchase. A later `student.updated` that
     * touched any counter supersedes it — an admin correcting the numbers by hand
     * is stating the intended truth, and re-deriving over the top of that would
     * report their correction as the error.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, array{purchased: int, deducted: int, edited_at: ?string}>
     */
    private function purchaseAnchors(array $studentIds): Collection
    {
        $entries = DB::table('audit_logs')
            ->where('auditable_type', (new User)->getMorphClass())
            ->whereIn('auditable_id', $studentIds)
            ->whereIn('action', ['student.enrolled', 'student.updated'])
            ->orderBy('id')
            ->get(['auditable_id', 'action', 'details', 'created_at']);

        $anchors = [];

        foreach ($entries as $entry) {
            $studentId = (int) $entry->auditable_id;
            $details = json_decode((string) $entry->details, true) ?: [];

            if ($entry->action === 'student.enrolled') {
                // A re-enrolment replaces the purchase, and clears any edit that
                // was made against the enrolment before it.
                $anchors[$studentId] = [
                    'purchased' => (int) ($details['sessions_purchased'] ?? 0),
                    'deducted' => (int) ($details['sessions_deducted'] ?? 0),
                    'edited_at' => null,
                ];

                continue;
            }

            $touchedCounters = array_intersect_key($details, array_flip([
                'sessions_remaining',
                'sessions_attended',
                'sessions_deducted',
            ]));

            if ($touchedCounters !== [] && isset($anchors[$studentId])) {
                $anchors[$studentId]['edited_at'] = (string) $entry->created_at;
            }
        }

        return collect($anchors);
    }

    // ----------------------------------------------------------------- checking

    /**
     * Compare one student's counters against their class history.
     *
     * @param  array{purchased: int, deducted: int, edited_at: ?string}|null  $anchor
     * @return array<string, mixed>
     */
    private function check(object $profile, ?object $history, ?array $anchor): array
    {
        $present = (int) ($history->present ?? 0);
        $studentAbsent = (int) ($history->student_absent ?? 0);
        $consumed = $present + $studentAbsent;

        $attended = (int) $profile->sessions_attended;
        $remaining = (int) $profile->sessions_remaining;
        $deducted = (int) $profile->sessions_deducted;

        $row = [
            'instructor' => $profile->instructor_name ?? '(unassigned)',
            'student' => $profile->student_name,
            'student_id' => $profile->user_id,
            'present' => $present,
            'student_absent' => $studentAbsent,
            'attended' => $attended,
            'attended_expected' => $present,
            'attended_delta' => $present - $attended,
            'remaining' => $remaining,
            'remaining_expected' => null,
            'remaining_delta' => null,
            'basis' => null,
        ];

        if ($anchor === null) {
            $row['basis'] = 'no enrolment record';

            return $row;
        }

        if ($anchor['edited_at'] !== null) {
            $row['basis'] = 'counters edited '.substr($anchor['edited_at'], 0, 10);

            return $row;
        }

        // The write-off is part of the anchor. If it has moved since enrolment
        // without a logged counter edit, the purchase no longer reconciles and
        // saying otherwise would be worse than saying nothing.
        if ($deducted !== $anchor['deducted']) {
            $row['basis'] = 'deducted moved off enrolment';

            return $row;
        }

        $expected = $anchor['purchased'] - $anchor['deducted'] - $consumed;

        $row['remaining_expected'] = $expected;
        $row['remaining_delta'] = $expected - $remaining;
        $row['basis'] = 'enrolled with '.$anchor['purchased'];

        return $row;
    }

    // ------------------------------------------------------------------- output

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function report(Collection $rows): void
    {
        $verifiable = $rows->filter(fn (array $r) => $r['remaining_delta'] !== null);
        $shortChanged = $verifiable->filter(fn (array $r) => $r['remaining_delta'] > 0);
        $overCredited = $verifiable->filter(fn (array $r) => $r['remaining_delta'] < 0);
        $attendedOnly = $rows->filter(
            fn (array $r) => $r['attended_delta'] !== 0 && ($r['remaining_delta'] ?? 0) === 0,
        );

        $this->newLine();
        $this->line(sprintf('  students checked ......... %s', number_format($rows->count())));
        $this->line(sprintf('  remaining verifiable ..... %s', number_format($verifiable->count())));
        $this->line(sprintf('  remaining is SHORT ....... %s', number_format($shortChanged->count())));
        $this->line(sprintf('  remaining is over ........ %s', number_format($overCredited->count())));
        $this->line(sprintf('  attended off only ........ %s', number_format($attendedOnly->count())));
        $this->newLine();

        // Short first, and on its own: this is the reported problem. The student
        // has paid for sessions the system has already taken off them.
        if ($shortChanged->isNotEmpty()) {
            $this->error(sprintf(
                '%d %s SHORT on remaining sessions:',
                $shortChanged->count(),
                Str::plural('student', $shortChanged->count()).($shortChanged->count() === 1 ? ' is' : ' are'),
            ));
            $this->renderTable($shortChanged);
        }

        if ($overCredited->isNotEmpty()) {
            $this->warn(sprintf(
                '%d %s more remaining than their history allows:',
                $overCredited->count(),
                Str::plural('student', $overCredited->count()).($overCredited->count() === 1 ? ' has' : ' have'),
            ));
            $this->renderTable($overCredited);
        }

        if ($attendedOnly->isNotEmpty()) {
            $this->warn(sprintf(
                '%d %s the right remaining count but a wrong attended count:',
                $attendedOnly->count(),
                Str::plural('student', $attendedOnly->count()).($attendedOnly->count() === 1 ? ' has' : ' have'),
            ));
            $this->renderTable($attendedOnly);
        }

        if ($this->option('show-unverifiable')) {
            $unverifiable = $rows->filter(fn (array $r) => $r['remaining_delta'] === null);

            if ($unverifiable->isNotEmpty()) {
                $this->newLine();
                $this->line(sprintf(
                    '%d %s no usable purchase anchor, so `remaining` cannot be',
                    $unverifiable->count(),
                    Str::plural('student', $unverifiable->count()).($unverifiable->count() === 1 ? ' has' : ' have'),
                ));
                $this->line('derived. Their attended count is still checked.');
                $this->renderTable($unverifiable);
            }
        }

        if ($this->option('show-ok')) {
            $ok = $rows->filter(
                fn (array $r) => $r['attended_delta'] === 0 && ($r['remaining_delta'] ?? 0) === 0,
            );

            if ($ok->isNotEmpty()) {
                $this->newLine();
                $this->info(sprintf('%d %s agree:', $ok->count(), Str::plural('student', $ok->count())));
                $this->renderTable($ok);
            }
        }

        if ($shortChanged->isEmpty() && $overCredited->isEmpty() && $attendedOnly->isEmpty()) {
            $this->info('✓ Every checkable counter agrees with class_sessions.');
        } else {
            $this->newLine();
            $this->line('Nothing here has been changed. `delta` is what to ADD to the stored');
            $this->line('figure to reach the expected one.');
        }

        if (! $this->option('show-unverifiable') && $verifiable->count() < $rows->count()) {
            $this->newLine();
            $this->comment(sprintf(
                '%d %s not checked for `remaining` — run with --show-unverifiable to see why.',
                $rows->count() - $verifiable->count(),
                Str::plural('student', $rows->count() - $verifiable->count())
                    .($rows->count() - $verifiable->count() === 1 ? ' was' : ' were'),
            ));
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function renderTable(Collection $rows): void
    {
        $shown = $rows->take(self::MAX_ROWS);

        $this->table(
            ['Instructor', 'Student', 'Att.', 'Exp.', 'Rem.', 'Exp.', 'Delta', 'Basis'],
            $shown->map(fn (array $row) => [
                mb_substr((string) $row['instructor'], 0, 20),
                mb_substr((string) $row['student'], 0, 22),
                $row['attended'],
                $row['attended_expected'],
                $row['remaining'],
                $row['remaining_expected'] ?? '—',
                $row['remaining_delta'] === null
                    ? '—'
                    : ($row['remaining_delta'] > 0 ? '+' : '').$row['remaining_delta'],
                $row['basis'],
            ])->all(),
        );

        if ($rows->count() > self::MAX_ROWS) {
            $this->comment(sprintf(
                '... and %d more. Use --csv to get the full list.',
                $rows->count() - self::MAX_ROWS,
            ));
        }
    }

    /**
     * Every checked student, so the figures can be worked through in a sheet.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function writeCsv(Collection $rows, string $path): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error('Could not write to '.$path);

            return;
        }

        fputcsv($handle, [
            'instructor', 'student', 'student_id',
            'present_rows', 'student_absent_rows',
            'attended', 'attended_expected', 'attended_delta',
            'remaining', 'remaining_expected', 'remaining_delta',
            'basis',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['instructor'], $row['student'], $row['student_id'],
                $row['present'], $row['student_absent'],
                $row['attended'], $row['attended_expected'], $row['attended_delta'],
                $row['remaining'], $row['remaining_expected'] ?? '', $row['remaining_delta'] ?? '',
                $row['basis'],
            ]);
        }

        fclose($handle);

        $this->newLine();
        $this->info('Full report written to '.$path);
    }
}
