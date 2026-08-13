<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An instructor's request to delete one of their students.
 *
 * A student is attached to attendance, reports and therefore to payroll, so
 * removing one is not a self-service button: the instructor states a reason, an
 * admin decides, and only an approval carries it out.
 *
 * `student_id` is nullable and nulls on delete — deliberately, and unlike every
 * other reference to `users` in this schema. This row is the record of who
 * approved the removal, so it must outlive the student under any deletion,
 * including a force-delete run outside the app. `student_name` keeps it
 * readable afterwards, the same way audit_logs.target_name does.
 *
 * One row per (instructor, student). A rejected request is reused rather than
 * duplicated — asking again resets it to pending, so the decision history stays
 * on one row instead of scattering across many.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: DDL is not transactional in MySQL, so a run that fails later
        // leaves this table created but unrecorded, and a re-run would collide
        // with it.
        if (Schema::hasTable('student_deletion_requests')) {
            return;
        }

        Schema::create('student_deletion_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();

            // Snapshot, so the queue and the audit trail still read correctly
            // once the student row is gone.
            $table->string('student_name', 120);

            // Why the instructor wants the student removed. Required from them.
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            // Many NULLs are permitted under a unique index, so approved rows
            // (whose student_id has been nulled by the deletion) never collide.
            $table->unique(['instructor_id', 'student_id'], 'student_deletion_requests_student_unique');

            // The admin queue reads pending first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_deletion_requests');
    }
};
