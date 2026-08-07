<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity and authentication only.
 *
 * The legacy `users` table was a god-table: it carried admin, instructor and
 * student rows side by side, plus 20-odd student-only columns (instructor_id,
 * learning_time, semester, monday_time..sunday_time, teaching_methods, ...)
 * that were NULL for two thirds of every row.
 *
 * Role-specific attributes now live in `instructor_profiles` and
 * `student_profiles`; the weekly timetable lives in `student_schedules`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Legacy `username`. Not unique: the live data has two colliding
            // names, and students are identified by an "A540 Hyun Seo" style
            // code embedded in the name rather than by a unique handle.
            $table->string('name', 120);

            // Nullable because 222 of 256 legacy users have no email at all —
            // students are enrolled by their instructor, not self-registered.
            // MySQL permits many NULLs under a unique index; the importer
            // normalises '' to NULL so blanks never collide.
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Legacy role 'user' is renamed 'student' — it never meant
            // "generic user", every such row was an enrolled student.
            $table->enum('role', ['admin', 'instructor', 'student'])->index();

            $table->string('phone', 30)->nullable();
            $table->date('birthday')->nullable();
            $table->string('avatar_path')->nullable();

            // Replaces the legacy status ENUM('active','inactive').
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_login_at')->nullable();

            // Traceability back to the old row. Signed, because hard-deleted
            // students survive in legacy attendance as NEGATIVE ids and the
            // importer restores them under those keys.
            $table->integer('legacy_id')->nullable()->unique();

            $table->rememberToken();
            $table->timestamps();

            // Soft deletes are the whole reason the legacy backup tables
            // existed. Keeping the row means attendance and earnings never
            // need a snapshot copy to survive a deletion.
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
