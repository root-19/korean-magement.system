<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instructor-only attributes.
 *
 * Merges the legacy `instructor_profiles` table with the instructor-only
 * columns that were sitting on the users god-table (bank_name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('bio')->nullable();
            $table->string('voice_intro_path')->nullable();

            // Legacy had credential_image_1/2/3 — three fixed columns that
            // capped an instructor at exactly three credentials. A JSON array
            // of paths lifts the cap without a schema change per slot.
            $table->json('credential_paths')->nullable();

            // Payout destination, previously users.bank_name.
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account', 60)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_profiles');
    }
};
