<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning materials an admin publishes for instructors to download.
 *
 * The PDF itself lives on the `local` disk, outside the web root, and is served
 * by an authenticated controller action. Only the path is stored here — nothing
 * about a material is reachable without a session.
 *
 * `original_name` is kept separately from `file_path` because the stored name is
 * a generated hash: two admins uploading "grammar.pdf" must not collide, while
 * the instructor should still download a file called "grammar.pdf".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_materials', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedInteger('file_size')->default(0);

            // Uploaded material outlives the admin account that posted it.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Drafts are invisible to instructors, so a material can be prepared
            // before it is handed out.
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // The instructor list: published only, newest first.
            $table->index(['is_published', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_materials');
    }
};
