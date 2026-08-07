-- ---------------------------------------------------------------------
-- 10 Minute Academy — modern schema
--
-- Generated from the Laravel migrations in modern-web/database/migrations.
-- Import this into a NEW, EMPTY database (e.g. u532211211_modern).
--
-- DO NOT import into the legacy database u532211211_jsut10academy:
-- `users` and `instructor_profiles` already exist there and those two
-- CREATE statements would fail. There are no DROP statements in this
-- file, so a mistaken import errors out instead of destroying data.
--
-- After importing, point DB_DATABASE at the new database and run
--     php artisan legacy:import
-- to copy the live data across from the legacy schema.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------- audit_logs
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `auditable_type` varchar(255) DEFAULT NULL,
  `auditable_id` bigint(20) unsigned DEFAULT NULL,
  `target_name` varchar(255) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_user_id_action_index` (`user_id`,`action`),
  KEY `audit_logs_created_at_index` (`created_at`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- bookings
CREATE TABLE `bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instructor_id` bigint(20) unsigned NOT NULL,
  `student_name` varchar(120) NOT NULL,
  `kakaotalk_id` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `session_date` date NOT NULL,
  `session_time` time NOT NULL,
  `sessions` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `teaching_method` enum('audio','video_kids','video_adults') DEFAULT NULL,
  `learning_time` smallint(5) unsigned DEFAULT NULL,
  `requested_schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`requested_schedule`)),
  `start_date` date DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `converted_student_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bookings_converted_student_id_foreign` (`converted_student_id`),
  KEY `bookings_instructor_id_status_index` (`instructor_id`,`status`),
  KEY `bookings_session_date_index` (`session_date`),
  CONSTRAINT `bookings_converted_student_id_foreign` FOREIGN KEY (`converted_student_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bookings_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- class_sessions
CREATE TABLE `class_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instructor_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `held_date` date DEFAULT NULL,
  `paid_date` date GENERATED ALWAYS AS (coalesce(`held_date`,`scheduled_date`)) STORED,
  `status` enum('present','absent','postponed') DEFAULT NULL,
  `absent_by` enum('student','teacher','other') DEFAULT NULL,
  `postponed_by` enum('student','teacher','other') DEFAULT NULL,
  `postpone_reason` text DEFAULT NULL,
  `makeup_time` time DEFAULT NULL,
  `rescheduled_date` date DEFAULT NULL,
  `rescheduled_time` time DEFAULT NULL,
  `marked_by` bigint(20) unsigned DEFAULT NULL,
  `marked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_sessions_slot_unique` (`instructor_id`,`student_id`,`scheduled_date`),
  KEY `class_sessions_marked_by_foreign` (`marked_by`),
  KEY `class_sessions_instructor_id_scheduled_date_index` (`instructor_id`,`scheduled_date`),
  KEY `class_sessions_student_id_scheduled_date_index` (`student_id`,`scheduled_date`),
  KEY `class_sessions_instructor_id_paid_date_index` (`instructor_id`,`paid_date`),
  KEY `class_sessions_paid_date_index` (`paid_date`),
  KEY `class_sessions_status_index` (`status`),
  CONSTRAINT `class_sessions_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_sessions_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_sessions_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- failed_jobs
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- instructor_availabilities
CREATE TABLE `instructor_availabilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instructor_id` bigint(20) unsigned NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instructor_availabilities_slot_unique` (`instructor_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `instructor_availabilities_day_of_week_is_available_index` (`day_of_week`,`is_available`),
  CONSTRAINT `instructor_availabilities_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- instructor_profiles
CREATE TABLE `instructor_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `bio` text DEFAULT NULL,
  `voice_intro_path` varchar(255) DEFAULT NULL,
  `credential_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`credential_paths`)),
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(60) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instructor_profiles_user_id_unique` (`user_id`),
  CONSTRAINT `instructor_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- learning_materials
CREATE TABLE `learning_materials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `learning_materials_uploaded_by_foreign` (`uploaded_by`),
  KEY `learning_materials_is_published_created_at_index` (`is_published`,`created_at`),
  CONSTRAINT `learning_materials_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- migrations
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- password_reset_tokens
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- payouts
CREATE TABLE `payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instructor_id` bigint(20) unsigned NOT NULL,
  `week_start` date NOT NULL,
  `week_end` date NOT NULL,
  `gross_earnings` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_earnings` decimal(10,2) GENERATED ALWAYS AS (`gross_earnings` - `deductions`) STORED,
  `audio_earnings` decimal(10,2) NOT NULL DEFAULT 0.00,
  `video_kids_earnings` decimal(10,2) NOT NULL DEFAULT 0.00,
  `video_adults_earnings` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sessions_paid` smallint(5) unsigned NOT NULL DEFAULT 0,
  `sessions_deducted` smallint(5) unsigned NOT NULL DEFAULT 0,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `paid_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payouts_instructor_week_unique` (`instructor_id`,`week_start`),
  KEY `payouts_paid_by_foreign` (`paid_by`),
  KEY `payouts_status_index` (`status`),
  CONSTRAINT `payouts_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payouts_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- personal_access_tokens
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- session_reports
CREATE TABLE `session_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_session_id` bigint(20) unsigned DEFAULT NULL,
  `instructor_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `class_date` date NOT NULL,
  `today_lesson` text DEFAULT NULL,
  `next_lesson` text DEFAULT NULL,
  `grammar_section` text DEFAULT NULL,
  `pronunciation_section` text DEFAULT NULL,
  `vocab_section` text DEFAULT NULL,
  `teacher_comments` text DEFAULT NULL,
  `listening_score` tinyint(3) unsigned DEFAULT NULL,
  `speaking_score` tinyint(3) unsigned DEFAULT NULL,
  `pronunciation_score` tinyint(3) unsigned DEFAULT NULL,
  `vocabulary_score` tinyint(3) unsigned DEFAULT NULL,
  `grammar_score` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_reports_class_unique` (`instructor_id`,`student_id`,`class_date`),
  KEY `session_reports_class_session_id_foreign` (`class_session_id`),
  KEY `session_reports_student_id_class_date_index` (`student_id`,`class_date`),
  KEY `session_reports_class_date_index` (`class_date`),
  CONSTRAINT `session_reports_class_session_id_foreign` FOREIGN KEY (`class_session_id`) REFERENCES `class_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `session_reports_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `session_reports_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- student_profiles
CREATE TABLE `student_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `instructor_id` bigint(20) unsigned DEFAULT NULL,
  `teaching_method` enum('audio','video_kids','video_adults') DEFAULT NULL,
  `learning_time` smallint(5) unsigned DEFAULT NULL,
  `sessions_remaining` smallint(5) unsigned NOT NULL DEFAULT 0,
  `sessions_attended` smallint(5) unsigned NOT NULL DEFAULT 0,
  `sessions_deducted` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_regular` tinyint(1) NOT NULL DEFAULT 1,
  `enrollment_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `enrollment_decided_at` timestamp NULL DEFAULT NULL,
  `enrollment_decided_by` bigint(20) unsigned DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `kakaotalk_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_profiles_user_id_unique` (`user_id`),
  KEY `student_profiles_enrollment_decided_by_foreign` (`enrollment_decided_by`),
  KEY `student_profiles_instructor_id_enrollment_status_index` (`instructor_id`,`enrollment_status`),
  KEY `student_profiles_enrollment_status_index` (`enrollment_status`),
  CONSTRAINT `student_profiles_enrollment_decided_by_foreign` FOREIGN KEY (`enrollment_decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_profiles_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- student_schedules
CREATE TABLE `student_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_schedules_student_id_day_of_week_unique` (`student_id`,`day_of_week`),
  KEY `student_schedules_day_of_week_start_time_index` (`day_of_week`,`start_time`),
  CONSTRAINT `student_schedules_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------- users
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','instructor','student') NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `legacy_id` int(11) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_legacy_id_unique` (`legacy_id`),
  KEY `users_role_index` (`role`),
  KEY `users_is_active_index` (`is_active`),
  KEY `users_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `users_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark every migration as applied, so `php artisan migrate` is a no-op
-- and only future migrations run.
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
    (1, '2014_10_12_000000_create_users_table', 1),
    (2, '2014_10_12_100000_create_password_reset_tokens_table', 1),
    (3, '2019_08_19_000000_create_failed_jobs_table', 1),
    (4, '2019_12_14_000001_create_personal_access_tokens_table', 1),
    (5, '2026_08_03_000100_create_instructor_profiles_table', 1),
    (6, '2026_08_03_000200_create_student_profiles_table', 1),
    (7, '2026_08_03_000300_create_student_schedules_table', 1),
    (8, '2026_08_03_000400_create_instructor_availabilities_table', 1),
    (9, '2026_08_03_000500_create_class_sessions_table', 1),
    (10, '2026_08_03_000600_create_session_reports_table', 1),
    (11, '2026_08_03_000700_create_bookings_table', 1),
    (12, '2026_08_03_000800_create_payouts_table', 1),
    (13, '2026_08_03_000900_create_audit_logs_table', 1),
    (14, '2026_08_07_000100_create_learning_materials_table', 1);

SET FOREIGN_KEY_CHECKS = 1;
