<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An instructor's request to mark attendance on a class that has already passed.
 *
 * Attendance is money: a marked session is what releases payment. Marking one
 * days later is therefore a payroll edit, not a correction, so it needs a
 * second pair of eyes. Same-day marking stays free; anything older is blocked
 * until an admin approves this request.
 *
 * One row per (instructor, student, class_date). A rejected request is reused
 * rather than duplicated — re-requesting resets it to pending, so the history of
 * who decided what stays on one row instead of scattering across many.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: DDL is not transactional in MySQL, so a run that fails later
        // leaves this table created but unrecorded, and a re-run would collide
        // with it.
        if (Schema::hasTable('attendance_requests')) {
            return;
        }

        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // The date of the class being asked about — matches class_sessions.paid_date.
            $table->date('class_date');

            // Why it was not marked on the day. Required from the instructor.
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->unique(['instructor_id', 'student_id', 'class_date'], 'attendance_requests_class_unique');

            // The admin queue reads pending first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_requests');
    }
};
