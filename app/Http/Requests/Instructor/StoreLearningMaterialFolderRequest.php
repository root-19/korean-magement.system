<?php

namespace App\Http\Requests\Instructor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An instructor adding a category to the shared library.
 *
 * Categories are shared, not per-instructor, so the name is unique across the
 * table and a new one is immediately offered to everybody. Only the name is
 * asked for: the description an admin can set is not worth a second field on a
 * form whose whole point is to get out of the way of the upload beneath it.
 *
 * Authorisation is the route group's `role:instructor` middleware.
 */
class StoreLearningMaterialFolderRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', 'unique:learning_material_folders,name'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('A category called that already exists — pick it from the list instead.'),
        ];
    }
}
