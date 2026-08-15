<?php

namespace App\Http\Requests\Admin;

use App\Enums\TeachingMethod;
use Illuminate\Validation\Rule;

/**
 * Enrolling a student from the admin area.
 *
 * The same shape the instructor enrol form submits, plus the instructor to
 * assign — an admin never enrols on their own behalf.
 */
class StoreStudentRequest extends StudentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'kakaotalk_id' => ['nullable', 'string', 'max:100'],

            'instructor_id' => ['nullable', 'integer', $this->instructorRule()],

            'teaching_method' => ['required', Rule::enum(TeachingMethod::class)],
            'learning_time' => ['required', 'integer', Rule::in(config('academy.learning_times'))],

            'sessions_purchased' => ['required', 'integer', 'min:1', 'max:500'],
            // Written off at enrolment, so it can never exceed what was bought.
            'sessions_deducted' => ['nullable', 'integer', 'min:0', 'lte:sessions_purchased'],

            // Required, not defaulted: regular and trial are different kinds of
            // enrolment and whoever fills the form is the one who knows which.
            'is_regular' => ['required', 'boolean'],

            'start_date' => ['nullable', 'date'],

            'schedule' => ['nullable', 'array'],
            'schedule.*' => ['nullable', 'date_format:H:i'],
        ];
    }
}
