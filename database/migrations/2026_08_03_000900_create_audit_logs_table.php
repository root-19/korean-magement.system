<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only trail for actions that move money or destroy data.
 *
 * Replaces legacy `audit_log`, whose (target_table, target_id) pair is now a
 * proper polymorphic relation so the subject can be resolved to a model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: a system/scheduled action has no actor. nullOnDelete so
            // deleting an admin never erases the trail of what they did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 60);

            // Legacy target_table + target_id.
            $table->nullableMorphs('auditable');

            // Name captured at the time of the action, so the log still reads
            // correctly after the subject is renamed or removed.
            $table->string('target_name')->nullable();

            $table->json('details')->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index('action');
            $table->index(['user_id', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
