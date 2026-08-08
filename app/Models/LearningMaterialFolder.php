<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of learning materials. One level deep — see the migration.
 */
class LearningMaterialFolder extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'created_by'];

    public function materials(): HasMany
    {
        return $this->hasMany(LearningMaterial::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
