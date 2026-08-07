<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per class slot. Replaces legacy `teacher_presence`.
 *
 * THE EARLY-CLASS FIX
 * -------------------
 * Legacy carried a UNIQUE KEY on (teacher_id, student_id, date), so a class
 * taught ahead of schedule could not be stored on the day it was actually
 * taught — that slot was already occupied by the student's regular class. The
 * workaround was to leave the row on the future scheduled date and hide the
 * real date inside a free-text column:
 *
 *     status          = 'present'
 *     postpone_reason = 'Early class held on 2025-04-18'
 *
 * Every earnings query then had to dig it back out:
 *
 *     CASE WHEN tp.postpone_reason LIKE 'Early class held on %'
 *          THEN COALESCE(STR_TO_DATE(RIGHT(tp.postpone_reason, 10), '%Y-%m-%d'),
 *                        DATE(tp.date))
 *          ELSE DATE(tp.date) END
 *
 * A date parsed out of the last 10 characters of a prose column, on every
 * filter, join and GROUP BY — unindexable, and silently wrong the moment
 * anyone appended a note.
 *
 * Here `held_date` is a real nullable DATE, and `paid_date` is a STORED
 * generated column, so the payout-window filter becomes an indexed range scan:
 *
 *     paid_date = COALESCE(held_date, scheduled_date)
 *
 * `scheduled_date` remains the slot the session belongs to; `held_date` is set
 * only when the two differ. The unique key still guards the schedule slot, so
 * an early class no longer collides with the regular class it pulls forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // The slot this session occupies in the student's timetable.
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();

            // Set ONLY for an early class: the day the work was really done.
            // NULL for every ordinary session.
            $table->date('held_date')->nullable();

            // The date this session is PAID for, derived once by the database
            // instead of re-parsed by every query. Declared here rather than in
            // a later ALTER because SQLite (test suite) only accepts generated
            // columns as part of CREATE TABLE. Both operands are declared above,
            // which MySQL requires for a generated expression.
            $table->date('paid_date')->storedAs('COALESCE(held_date, scheduled_date)');

            // NULL = not yet marked. Legacy had 189 such rows, and the earnings
            // queries relied on `status IN ('present','absent')` to skip them.
            $table->enum('status', ['present', 'absent', 'postponed'])->nullable();

            // Who caused it. Teacher-absent is a payroll deduction;
            // student-absent still pays the teacher.
            $table->enum('absent_by', ['student', 'teacher', 'other'])->nullable();
            $table->enum('postponed_by', ['student', 'teacher', 'other'])->nullable();

            // Free text again, but now genuinely free text: no date is encoded
            // in it and nothing parses it.
            $table->text('postpone_reason')->nullable();

            $table->time('makeup_time')->nullable();
            $table->date('rescheduled_date')->nullable();
            $table->time('rescheduled_time')->nullable();

            // Attendance is money, so record who marked it.
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();

            $table->timestamps();

            // Carried over from legacy `uniq_tp`: one session per student slot.
            $table->unique(['instructor_id', 'student_id', 'scheduled_date'], 'class_sessions_slot_unique');

            $table->index(['instructor_id', 'scheduled_date']);
            $table->index(['student_id', 'scheduled_date']);

            // The payout report's hot path: one instructor, one date window.
            $table->index(['instructor_id', 'paid_date']);
            $table->index('paid_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
