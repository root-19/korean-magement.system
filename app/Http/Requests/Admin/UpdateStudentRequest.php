<?php

namespace App\Http\Requests\Admin;

use App\Enums\EnrollmentStatus;
use App\Enums\TeachingMethod;
use Illuminate\Validation\Rule;

/**
 * Editing every detail of an existing student.
 *
 * The plan fields are nullable here and required on the enrol form: legacy rows
 * arrived with a blank teaching method or duration, and forcing a value on the
 * next edit would silently restate what that student's classes are worth.
 */
class UpdateStudentRequest extends StudentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('student')->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'kakaotalk_id' => ['nullable', 'string', 'max:100'],

            'instructor_id' => ['nullable', 'integer', $this->instructorRule()],

            'teaching_method' => ['nullable', Rule::enum(TeachingMethod::class)],
            'learning_time' => ['nullable', 'integer', Rule::in(config('academy.learning_times'))],
            'is_regular' => ['required', 'boolean'],

            // Bounded by the column rather than by a tidier business figure: the
            // counters are unsignedSmallInteger, and a legacy row already sitting
            // above a lower limit must still be saveable.
            'sessions_remaining' => ['required', 'integer', 'min:0', 'max:65535'],
            'sessions_attended' => ['required', 'integer', 'min:0', 'max:65535'],
            'sessions_deducted' => ['required', 'integer', 'min:0', 'max:65535'],

            'enrollment_status' => ['required', Rule::enum(EnrollmentStatus::class)],
            'rejection_reason' => ['nullable', 'string', 'max:500'],

            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'schedule' => ['nullable', 'array'],
            'schedule.*' => ['nullable', 'date_format:H:i'],
        ];
    }
}
