<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting every student in a filtered view of the admin roster at once.
 *
 * Limited to the "No sessions left" view: those are the students whose plan
 * is used up, and the only list where clearing everything is a routine job.
 */
class DestroyStudentsRequest extends FormRequest
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
            'filter' => ['required', 'string', 'in:no_sessions'],
            'q' => ['nullable', 'string', 'max:255'],
            'instructor' => ['nullable', 'integer'],
        ];
    }
}
