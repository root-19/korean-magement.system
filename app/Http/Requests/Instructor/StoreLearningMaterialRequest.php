<?php

namespace App\Http\Requests\Instructor;

use App\Models\LearningMaterial;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An instructor posting a teaching resource of their own.
 *
 * The file rules match the admin upload: a PDF, checked by reported content
 * type rather than by extension, under the ceiling the model defines. An
 * instructor cannot create folders, so `folder_id` may only name one that
 * already exists — null is fine and shows as Uncategorised.
 *
 * Authorisation is the route group's `role:instructor` middleware, as it is
 * for every other instructor action.
 */
class StoreLearningMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'mimetypes:application/pdf',
                'max:'.LearningMaterial::MAX_KILOBYTES,
            ],
            'folder_id' => ['nullable', 'integer', 'exists:learning_material_folders,id'],
        ];
    }
}
