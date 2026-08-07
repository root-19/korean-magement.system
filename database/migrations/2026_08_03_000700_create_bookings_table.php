<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trial-class requests from the public instructor profile page.
 *
 * A booking is a prospect, not yet a user: there is no account behind it until
 * an instructor confirms and enrols them, hence the free-text contact fields.
 *
 * The legacy version was created at runtime by BookingModel::ensureTable() —
 * a CREATE TABLE IF NOT EXISTS plus six conditional ALTER TABLEs executed on
 * every single instantiation of the model. That is now a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();

            $table->string('student_name', 120);
            $table->string('kakaotalk_id', 100);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();

            $table->date('session_date');
            $table->time('session_time');

            // How many trial sessions are being requested.
            $table->unsignedTinyInteger('sessions')->default(1);

            $table->enum('teaching_method', ['audio', 'video_kids', 'video_adults'])->nullable();
            $table->unsignedSmallInteger('learning_time')->nullable();

            // Requested weekly pattern, kept as submitted. Normalised into
            // student_schedules only once the booking is converted to a student.
            $table->json('requested_schedule')->nullable();
            $table->date('start_date')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();

            // Set when a confirmed booking becomes an enrolled student.
            $table->foreignId('converted_student_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index('session_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
