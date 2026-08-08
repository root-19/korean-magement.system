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
RENAME TABLE `bookings`             TO `legacy_bookings`;
RENAME TABLE `migrations`           TO `legacy_migrations`;

-- `my_view` is defined as SELECT id, username FROM `users`, so the rename above
-- leaves it pointing at a table name that no longer exists. Repointing it at
-- `legacy_users` keeps it returning exactly the same rows. Nothing is dropped:
-- CREATE OR REPLACE VIEW rewrites the definition in place.
CREATE OR REPLACE VIEW `my_view` AS SELECT `id` AS `id`, `username` AS `username` FROM `legacy_users`;

SET FOREIGN_KEY_CHECKS = 1;

-- These are NOT renamed. Their names do not collide with anything in the modern
-- schema, and `legacy:import` reads them where they stand:
--
--   feedback                      -> becomes session_reports
--   teacher_presence              -> becomes class_sessions
--   teacher_schedules             -> becomes student_schedules
--   audit_log                     -> becomes audit_logs
--
-- And these are left exactly as they are, untouched and unread:
--   archived_users, feedback_backup, instructor_attendance_history,
--   teacher_attendance_backup, teacher_presence_backup, teacher_student_backup,
--   user_sessions

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
-- RENAME TABLE `legacy_bookings`            TO `bookings`;
-- RENAME TABLE `legacy_migrations`          TO `migrations`;
-- CREATE OR REPLACE VIEW `my_view` AS SELECT `id` AS `id`, `username` AS `username` FROM `users`;
-- SET FOREIGN_KEY_CHECKS = 1;
