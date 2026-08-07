<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\LearningMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Learning materials as an instructor sees them: published PDFs, downloadable.
 */
class LearningMaterialController extends Controller
{
    public function index(): View
    {
        return view('instructor.materials.index', [
            'materials' => LearningMaterial::query()
                ->published()
                ->with('uploader:id,name')
                ->latest('published_at')
                ->latest('id')
                ->paginate(20),
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
