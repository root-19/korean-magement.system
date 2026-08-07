<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The post-class report an instructor files. Replaces legacy `feedback`.
 *
 * Renamed away from "feedback" because it is not student feedback about the
 * class — it is the instructor's assessment of the student, and filing it is
 * what unlocks payment for that session.
 *
 * THE JOIN FIX
 * ------------
 * Legacy had no link between a report and the session it described, so every
 * earnings query re-derived the relationship by matching three columns and
 * coalescing a date out of two candidates:
 *
 *     LEFT JOIN feedback f
 *            ON f.instructor_id = tp.teacher_id
 *           AND f.student_id    = tp.student_id
 *           AND DATE(COALESCE(f.class_date, f.created_at)) = <paid date expr>
 *
 * `class_session_id` makes that a foreign key. The denormalised
 * instructor_id / student_id / class_date are kept alongside it because reports
 * are also listed per student and per date without touching class_sessions, and
 * because the importer cannot always resolve a session for a historical row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_reports', function (Blueprint $table) {
            $table->id();

            // Nullable: some legacy reports have no matching attendance row.
            // Those still count as filed, they just cannot be linked.
            $table->foreignId('class_session_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // The date of the class being reported on — for an early class this
            // is the held date, matching class_sessions.paid_date.
            $table->date('class_date');

            // Lesson content.
            $table->text('today_lesson')->nullable();
            $table->text('next_lesson')->nullable();
            $table->text('grammar_section')->nullable();
            $table->text('pronunciation_section')->nullable();
            $table->text('vocab_section')->nullable();
            $table->text('teacher_comments')->nullable();

            // Assessment, 1-10 in the UI. Nullable because early reports
            // predate the scoring fields.
            $table->unsignedTinyInteger('listening_score')->nullable();
            $table->unsignedTinyInteger('speaking_score')->nullable();
            $table->unsignedTinyInteger('pronunciation_score')->nullable();
            $table->unsignedTinyInteger('vocabulary_score')->nullable();
            $table->unsignedTinyInteger('grammar_score')->nullable();

            $table->timestamps();

            // One report per class. The live data satisfies this already
            // (0 duplicate groups across 2,071 rows), so it is safe to enforce
            // rather than leaving duplicates to double-count earnings.
            $table->unique(['instructor_id', 'student_id', 'class_date'], 'session_reports_class_unique');

            $table->index(['student_id', 'class_date']);
            $table->index('class_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_reports');
    }
};
