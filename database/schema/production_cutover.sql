-- ---------------------------------------------------------------------------
-- 10 Minute Academy — in-place cutover, step 1 of 3
--
-- Frees up three table names the modern schema needs, by RENAMING the legacy
-- tables out of the way.
--
-- NOTHING IS DELETED.
--   * No DROP TABLE. No DROP DATABASE. No DROP COLUMN. No DELETE. No TRUNCATE.
--   * Every legacy table keeps every row and every column it has today.
--   * The three tables below simply answer to a different name afterwards, and
--     `legacy:import` is configured to follow them there.
--
-- It is reversible: the "ROLLBACK" block at the bottom renames them back.
--
-- WHAT IT COSTS
--   The live legacy site reads `users`. The moment that rename runs, the legacy
--   site stops working and does not come back until either the modern app is
--   live or you run the rollback. Take the backup first.
--
-- RUN ORDER
--   0.  mysqldump -u USER -p DBNAME > backup-before-cutover.sql     <-- do this
--   1.  this file
--   2.  php artisan migrate            (creates the 15 modern tables)
--   3.  php artisan legacy:import      (copies the data across)
--   4.  php artisan legacy:verify-earnings   (must report a 0.00 difference)
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- Renaming a table keeps its rows, its columns, its indexes and any foreign
-- keys pointing at it; MySQL repoints those references itself.
SET FOREIGN_KEY_CHECKS = 0;

RENAME TABLE `users`                TO `legacy_users`;
RENAME TABLE `instructor_profiles`  TO `legacy_instructor_profiles`;
RENAME TABLE `payouts`              TO `legacy_payouts`;

SET FOREIGN_KEY_CHECKS = 1;

-- These are NOT renamed. Their names do not collide with anything in the modern
-- schema, and `legacy:import` reads them where they stand:
--
--   feedback                  -> becomes session_reports
--   teacher_presence          -> becomes class_sessions
--   teacher_schedules         -> becomes student_schedules
--   audit_log                 -> becomes audit_logs
--   teacher_attendance_backup -> untouched, kept as-is
--   teacher_student_backup    -> untouched, kept as-is

-- ---------------------------------------------------------------------------
-- CHECK — every count below must match what it was before this file ran.
-- ---------------------------------------------------------------------------
-- SELECT 'legacy_users' AS table_name, COUNT(*) AS rows FROM `legacy_users`
-- UNION ALL SELECT 'legacy_instructor_profiles', COUNT(*) FROM `legacy_instructor_profiles`
-- UNION ALL SELECT 'legacy_payouts', COUNT(*) FROM `legacy_payouts`
-- UNION ALL SELECT 'teacher_presence', COUNT(*) FROM `teacher_presence`
-- UNION ALL SELECT 'feedback', COUNT(*) FROM `feedback`
-- UNION ALL SELECT 'teacher_schedules', COUNT(*) FROM `teacher_schedules`;

-- ---------------------------------------------------------------------------
-- ROLLBACK — puts the legacy site back exactly as it was.
--
-- Safe to run any time BEFORE `php artisan migrate` has created the modern
-- tables. If migrate has already run, drop nothing — just rename the modern
-- tables aside first (e.g. `users` -> `modern_users`), then run this.
-- ---------------------------------------------------------------------------
-- SET FOREIGN_KEY_CHECKS = 0;
-- RENAME TABLE `legacy_users`               TO `users`;
-- RENAME TABLE `legacy_instructor_profiles` TO `instructor_profiles`;
-- RENAME TABLE `legacy_payouts`             TO `payouts`;
-- SET FOREIGN_KEY_CHECKS = 1;
