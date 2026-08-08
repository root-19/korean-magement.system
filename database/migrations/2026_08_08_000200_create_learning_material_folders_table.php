<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders for learning materials.
 *
 * One level deep on purpose: the library is a few dozen PDFs an instructor
 * browses, not a filesystem. A tree would cost breadcrumbs, move-between-folders
 * and orphan handling for no gain at this size.
 *
 * `folder_id` on a material is NULLABLE and nullOnDelete: a material without a
 * folder is fine (it shows as Uncategorised), and deleting a folder moves its
 * materials there rather than deleting them with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_material_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('name');
        });

        Schema::table('learning_materials', function (Blueprint $table) {
            $table->foreignId('folder_id')
                ->nullable()
                ->after('id')
                ->constrained('learning_material_folders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('learning_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('learning_material_folders');
    }
};
