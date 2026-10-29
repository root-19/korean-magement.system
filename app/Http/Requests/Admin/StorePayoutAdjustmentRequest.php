<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crediting an instructor one paid class by hand.
 */
class StorePayoutAdjustmentRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'paid_date' => ['required', 'date'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
