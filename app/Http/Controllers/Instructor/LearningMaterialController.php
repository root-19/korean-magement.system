<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\LearningMaterial;
use App\Models\LearningMaterialFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Learning materials as an instructor sees them: published PDFs, downloadable.
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
            return view('instructor.materials.index', [
                'folder' => null,
                'materials' => null,

                // Only folders with something published in them: a folder whose
                // contents are all drafts would open onto an empty shelf.
                'folders' => LearningMaterialFolder::query()
                    ->withCount(['materials as published_count' => fn ($q) => $q->where('is_published', true)])
                    ->orderBy('name')
                    ->get()
                    ->filter(fn ($folder) => $folder->published_count > 0)
                    ->values(),

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
