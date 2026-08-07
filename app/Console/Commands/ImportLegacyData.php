<?php

namespace App\Console\Commands;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\Role;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrates the native-PHP schema into the normalised one.
 *
 * Idempotent for ROWS: every one is keyed on `legacy_id` or a natural key, so a
 * plain re-run updates in place rather than duplicating, and picks up whatever
 * production has added since.
 *
 *     php artisan legacy:import --dry-run
 *     php artisan legacy:import
 *
 * NOT idempotent for MAPPINGS. If the logic that decides which imported user a
 * legacy student maps to ever changes, a plain re-run writes the new mapping but
 * leaves rows created under the OLD one behind — so one legacy session ends up
 * imported twice, under two different students, and the instructor is paid twice
 * for it. Any change to restoreDeletedStudents() or resolveStudentId() must be
 * followed by:
 *
 *     php artisan legacy:import --fresh
 *
 * `legacy:verify-earnings` catches this: duplicated sessions show up as the new
 * engine paying MORE than legacy.
 *
 * The source is the `legacy` connection (see config/database.php), which is
 * read-only by convention — nothing here writes to it.
 */
class ImportLegacyData extends Command
{
    protected $signature = 'legacy:import
                            {--dry-run : Report what would change without writing}
                            {--fresh : Wipe the target tables first}';

    protected $description = 'Import users, schedules, attendance and reports from the legacy schema';

    /**
     * Everything this command writes to, in truncation order (children first).
     *
     * Also the list the same-database check compares the legacy source tables
     * against: what makes an import unsafe is a table appearing in both.
     */
    private const TARGET_TABLES = [
        'audit_logs', 'session_reports', 'class_sessions', 'payouts', 'bookings',
        'instructor_availabilities', 'student_schedules', 'student_profiles',
        'instructor_profiles', 'users',
    ];

    /** Legacy user id => new user id. */
    private array $userMap = [];

    /**
     * Lowercased snapshot username => new user id.
     *
     * For attendance rows whose student_id was ZEROED rather than negated on
     * deletion; those carry no usable id, only the preserved username.
     */
    private array $snapshotMap = [];

    /** Counters for the closing summary. */
    private array $stats = [];

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        try {
            $legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the legacy database: '.$e->getMessage());
            $this->line('Check LEGACY_DB_* in your .env file.');

            return self::FAILURE;
        }

        if (! $this->assertDistinctDatabases($legacy)) {
            return self::FAILURE;
        }

        $this->info('Source: '.$this->describe($legacy).'   Target: '.$this->describe(DB::connection()));
        $this->newLine();

        if ($this->option('fresh') && ! $this->option('dry-run')) {
            $this->error('--fresh TRUNCATES every table in the target database.');
            $this->line('  Target: '.$this->describe(DB::connection()));

            if (! $this->confirm('Is that definitely the right database to wipe?', false)) {
                return self::FAILURE;
            }

            $this->truncateTarget();
        }

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $this->importUsers($legacy);
            $this->importInstructorProfiles($legacy);
            $this->importStudentProfiles($legacy);
            $this->importSchedules($legacy);
            $this->importClassSessions($legacy);
            $this->importSessionReports($legacy);
            $this->importInstructorAvailability($legacy);
            $this->importAuditLog($legacy);

            if ($dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('Dry run — everything rolled back.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('Import committed.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('Import failed and was rolled back: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->renderSummary();

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------- users

    /**
     * The god-table split.
     *
     * Legacy role 'user' becomes 'student'. Blank emails become NULL so they do
     * not collide under the unique index — 222 of 256 rows have no email.
     */
    private function importUsers($legacy): void
    {
        $rows = $legacy->table($this->src('users'))->orderBy('id')->get();
        $count = 0;

        foreach ($rows as $row) {
            $role = match ($row->role) {
                'admin' => Role::Admin,
                'instructor' => Role::Instructor,
                default => Role::Student,
            };

            $email = trim((string) ($row->email ?? ''));

            $id = DB::table('users')->updateOrInsert(
                ['legacy_id' => (int) $row->id],
                [
                    'name' => $row->username,
                    'email' => $email === '' ? null : $email,
                    // Already a password_hash() digest; Laravel's bcrypt driver
                    // verifies it unchanged, so existing passwords keep working.
                    'password' => $row->password,
                    'role' => $role->value,
                    'phone' => $row->phone_number ?: null,
                    'birthday' => $this->date($row->birthday ?? null),
                    'avatar_path' => $row->profile_image ?: null,
                    'is_active' => ($row->status ?? 'active') === 'active',
                    // Coalesced, not assumed: the live legacy `users` has
                    // created_at but neither updated_at nor deleted_at, and a
                    // bare -> on a missing column is a fatal, not a null.
                    'created_at' => ($row->created_at ?? null) ?: now(),
                    'updated_at' => ($row->updated_at ?? null) ?: now(),
                    'deleted_at' => ($row->deleted_at ?? null) ?: null,
                ]
            );

            $count++;
        }

        // Build the id map in one query rather than per row.
        $this->userMap = DB::table('users')
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')
            ->toArray();

        $this->stats['users'] = $count;
        $this->line("  users ................ {$count}");
    }

    private function importInstructorProfiles($legacy): void
    {
        $count = 0;

        // The legacy instructor_profiles table, where it exists.
        if ($this->legacyHasTable($legacy, 'instructor_profiles')) {
            foreach ($legacy->table($this->src('instructor_profiles'))->get() as $row) {
                $userId = $this->userMap[(int) $row->user_id] ?? null;

                if ($userId === null) {
                    continue;
                }

                $credentials = array_values(array_filter([
                    $row->credential_image_1 ?? null,
                    $row->credential_image_2 ?? null,
                    $row->credential_image_3 ?? null,
                ]));

                DB::table('instructor_profiles')->updateOrInsert(
                    ['user_id' => $userId],
                    [
                        'bio' => $row->bio ?: null,
                        'voice_intro_path' => $row->voice_intro ?: ($row->intro_voice ?? null) ?: null,
                        'credential_paths' => $credentials === [] ? null : json_encode($credentials),
                        'created_at' => $row->created_at ?: now(),
                        'updated_at' => ($row->updated_at ?? null) ?: now(),
                    ]
                );
                $count++;
            }
        }

        // bank_name lived on the users god-table; fold it in.
        foreach ($legacy->table($this->src('users'))->where('role', 'instructor')->get() as $row) {
            $userId = $this->userMap[(int) $row->id] ?? null;

            if ($userId === null || empty($row->bank_name)) {
                continue;
            }

            DB::table('instructor_profiles')->updateOrInsert(
                ['user_id' => $userId],
                ['bank_name' => $row->bank_name, 'updated_at' => now()]
            );
        }

        $this->stats['instructor_profiles'] = DB::table('instructor_profiles')->count();
        $this->line('  instructor_profiles .. '.$this->stats['instructor_profiles']);
    }

    /**
     * Student attributes, with the misleading legacy names corrected:
     *   semester       -> sessions_remaining  (it was a countdown, not a term)
     *   present_count  -> sessions_attended
     *   deduction_days -> sessions_deducted
     *   regular        -> is_regular
     */
    private function importStudentProfiles($legacy): void
    {
        // enrollment_status was added to production after this dump was taken.
        $hasEnrollment = $this->legacyHasColumn($legacy, 'users', 'enrollment_status');

        $count = 0;

        foreach ($legacy->table($this->src('users'))->where('role', 'user')->get() as $row) {
            $userId = $this->userMap[(int) $row->id] ?? null;

            if ($userId === null) {
                continue;
            }

            $instructorId = $row->instructor_id
                ? ($this->userMap[(int) $row->instructor_id] ?? null)
                : null;

            // NULL meant "predates the approval flow", which the app read as
            // approved — so that is what it maps to.
            $enrollment = $hasEnrollment
                ? (EnrollmentStatus::tryFrom((string) ($row->enrollment_status ?? '')) ?? EnrollmentStatus::Approved)
                : EnrollmentStatus::Approved;

            DB::table('student_profiles')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'instructor_id' => $instructorId,
                    'teaching_method' => TeachingMethod::fromLegacy($row->teaching_methods ?? null)?->value,
                    'learning_time' => $this->positiveInt($row->learning_time ?? null),
                    'sessions_remaining' => $this->positiveInt($row->semester ?? null) ?? 0,
                    'sessions_attended' => $this->positiveInt($row->present_count ?? null) ?? 0,
                    'sessions_deducted' => $this->positiveInt($row->deduction_days ?? null) ?? 0,
                    'is_regular' => trim(strtolower((string) ($row->regular ?? ''))) === 'regular',
                    'enrollment_status' => $enrollment->value,
                    'start_date' => $this->date($row->start_date ?? null),
                    'end_date' => $this->date($row->end_date ?? null),
                    'created_at' => $row->created_at ?: now(),
                    'updated_at' => ($row->updated_at ?? null) ?: now(),
                ]
            );
            $count++;
        }

        $this->stats['student_profiles'] = $count;
        $this->line("  student_profiles ..... {$count}");
    }

    /**
     * Unpacks `users.schedule` ("Monday,Wednesday,Friday") plus the seven
     * `<day>_time` columns into one row per class day.
     */
    private function importSchedules($legacy): void
    {
        $dayColumns = [
            1 => 'monday_time',
            2 => 'tuesday_time',
            3 => 'wednesday_time',
            4 => 'thursday_time',
            5 => 'friday_time',
            6 => 'saturday_time',
            7 => 'sunday_time',
        ];

        $inserted = 0;
        $skippedNoTime = 0;

        foreach ($legacy->table($this->src('users'))->where('role', 'user')->get() as $row) {
            $userId = $this->userMap[(int) $row->id] ?? null;

            if ($userId === null) {
                continue;
            }

            // The day list is authoritative for WHICH days; the time columns
            // supply WHEN. A day named with no time is dropped and counted,
            // because a schedule row with no start_time cannot be stored.
            $days = array_filter(array_map(
                fn (string $name) => StudentSchedule::isoDayFromName($name),
                explode(',', (string) ($row->schedule ?? ''))
            ));

            foreach ($days as $isoDay) {
                $time = $row->{$dayColumns[$isoDay]} ?? null;

                // A legacy '00:00:00' is the column default, not midnight class.
                if (empty($time) || $time === '00:00:00') {
                    $time = ! empty($row->start_time) && $row->start_time !== '00:00:00'
                        ? $row->start_time
                        : null;
                }

                if ($time === null) {
                    $skippedNoTime++;

                    continue;
                }

                DB::table('student_schedules')->updateOrInsert(
                    ['student_id' => $userId, 'day_of_week' => $isoDay],
                    ['start_time' => $time, 'created_at' => now(), 'updated_at' => now()]
                );
                $inserted++;
            }
        }

        $this->stats['student_schedules'] = $inserted;
        $this->stats['schedules_without_time'] = $skippedNoTime;
        $this->line("  student_schedules .... {$inserted}".($skippedNoTime > 0 ? "  ({$skippedNoTime} skipped: day named but no time)" : ''));
    }

    /**
     * teacher_presence -> class_sessions.
     *
     * Two legacy hacks are resolved here, once, instead of in every query:
     *
     *  1. EARLY CLASSES. `postpone_reason` beginning 'Early class held on ' hid
     *     the real teaching date in its last 10 characters. That date is parsed
     *     out into the real `held_date` column and the marker is stripped from
     *     the note.
     *
     *  2. HARD-DELETED STUDENTS. The legacy app had two deletion paths that
     *     both orphaned attendance rows, and each left a different marker:
     *
     *       student_id < 0   hardDeleteStudentForInstructor() negated the id
     *       student_id = 0   a second path zeroed it
     *
     *     Either way the student's identity survives only in the
     *     `student_username` snapshot on the attendance row. Both are restored
     *     to a real soft-deleted user here, so the instructor's teaching record
     *     stays intact and the runtime needs none of the negative-id or
     *     username-dedup logic the legacy queries carried.
     *
     *     Note on the zeroed rows: legacy earnings silently DROPPED them, since
     *     `student_id = 0` satisfies neither the `> 0` nor the `< 0` branch of
     *     the gate in getTeacherEarnings. Restoring them does not retroactively
     *     create pay — the report requirement still gates payment and none of
     *     these rows has a report — but it stops the sessions from vanishing.
     */
    private function importClassSessions($legacy): void
    {
        $prefix = 'Early class held on ';
        $imported = 0;
        $early = 0;
        $dropped = 0;
        $skipped = 0;
        $collisions = 0;

        /** @var array<string, true> slots filled during this run */
        $seenSlots = [];

        $restored = $this->restoreDeletedStudents($legacy);

        // --- The legacy duplicate rule, applied once ------------------------
        // A deleted-student row is a duplicate only when the SAME student
        // (matched on the preserved username) also has an ACTIVE row on the same
        // teacher and paid date — i.e. a delete + re-enrol left two rows for one
        // real class. Applied here so the earnings query never has to.
        $activeKeys = $this->activeSessionKeys($legacy, $prefix);

        foreach ($legacy->table($this->src('teacher_presence'))->orderBy('id')->cursor() as $row) {
            $instructorId = $this->userMap[(int) $row->teacher_id] ?? null;
            $studentId = $this->resolveStudentId($row);

            if ($instructorId === null || $studentId === null || empty($row->date)) {
                $skipped++;

                continue;
            }

            // Unpack the early-class marker.
            $heldDate = null;
            $reason = $row->postpone_reason ?? null;

            if (is_string($reason) && str_starts_with($reason, $prefix)) {
                $parsed = $this->date(substr($reason, -10));

                if ($parsed !== null) {
                    $heldDate = $parsed;
                    $early++;
                    // Strip the marker: the date is a column now, and leaving the
                    // text would invite something parsing it again.
                    $reason = trim(substr($reason, 0, -strlen($prefix) - 10)) ?: null;
                }
            }

            $paidDate = $heldDate ?? $this->date($row->date);

            // Drop a deleted-student row that duplicates an active one.
            if ((int) $row->student_id < 0) {
                $username = trim((string) $row->student_username);
                $key = (int) $row->teacher_id.'|'.$paidDate.'|'.mb_strtolower($username);

                if ($username !== '' && isset($activeKeys[$key])) {
                    $dropped++;

                    continue;
                }
            }

            $status = SessionStatus::tryFrom((string) ($row->status ?? ''));
            $absentBy = $status === SessionStatus::Absent
                ? Party::tryFrom((string) ($row->absent_by ?? ''))
                : null;
            $postponedBy = $status === SessionStatus::Postponed
                ? Party::tryFrom((string) ($row->postponed_by ?? ''))
                : null;

            $slot = [
                'instructor_id' => $instructorId,
                'student_id' => $studentId,
                'scheduled_date' => $this->date($row->date),
            ];

            /*
             * Two legacy rows mapping onto one imported session means
             * updateOrInsert silently overwrites the first — losing a taught
             * class, and the instructor's pay for it.
             *
             * Tracked against slots seen in THIS run, not against the table:
             * the importer is idempotent, so on a re-import every row already
             * exists and a table check would call all of them collisions.
             */
            $slotKey = implode('|', $slot);

            if (isset($seenSlots[$slotKey])) {
                $collisions++;
            }

            $seenSlots[$slotKey] = true;

            DB::table('class_sessions')->updateOrInsert(
                $slot,
                [
                    'held_date' => $heldDate,
                    'status' => $status?->value,
                    'absent_by' => $absentBy?->value,
                    'postponed_by' => $postponedBy?->value,
                    'postpone_reason' => $reason ?: null,
                    'makeup_time' => ($row->makeup_time ?? null) ?: null,
                    'rescheduled_time' => ($row->rescheduled_time ?? null) ?: null,
                    'created_at' => $row->created_at ?: now(),
                    'updated_at' => ($row->updated_at ?? null) ?: now(),
                ]
            );

            $imported++;
        }

        $this->stats['class_sessions'] = $imported;
        $this->stats['early_classes'] = $early;
        $this->stats['restored_students'] = $restored;
        $this->stats['dropped_duplicates'] = $dropped;
        $this->stats['skipped_sessions'] = $skipped;
        $this->stats['slot_collisions'] = $collisions;

        $this->line("  class_sessions ....... {$imported}");
        $this->line("    early classes ...... {$early}   (parsed out of postpone_reason)");
        $this->line("    restored students .. {$restored}  (orphaned by a hard delete, rebuilt from snapshots)");
        $this->line("    dropped duplicates . {$dropped}");

        if ($skipped > 0) {
            $this->warn("    skipped ............ {$skipped}  (unmapped instructor/student, or no date)");
        }

        if ($collisions > 0) {
            // Never expected. Means two legacy students mapped to one imported
            // user, so a taught class — and its pay — was overwritten.
            $this->error("    SLOT COLLISIONS .... {$collisions}  (two legacy rows mapped to one session — pay LOST)");
            $this->line('    Run `php artisan legacy:verify-earnings` to size the damage.');
        }
    }

    /**
     * Rebuild users for students whose attendance rows were orphaned by a hard
     * delete — negated ids and zeroed ids alike.
     *
     * Resolution order for a snapshot username:
     *   1. An existing user with that exact name (the student was re-enrolled,
     *      or only the FK was lost) — link to them, create nothing.
     *   2. Otherwise create a soft-deleted student from the snapshot.
     *
     * @return int number of users created
     */
    private function restoreDeletedStudents($legacy): int
    {
        $snapshots = $legacy->table($this->src('teacher_presence'))
            ->where('student_id', '<=', 0)
            ->whereNotNull('student_username')
            ->where('student_username', '<>', '')
            ->select(
                'student_id',
                'student_username',
                'student_teaching_methods',
                'student_learning_time',
                'student_deleted_at'
            )
            ->get()
            ->unique(fn ($row) => (int) $row->student_id.'|'.mb_strtolower(trim((string) $row->student_username)));

        $created = 0;

        foreach ($snapshots as $snapshot) {
            $legacyId = (int) $snapshot->student_id;
            $name = trim((string) $snapshot->student_username);
            $nameKey = mb_strtolower($name);

            /*
             * A NEGATED id identifies one specific deleted student, so it always
             * gets its own user keyed on legacy_id — never merged by name.
             *
             * Merging by name loses money. Production has two distinct deleted
             * students both preserved as "A628 Min Jae" (legacy ids -736 and
             * -1178). Collapsing them into one user made their sessions collide
             * on the unique key (instructor, student, scheduled_date), so
             * updateOrInsert overwrote instead of inserting and 8 taught classes
             * vanished from one instructor's payslips.
             *
             * The "was this student re-enrolled?" question is a DIFFERENT
             * concern, and already handled by the activeSessionKeys duplicate
             * rule below — which is what the legacy earnings query did too.
             */
            if ($legacyId < 0) {
                if (isset($this->userMap[$legacyId])) {
                    continue;
                }
            } else {
                // A ZEROED id carries no usable key, so the preserved username is
                // the only thing to go on. Reuse an existing student of that name
                // when there is one, otherwise create it.
                if (isset($this->snapshotMap[$nameKey])) {
                    continue;
                }

                $existingId = DB::table('users')
                    ->whereRaw('LOWER(name) = ?', [$nameKey])
                    ->where('role', Role::Student->value)
                    ->value('id');

                if ($existingId !== null) {
                    $this->snapshotMap[$nameKey] = $existingId;

                    continue;
                }
            }

            $attributes = [
                'name' => $name !== '' ? $name : 'Deleted student '.abs($legacyId),
                'email' => null,
                // No usable credential survived the deletion; a random hash
                // means the account cannot be signed into.
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'role' => Role::Student->value,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => $snapshot->student_deleted_at ?: now(),
            ];

            if ($legacyId < 0) {
                DB::table('users')->updateOrInsert(['legacy_id' => $legacyId], $attributes);
                $newId = DB::table('users')->where('legacy_id', $legacyId)->value('id');
                $this->userMap[$legacyId] = $newId;
            } else {
                $newId = DB::table('users')->insertGetId($attributes + ['legacy_id' => null]);
            }

            $this->snapshotMap[$nameKey] = $newId;

            DB::table('student_profiles')->updateOrInsert(
                ['user_id' => $newId],
                [
                    'teaching_method' => TeachingMethod::fromLegacy($snapshot->student_teaching_methods)?->value,
                    'learning_time' => $this->positiveInt($snapshot->student_learning_time),
                    'enrollment_status' => EnrollmentStatus::Approved->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $created++;
        }

        return $created;
    }

    /**
     * The new user id for an attendance row's student.
     *
     * A positive legacy id maps directly. A negated or zeroed id is an orphan
     * from a hard delete and resolves through the restored-snapshot map.
     */
    private function resolveStudentId(object $row): ?int
    {
        $legacyId = (int) $row->student_id;

        if ($legacyId > 0) {
            return $this->userMap[$legacyId] ?? null;
        }

        if ($legacyId < 0 && isset($this->userMap[$legacyId])) {
            return $this->userMap[$legacyId];
        }

        $nameKey = mb_strtolower(trim((string) ($row->student_username ?? '')));

        return $nameKey === '' ? null : ($this->snapshotMap[$nameKey] ?? null);
    }

    /**
     * Lookup of teacher|paidDate|username for every ACTIVE attendance row, used
     * to detect delete + re-enrol duplicates.
     *
     * @return array<string, true>
     */
    private function activeSessionKeys($legacy, string $prefix): array
    {
        $keys = [];

        // `postpone_reason` carries the legacy early-class date, but not every
        // legacy install has the column. Selecting it blindly is a SQL error, so
        // it is only asked for when it is there; without it there is simply no
        // early class to detect.
        $columns = ['tp.teacher_id', 'tp.date', 'u.username'];

        if ($this->legacyHasColumn($legacy, 'teacher_presence', 'postpone_reason')) {
            $columns[] = 'tp.postpone_reason';
        }

        $rows = $legacy->table($this->src('teacher_presence', 'tp'))
            ->join($this->src('users', 'u'), 'u.id', '=', 'tp.student_id')
            ->where('tp.student_id', '>', 0)
            ->whereIn('tp.status', SessionStatus::payableValues())
            ->select($columns)
            ->get();

        foreach ($rows as $row) {
            $paid = $this->date($row->date);

            if (is_string($row->postpone_reason ?? null) && str_starts_with($row->postpone_reason, $prefix)) {
                $paid = $this->date(substr($row->postpone_reason, -10)) ?? $paid;
            }

            $keys[(int) $row->teacher_id.'|'.$paid.'|'.mb_strtolower(trim((string) $row->username))] = true;
        }

        return $keys;
    }

    /**
     * feedback -> session_reports, linked to its class_session where resolvable.
     */
    private function importSessionReports($legacy): void
    {
        $imported = 0;
        $skipped = 0;
        $linked = 0;

        // One lookup of (instructor, student, paid_date) => session id, so the
        // FK can be filled without a query per report.
        $sessionIds = [];
        foreach (DB::table('class_sessions')->select('id', 'instructor_id', 'student_id', 'paid_date')->cursor() as $s) {
            $sessionIds[$s->instructor_id.'|'.$s->student_id.'|'.$s->paid_date] = $s->id;
        }

        foreach ($legacy->table($this->src('feedback'))->orderBy('id')->cursor() as $row) {
            $instructorId = $this->userMap[(int) $row->instructor_id] ?? null;
            $studentId = $this->userMap[(int) $row->student_id] ?? null;

            if ($instructorId === null || $studentId === null) {
                $skipped++;

                continue;
            }

            // Legacy matched on COALESCE(class_date, created_at).
            $classDate = $this->date($row->class_date ?? null) ?? $this->date($row->created_at ?? null);

            if ($classDate === null) {
                $skipped++;

                continue;
            }

            $sessionId = $sessionIds[$instructorId.'|'.$studentId.'|'.$classDate] ?? null;

            if ($sessionId !== null) {
                $linked++;
            }

            DB::table('session_reports')->updateOrInsert(
                [
                    'instructor_id' => $instructorId,
                    'student_id' => $studentId,
                    'class_date' => $classDate,
                ],
                [
                    'class_session_id' => $sessionId,
                    'today_lesson' => $row->today_lesson ?: null,
                    'next_lesson' => $row->next_lesson ?: null,
                    'grammar_section' => $row->grammar_section ?: null,
                    'pronunciation_section' => $row->pronunciation_section ?: null,
                    'vocab_section' => $row->vocab_section ?: null,
                    'teacher_comments' => $row->teacher_comments ?: null,
                    'listening_score' => $this->score($row->listening_score ?? null),
                    'speaking_score' => $this->score($row->speaking_score ?? null),
                    'pronunciation_score' => $this->score($row->pronunciation_score ?? null),
                    'vocabulary_score' => $this->score($row->vocabulary_score ?? null),
                    'grammar_score' => $this->score($row->grammar_score ?? null),
                    'created_at' => $row->created_at ?: now(),
                    'updated_at' => $row->created_at ?: now(),
                ]
            );

            $imported++;
        }

        $this->stats['session_reports'] = $imported;
        $this->stats['reports_linked'] = $linked;
        $this->line("  session_reports ...... {$imported}   ({$linked} linked to a session)");

        if ($skipped > 0) {
            $this->warn("    skipped ............ {$skipped}  (unmapped user or no date)");
        }
    }

    private function importInstructorAvailability($legacy): void
    {
        if (! $this->legacyHasTable($legacy, 'teacher_schedules')) {
            return;
        }

        $count = 0;

        foreach ($legacy->table($this->src('teacher_schedules'))->get() as $row) {
            $instructorId = $this->userMap[(int) $row->instructor_id] ?? null;
            $isoDay = StudentSchedule::isoDayFromName((string) $row->day_of_week);

            if ($instructorId === null || $isoDay === null) {
                continue;
            }

            DB::table('instructor_availabilities')->updateOrInsert(
                [
                    'instructor_id' => $instructorId,
                    'day_of_week' => $isoDay,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                ],
                [
                    'is_available' => ($row->status ?? 'available') === 'available',
                    'created_at' => $row->created_at ?: now(),
                    'updated_at' => ($row->updated_at ?? null) ?: now(),
                ]
            );
            $count++;
        }

        $this->stats['instructor_availabilities'] = $count;
        $this->line("  availabilities ....... {$count}");
    }

    private function importAuditLog($legacy): void
    {
        if (! $this->legacyHasTable($legacy, 'audit_log')) {
            return;
        }

        $count = 0;

        foreach ($legacy->table($this->src('audit_log'))->orderBy('id')->cursor() as $row) {
            $userId = $this->userMap[(int) $row->user_id] ?? null;

            // target_table + target_id become a polymorphic reference.
            $type = match ($row->target_table) {
                'users' => User::class,
                'teacher_presence' => ClassSession::class,
                'feedback' => SessionReport::class,
                default => null,
            };

            $targetId = $type === User::class
                ? ($this->userMap[(int) $row->target_id] ?? null)
                : null;

            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'action' => substr((string) $row->action, 0, 60),
                'auditable_type' => $targetId !== null ? $type : null,
                'auditable_id' => $targetId,
                'target_name' => $row->target_name ?: null,
                'details' => $this->json($row->details ?? null),
                'ip_address' => $row->ip_address ?: null,
                'user_agent' => $row->user_agent ?: null,
                'created_at' => $row->created_at ?: now(),
                'updated_at' => $row->created_at ?: now(),
            ]);
            $count++;
        }

        $this->stats['audit_logs'] = $count;
        $this->line("  audit_logs ........... {$count}");
    }

    // --------------------------------------------------------------- utilities

    /**
     * Refuse to run when the source and target are the same database.
     *
     * The importer writes to the default connection and reads from `legacy`. If
     * both point at the same server and schema — which happens the moment
     * someone sets DB_* to the production host — then `--fresh` would truncate
     * the very data being imported, and the import itself would fight the
     * legacy tables it is reading.
     */
    private function assertDistinctDatabases($legacy): bool
    {
        $target = DB::connection();

        if ($this->fingerprint($legacy) !== $this->fingerprint($target)) {
            return true;
        }

        // One database is legitimate after an in-place cutover: the legacy
        // tables were RENAMED clear (database/schema/production_cutover.sql) so
        // the modern schema could take their names. What makes an import unsafe
        // is not the database name, it is a table being both a source and a
        // target -- where --fresh would truncate the very rows being read. So
        // check for that overlap instead.
        $sources = array_values(config('academy.legacy_tables', []));
        $overlap = array_intersect($sources, self::TARGET_TABLES);

        if ($overlap === []) {
            $this->warn('Source and target are one database: '.$this->describe($target));
            $this->line('  Legacy tables are renamed clear of the modern ones, so this is safe.');
            $this->line('  Reading from: '.implode(', ', $sources));
            $this->newLine();

            return true;
        }

        $this->error('Source and target are the same database, and the same tables.');
        $this->newLine();
        $this->line('  Both resolve to: '.$this->describe($target));
        $this->line('  Read AND written: '.implode(', ', $overlap));
        $this->newLine();
        $this->line('  Either point LEGACY_DB_* at a separate database, or rename the legacy');
        $this->line('  tables clear and set LEGACY_TABLE_PREFIX -- see');
        $this->line('  database/schema/production_cutover.sql.');
        $this->newLine();
        $this->warn('  As it stands, --fresh would truncate the rows being imported.');

        return false;
    }

    /** host:port/database, for comparison. */
    private function fingerprint($connection): string
    {
        $config = $connection->getConfig();

        return sprintf(
            '%s:%s/%s',
            $config['host'] ?? '',
            $config['port'] ?? '',
            $config['database'] ?? '',
        );
    }

    /** Human-readable connection label for the console. */
    private function describe($connection): string
    {
        $config = $connection->getConfig();

        return sprintf(
            '%s @ %s',
            $config['database'] ?? '?',
            $config['host'] ?? '?',
        );
    }

    private function truncateTarget(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TARGET_TABLES as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();

        $this->warn('Target tables truncated.');
    }

    private function legacyHasTable($legacy, string $table): bool
    {
        try {
            return $legacy->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function legacyHasColumn($legacy, string $table, string $column): bool
    {
        try {
            return $legacy->getSchemaBuilder()->hasColumn($this->src($table), $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /** 'Y-m-d' or null. Rejects the 0000-00-00 that MySQL used to allow. */
    private function date(mixed $value): ?string
    {
        if (empty($value) || str_starts_with((string) $value, '0000')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    /** Scores are stored 1-10; anything outside that is dropped, not clamped. */
    /**
     * The name of a legacy source table.
     *
     * Normally the legacy name on a separate database. After an in-place
     * cutover both connections point at one database and the two legacy tables
     * whose names the modern schema needs — `users` and `instructor_profiles` —
     * have been RENAMED (never dropped) to legacy_*. This resolves either
     * layout from config, so the importer does not care which one it is looking
     * at. See config/academy.php.
     */
    private function src(string $table, ?string $alias = null): string
    {
        $name = config("academy.legacy_tables.{$table}", $table);

        return $alias === null ? $name : "{$name} as {$alias}";
    }

    private function score(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return ($int >= 1 && $int <= 10) ? $int : null;
    }

    private function json(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Legacy `details` was already a JSON string; keep it if it parses,
        // otherwise wrap the prose so nothing is lost.
        json_decode((string) $value);

        return json_last_error() === JSON_ERROR_NONE
            ? (string) $value
            : json_encode(['note' => (string) $value]);
    }

    private function renderSummary(): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($this->stats)->map(fn ($v, $k) => [$k, number_format($v)])->values()->all()
        );
    }
}
