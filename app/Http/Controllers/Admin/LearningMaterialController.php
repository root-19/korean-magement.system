<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LearningMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                ->with('uploader:id,name')
                ->latest('id')
                ->paginate(20),
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
        ]);

        $file = $request->file('file');

        // Hashed name: two admins uploading "grammar.pdf" must not collide.
        $path = $file->store(LearningMaterial::DIRECTORY, LearningMaterial::DISK);

        $published = (bool) ($data['is_published'] ?? false);

        $material = LearningMaterial::create([
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
