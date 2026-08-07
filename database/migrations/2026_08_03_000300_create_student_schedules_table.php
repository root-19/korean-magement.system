<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's weekly timetable, one row per class day.
 *
 * Replaces two legacy columns that had to be read together:
 *
 *   users.schedule      TEXT  -- "Monday,Wednesday,Friday"
 *   users.monday_time   TIME  -- 18:30:00
 *   users.tuesday_time  TIME
 *   ... through sunday_time
 *
 * Eight columns for what is a one-to-many relation. Answering "who does this
 * instructor teach on Wednesday?" meant `FIND_IN_SET`/`LIKE '%Wednesday%'`
 * against a comma-joined string, which no index can serve. Now it is an
 * indexed integer comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // ISO-8601 day number: 1 = Monday ... 7 = Sunday, matching
            // Carbon::dayOfWeekIso so no lookup table is needed.
            $table->unsignedTinyInteger('day_of_week');

            $table->time('start_time');

            $table->timestamps();

            // One class per student per weekday — exactly what the legacy
            // single `<day>_time` column could express.
            $table->unique(['student_id', 'day_of_week']);
            $table->index(['day_of_week', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_schedules');
    }
};
