<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LearningMaterial;
use App\Models\LearningMaterialFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin side of learning materials: post a PDF, publish it, remove it.
 *
 * Uploads go to the `local` disk, outside the web root. Nothing here writes to a
 * publicly served directory, so a material cannot be fetched by guessing a URL —
 * see the download action on the instructor-facing controller.
 */
class LearningMaterialController extends Controller
{
    /** Bytes. 20 MB is generous for a PDF and small enough to survive a shared host. */
    private const MAX_KILOBYTES = 20480;

    public function index(): View
    {
        return view('admin.materials.index', [
            'materials' => LearningMaterial::query()
                ->with(['uploader:id,name', 'folder:id,name'])
                ->latest('id')
                ->paginate(20),
            'folders' => LearningMaterialFolder::query()
                ->withCount('materials')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // mimetypes checks the file's reported content type, not just the
            // extension, so a renamed .exe does not pass.
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.self::MAX_KILOBYTES],

            'is_published' => ['nullable', 'boolean'],

            // Null is allowed: a material with no folder shows as Uncategorised.
            'folder_id' => ['nullable', 'integer', 'exists:learning_material_folders,id'],
        ]);

        $file = $request->file('file');

        // Hashed name: two admins uploading "grammar.pdf" must not collide.
        $path = $file->store(LearningMaterial::DIRECTORY, LearningMaterial::DISK);

        $published = (bool) ($data['is_published'] ?? false);

        $material = LearningMaterial::create([
            'folder_id' => $data['folder_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);

        AuditLog::record(
            action: 'material.uploaded',
            subject: $material,
            targetName: $material->title,
            details: ['published' => $published],
            userId: $request->user()->id,
        );

        return back()->with('success', $published
            ? "\"{$material->title}\" is published and visible to instructors."
            : "\"{$material->title}\" is saved as a draft. Publish it when it is ready.");
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:learning_material_folders,name'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        LearningMaterialFolder::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', "Folder \"{$data['name']}\" created.");
    }

    /**
     * Remove a folder. Its materials are NOT deleted with it — `folder_id` is
     * nullOnDelete, so they fall back to Uncategorised and stay downloadable.
     */
    public function destroyFolder(Request $request, LearningMaterialFolder $folder): RedirectResponse
    {
        $count = $folder->materials()->count();
        $name = $folder->name;

        $folder->delete();

        AuditLog::record(
            action: 'material_folder.deleted',
            targetName: $name,
            details: ['materials_moved' => $count],
            userId: $request->user()->id,
        );

        return back()->with('success', $count > 0
            ? "Folder \"{$name}\" removed. Its {$count} ".Str::plural('material', $count).' moved to Uncategorised.'
            : "Folder \"{$name}\" removed.");
    }

    /**
     * Publish a draft, or pull a published material back out of sight.
     */
    public function togglePublished(Request $request, LearningMaterial $material): RedirectResponse
    {
        $material->update([
            'is_published' => ! $material->is_published,
            'published_at' => $material->is_published ? null : now(),
        ]);

        AuditLog::record(
            action: $material->is_published ? 'material.published' : 'material.unpublished',
            subject: $material,
            targetName: $material->title,
            userId: $request->user()->id,
        );

        return back()->with('success', $material->is_published
            ? "\"{$material->title}\" is now visible to instructors."
            : "\"{$material->title}\" is hidden from instructors.");
    }

    /**
     * Remove a material, file included — an orphaned PDF on disk is nobody's.
     */
    public function destroy(Request $request, LearningMaterial $material): RedirectResponse
    {
        $title = $material->title;

        $material->deleteFile();
        $material->delete();

        AuditLog::record(
            action: 'material.deleted',
            targetName: $title,
            userId: $request->user()->id,
        );

        return back()->with('success', "\"{$title}\" was deleted.");
    }
}
