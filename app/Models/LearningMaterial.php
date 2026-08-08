<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A PDF an admin publishes for instructors.
 *
 * The file is on the `local` disk, outside the web root; it is only ever handed
 * out by LearningMaterialController::download(), which checks the session first.
 */
class LearningMaterial extends Model
{
    use HasFactory;

    /** The disk holding the PDFs — private, not `public`. */
    public const DISK = 'local';

    /** Where on that disk. */
    public const DIRECTORY = 'learning-materials';

    protected $fillable = [
        'folder_id',
        'title',
        'description',
        'file_path',
        'original_name',
        'file_size',
        'uploaded_by',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    // ---------------------------------------------------------------- relations

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Null means the material sits outside any folder — shown as Uncategorised. */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(LearningMaterialFolder::class, 'folder_id');
    }

    // ------------------------------------------------------------------- scopes

    /** What an instructor is allowed to see. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Delete the file behind this material. Called before the row goes.
     */
    public function deleteFile(): void
    {
        if ($this->file_path && Storage::disk(self::DISK)->exists($this->file_path)) {
            Storage::disk(self::DISK)->delete($this->file_path);
        }
    }

    /**
     * "1.4 MB" — the size an instructor sees before starting a download.
     */
    public function readableSize(): string
    {
        $bytes = $this->file_size;

        if ($bytes <= 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0).' '.$units[$unit];
    }
}
