<?php

namespace App\Console\Commands;

use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the new earnings engine agrees with the legacy one.
 *
 * Runs the ORIGINAL legacy SQL against the legacy database and the new
 * EarningsCalculator against the imported database, for every instructor across
 * a range of payout weeks, and reports any week where the two disagree.
 *
 *     php artisan legacy:verify-earnings
 *     php artisan legacy:verify-earnings --weeks=52 --tolerance=0.01
 *
 * This is the check that makes the migration trustworthy: the schema, the
 * queries and the rounding all changed, so the only thing worth asserting is
 * that the number an instructor gets paid did not.
 */
class VerifyLegacyEarnings extends Command
{
    protected $signature = 'legacy:verify-earnings
                            {--weeks=26 : How many payout weeks back to compare}
                            {--tolerance=0.01 : Acceptable absolute difference in pesos}
                            {--instructor= : Restrict to one legacy instructor id}
                            {--include-current : Also compare the in-progress week (see below)}
                            {--show-all : List matching weeks too, not just mismatches}';

    protected $description = 'Compare new earnings figures against the legacy SQL, week by week';

    private const AUDIO_RATE = 190;

    private const VIDEO_KIDS_RATE = 220;

    private const VIDEO_ADULT_RATE = 210;

    public function handle(EarningsCalculator $calculator): int
    {
        $legacy = DB::connection('legacy');

        try {
            $legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        $weeks = max(1, (int) $this->option('weeks'));
        $tolerance = (float) $this->option('tolerance');

        // Compare only instructors that exist on both sides, matched on legacy_id.
        $instructors = DB::table('users')
            ->whereNotNull('legacy_id')
            ->where('role', 'instructor')
            ->when($this->option('instructor'), fn ($q, $id) => $q->where('legacy_id', (int) $id))
            ->orderBy('legacy_id')
            ->get(['id', 'legacy_id', 'name']);

        if ($instructors->isEmpty()) {
            $this->error('No imported instructors found. Run `php artisan legacy:import` first.');

            return self::FAILURE;
        }

        /*
         * The current week is excluded by default.
         *
         * The legacy figure is read LIVE from the source database while the new
         * figure comes from an imported snapshot, so any attendance marked since
         * the import shows up as a difference. That is staleness, not a defect,
         * and on a live system the in-progress week never settles — it would
         * report a false mismatch every run and train everyone to ignore the
         * output. Completed weeks are stable and are what actually gets paid.
         */
        $windows = collect(PayoutWindow::current()->recent($weeks + 1))
            ->reject(fn (PayoutWindow $w) => ! $this->option('include-current') && $w->isCurrent())
            ->take($weeks)
            ->values()
            ->all();

        if ($this->option('include-current')) {
            $this->warn('Including the in-progress week: differences there usually just mean');
            $this->warn('production has moved on since the last `legacy:import`.');
            $this->newLine();
        }

        $this->info(sprintf(
            'Comparing %d instructors × %d weeks (%s .. %s), tolerance ₱%s',
            $instructors->count(),
            $weeks,
            end($windows)->startDate(),
            $windows[0]->endDate(),
            $tolerance,
        ));
        $this->newLine();

        $compared = 0;
        $mismatches = [];
        $legacyTotal = 0.0;
        $newTotal = 0.0;
        $nonZeroWeeks = 0;

        $bar = $this->output->createProgressBar($instructors->count());
        $bar->start();

        foreach ($instructors as $instructor) {
            foreach ($windows as $window) {
                $legacyAmount = $this->legacyGross(
                    $legacy,
                    (int) $instructor->legacy_id,
                    $window->startDate(),
                    $window->endDate(),
                );

                $newAmount = $calculator
                    ->forWindow((int) $instructor->id, $window)
                    ->gross();

                $compared++;
                $legacyTotal += $legacyAmount;
                $newTotal += $newAmount;

                if ($legacyAmount > 0 || $newAmount > 0) {
                    $nonZeroWeeks++;
                }

                $delta = round($newAmount - $legacyAmount, 2);

                if (abs($delta) > $tolerance) {
                    $mismatches[] = [
                        $instructor->name,
                        $window->label(),
                        number_format($legacyAmount, 2),
                        number_format($newAmount, 2),
                        ($delta > 0 ? '+' : '').number_format($delta, 2),
                    ];
                } elseif ($this->option('show-all') && $legacyAmount > 0) {
                    $this->line(sprintf(
                        '  ok  %-22s %-24s ₱%s',
                        mb_substr($instructor->name, 0, 22),
                        $window->label(),
                        number_format($newAmount, 2),
                    ));
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf('  weeks compared ....... %s', number_format($compared)));
        $this->line(sprintf('  weeks with earnings .. %s', number_format($nonZeroWeeks)));
        $this->line(sprintf('  legacy gross total ... ₱%s', number_format($legacyTotal, 2)));
        $this->line(sprintf('  new gross total ...... ₱%s', number_format($newTotal, 2)));
        $this->line(sprintf('  difference ........... ₱%s', number_format($newTotal - $legacyTotal, 2)));
        $this->newLine();

        if ($mismatches === []) {
            $this->info('✓ Every week matches. The new engine reproduces legacy payouts exactly.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d of %d weeks disagree:', count($mismatches), $compared));
        $this->table(['Instructor', 'Week', 'Legacy', 'New', 'Delta'], array_slice($mismatches, 0, 40));

        if (count($mismatches) > 40) {
            $this->warn(sprintf('... and %d more not shown.', count($mismatches) - 40));
        }

        return self::FAILURE;
    }

    /**
     * The legacy getTeacherEarnings() gross, computed with the ORIGINAL SQL.
     *
     * Reproduced verbatim from app/models/Earnings.php rather than reimplemented,
     * so this is a genuine independent check and not the new logic compared
     * against itself. The rate arithmetic below is the legacy calculateAmount().
     */
    private function legacyGross($legacy, int $teacherId, string $start, string $end): float
    {
        // Resolved, not hard-coded: after an in-place cutover the legacy tables
        // are renamed and these must follow them. See config/academy.php.
        $usersTable = config('academy.legacy_tables.users', 'users');
        $tpTable = config('academy.legacy_tables.teacher_presence', 'teacher_presence');
        $feedbackTable = config('academy.legacy_tables.feedback', 'feedback');

        $prefix = 'Early class held on ';

        $paid = "CASE
                    WHEN tp.postpone_reason LIKE '{$prefix}%'
                        THEN COALESCE(STR_TO_DATE(RIGHT(tp.postpone_reason, 10), '%Y-%m-%d'), DATE(tp.date))
                    ELSE DATE(tp.date)
                 END";

        $paidCheck = "CASE
                    WHEN tp_check.postpone_reason LIKE '{$prefix}%'
                        THEN COALESCE(STR_TO_DATE(RIGHT(tp_check.postpone_reason, 10), '%Y-%m-%d'), DATE(tp_check.date))
                    ELSE DATE(tp_check.date)
                 END";

        $sql = "
            SELECT
                COALESCE(d.student_teaching_methods, u.teaching_methods, 'Not specified') AS teaching_methods,
                COALESCE(d.student_learning_time, u.learning_time, 0) AS learning_time
            FROM (
                SELECT
                    tp.teacher_id,
                    tp.student_id,
                    tp.student_username,
                    tp.student_teaching_methods,
                    tp.student_learning_time,
                    {$paid} AS class_date,
                    CASE
                        WHEN MAX(CASE WHEN tp.status = 'present' THEN 1 ELSE 0 END) = 1 THEN 'present'
                        WHEN MAX(CASE WHEN tp.status = 'absent' AND tp.absent_by = 'student' THEN 1 ELSE 0 END) = 1 THEN 'student_absent'
                        WHEN MAX(CASE WHEN tp.status = 'absent' AND tp.absent_by = 'teacher' THEN 1 ELSE 0 END) = 1 THEN 'teacher_absent'
                        ELSE NULL
                    END AS resolved_status
                FROM {$tpTable} tp
                WHERE tp.teacher_id = ?
                  AND tp.status IN ('present','absent')
                  AND {$paid} BETWEEN ? AND ?
                GROUP BY tp.teacher_id, tp.student_id, tp.student_username,
                         tp.student_teaching_methods, tp.student_learning_time,
                         {$paid}, DATE(tp.date), tp.date
            ) d
            LEFT JOIN {$usersTable} u ON u.id = d.student_id
            LEFT JOIN {$feedbackTable} f ON f.instructor_id = d.teacher_id
                AND f.student_id = d.student_id
                AND DATE(COALESCE(f.class_date, f.created_at)) = d.class_date
            WHERE d.resolved_status IS NOT NULL
              AND d.resolved_status IN ('present', 'student_absent')
              AND (
                  d.student_id > 0
                  OR (
                      d.student_id < 0
                      AND NOT EXISTS (
                          SELECT 1 FROM {$tpTable} tp_check
                          JOIN {$usersTable} u_check ON u_check.id = tp_check.student_id
                          WHERE tp_check.teacher_id = d.teacher_id
                            AND {$paidCheck} = d.class_date
                            AND tp_check.student_id > 0
                            AND tp_check.status IN ('present','absent')
                            AND u_check.username = d.student_username
                      )
                  )
              )
              AND (
                  (d.class_date < ?)
                  OR (d.class_date >= ? AND (f.id IS NOT NULL OR ? = 1))
              )
        ";

        $requiredFrom = (string) config('academy.feedback_required_from', '2024-01-01');
        $exempt = in_array((int) $teacherId, [66, 67, 82], true) ? 1 : 0;

        $rows = $legacy->select($sql, [
            $teacherId, $start, $end, $requiredFrom, $requiredFrom, $exempt,
        ]);

        $total = 0.0;

        foreach ($rows as $row) {
            $total += $this->legacyAmount($row->teaching_methods, (int) $row->learning_time);
        }

        return round($total, 2);
    }

    /** The legacy Earnings::calculateAmount(), unchanged. */
    private function legacyAmount(?string $methods, int $minutes): float
    {
        $lower = strtolower((string) $methods);

        if (str_contains($lower, 'video_kids') || str_contains($lower, 'video-kids')) {
            $rate = self::VIDEO_KIDS_RATE;
        } elseif (
            str_contains($lower, 'video_adults')
            || str_contains($lower, 'video-adults')
            || str_contains($lower, 'videoadult')
        ) {
            $rate = self::VIDEO_ADULT_RATE;
        } else {
            $rate = self::AUDIO_RATE;
        }

        return round($minutes / 60 * $rate, 2);
    }
}
