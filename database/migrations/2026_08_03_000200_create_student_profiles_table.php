<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student-only attributes, lifted off the users god-table.
 *
 * COLUMN RENAMES — the legacy names actively misled:
 *
 *   users.semester        -> sessions_remaining
 *       Never a semester. It is a countdown of prepaid classes: marking a
 *       student present did `semester = GREATEST(semester - 1, 0)`, and
 *       ClassModel::getRegularClassInfo() selected it as
 *       `u.semester AS remaining_sessions`.
 *
 *   users.present_count   -> sessions_attended
 *   users.deduction_days  -> sessions_deducted
 *       Sessions written off at enrolment; subtracted from the purchased
 *       total at registration time.
 *
 *   users.regular         -> is_regular   (was the string 'regular' or '')
 *   users.teaching_methods-> teaching_method (singular; only ever held one)
 *
 * Session accounting identity, as rendered on the feedback page:
 *
 *   purchased = sessions_attended
 *             + student-absent sessions
 *             + sessions_remaining
 *             + sessions_deducted
 *
 * A student-absent class consumes a prepaid session (remaining goes down) but
 * is neither "attended" nor "remaining", which is why it is its own term.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Assigned instructor. nullOnDelete so losing an instructor leaves
            // the student unassigned rather than cascading the student away.
            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();

            // Drives the pay rate — see config/academy.php.
            $table->enum('teaching_method', ['audio', 'video_kids', 'video_adults'])->nullable();

            // Minutes per class (legacy values: 20, 25, 30).
            $table->unsignedSmallInteger('learning_time')->nullable();

            $table->unsignedSmallInteger('sessions_remaining')->default(0);
            $table->unsignedSmallInteger('sessions_attended')->default(0);
            $table->unsignedSmallInteger('sessions_deducted')->default(0);

            $table->boolean('is_regular')->default(true);

            // Instructors enrol students, an admin approves them. Legacy stored
            // NULL for "pre-dates the approval flow", which read as approved.
            $table->enum('enrollment_status', ['pending', 'approved', 'rejected'])
                ->default('approved')
                ->index();
            $table->timestamp('enrollment_decided_at')->nullable();
            $table->foreignId('enrollment_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Korean students are contacted over KakaoTalk, not email.
            $table->string('kakaotalk_id', 100)->nullable();

            $table->timestamps();

            $table->index(['instructor_id', 'enrollment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
