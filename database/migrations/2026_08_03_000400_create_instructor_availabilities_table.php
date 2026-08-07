<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an instructor is open for bookings. Replaces `teacher_schedules`.
 *
 * `day_of_week` was a VARCHAR(20) holding 'Monday'; it is now the same ISO-8601
 * integer used by student_schedules, so the two can be compared directly when
 * matching a student's slot against an instructor's availability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();

            // 1 = Monday ... 7 = Sunday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');

            // Legacy status ENUM('available','not_available').
            $table->boolean('is_available')->default(true);

            $table->timestamps();

            $table->unique(
                ['instructor_id', 'day_of_week', 'start_time', 'end_time'],
                'instructor_availabilities_slot_unique'
            );
            $table->index(['day_of_week', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_availabilities');
    }
};
