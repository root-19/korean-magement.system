<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid class credited to an instructor by hand, outside the class_sessions
 * attendance record.
 *
 * Earnings are computed from class_sessions, so the only way to pay for a
 * class whose session row is missing or must not be altered was to edit the
 * student's attendance. An adjustment is a payslip line in its own right: it
 * is picked up by the earnings calculator for the week of `paid_date`, flows
 * into a finalised payout like any other line, and leaves the student's
 * sessions untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();

            // Kept verbatim so the line still reads after the student is renamed or deleted.
            $table->string('student_name');

            // The day the class is paid for; decides which payout week it lands in.
            $table->date('paid_date');

            $table->enum('teaching_method', ['audio', 'video_kids', 'video_adults'])->nullable();
            $table->unsignedSmallInteger('learning_time');
            $table->decimal('amount', 10, 2);

            $table->text('note');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['instructor_id', 'paid_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_adjustments');
    }
};
