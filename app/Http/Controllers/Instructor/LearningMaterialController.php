<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StoreLearningMaterialRequest;
use App\Models\AuditLog;
use App\Models\LearningMaterial;
use App\Models\LearningMaterialFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Learning materials as an instructor sees them: published PDFs, downloadable,
 * plus the resources they post themselves.
 *
 * An instructor upload is published on the spot — there is no draft step for
 * one — so it reaches the other instructors and the student dashboard as soon
 * as it is saved. They may delete what they posted and nothing else; an
 * admin-posted material, or another instructor's, goes through the admin page.
 */
class LearningMaterialController extends Controller
{
    /** The query value standing in for "no folder". */
    private const LOOSE = 'none';

    /**
     * Folders first; the materials inside one only once it is opened.
     *
     * Drill-down by query string rather than an accordion: it works without
     * JavaScript, every folder is a shareable URL, and the back link is a real
     * link. Same reasoning as the dashboard calendar.
     */
    public function index(Request $request): View
    {
        $selected = $request->query('folder');

        if ($selected === null) {
            $folders = LearningMaterialFolder::query()
                ->withCount(['materials as published_count' => fn ($q) => $q->where('is_published', true)])
                ->orderBy('name')
                ->get();

            return view('instructor.materials.index', [
                'folder' => null,
                'materials' => null,

                // Only folders with something published in them: a folder whose
                // contents are all drafts would open onto an empty shelf.
                'folders' => $folders->filter(fn ($item) => $item->published_count > 0)->values(),

                // The upload form lists every folder, empty ones included —
                // the same fetch, so an empty shelf still has somewhere to go.
                'folderOptions' => $folders,

                'looseCount' => LearningMaterial::query()->published()->whereNull('folder_id')->count(),
            ]);
        }

        $folder = $selected === self::LOOSE
            ? null
            : LearningMaterialFolder::query()->findOrFail($selected);

        return view('instructor.materials.index', [
            'folder' => $folder,
            'folderLabel' => $folder?->name ?? 'Uncategorised',
            'folders' => null,
            'folderOptions' => LearningMaterialFolder::query()->orderBy('name')->get(['id', 'name']),
            'materials' => LearningMaterial::query()
                ->published()
                ->when($folder, fn ($q) => $q->where('folder_id', $folder->id))
                ->when($folder === null, fn ($q) => $q->whereNull('folder_id'))
                ->with('uploader:id,name')
                ->latest('published_at')
                ->latest('id')
                ->get(),
        ]);
    }

    /**
     * An instructor posts a resource of their own.
     *
     * Published immediately: an instructor has no draft step, so the material
     * is on the shelf for every instructor and student the moment it is saved.
     * The stored name is a hash, so two instructors posting "grammar.pdf" do
     * not collide, while the download still hands back the original name.
     */
    public function store(StoreLearningMaterialRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $file = $request->file('file');

        $material = LearningMaterial::create([
            'folder_id' => $data['folder_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $file->store(LearningMaterial::DIRECTORY, LearningMaterial::DISK),
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
            'is_published' => true,
            'published_at' => now(),
        ]);

        AuditLog::record(
            action: 'material.uploaded',
            subject: $material,
            targetName: $material->title,
            details: ['published' => true, 'by_instructor' => true],
            userId: $request->user()->id,
        );

        return back()->with('success', __('":title" is posted and available to everyone.', [
            'title' => $material->title,
        ]));
    }

    /**
     * Remove a material, file included.
     *
     * Only what this instructor posted. Anything from an admin or another
     * instructor is not theirs to take down, and the file is deleted with the
     * row so no orphaned PDF is left on disk.
     */
    public function destroy(Request $request, LearningMaterial $material): RedirectResponse
    {
        abort_unless($material->wasPostedBy($request->user()), 403);

        $title = $material->title;

        $material->deleteFile();
        $material->delete();

        AuditLog::record(
            action: 'material.deleted',
            targetName: $title,
            details: ['by_instructor' => true],
            userId: $request->user()->id,
        );

        return back()->with('success', __('":title" was deleted.', ['title' => $title]));
    }

    /**
     * Hand over the file.
     *
     * The PDF is not in the web root, so this action is the only way to reach it
     * and every request passes through auth. A draft is downloadable by an admin
     * — who can see it in their own list — and 404s for an instructor, matching
     * what each role is shown.
     */
    public function download(Request $request, LearningMaterial $material): StreamedResponse
    {
        abort_unless($material->is_published || $request->user()->isAdmin(), 404);

        $disk = Storage::disk(LearningMaterial::DISK);

        abort_unless($disk->exists($material->file_path), 404, 'That file is no longer on the server.');

        return $disk->download($material->file_path, $material->original_name);
    }
}
