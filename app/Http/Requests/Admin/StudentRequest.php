<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * What the admin enrol and edit forms have in common.
 *
 * Both post the same account fields, the same weekly timetable and the same
 * instructor picker; only the plan fields differ, so only rules() is left to
 * the subclass.
 */
abstract class StudentRequest extends FormRequest
{
    /** Both routes sit inside the `role:admin` middleware group. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The validated payload, normalised for the service layer.
     *
     * @return array<string, mixed>
     */
    public function studentData(): array
    {
        $data = $this->validated();

        return array_merge($data, [
            'is_regular' => $this->boolean('is_regular'),
            // An unticked day submits a blank time. Dropping it here is what
            // makes unticking a day delete its schedule row.
            'schedule' => array_filter($data['schedule'] ?? []),
        ]);
    }

    /**
     * An instructor who still exists and has not been deleted.
     *
     * `exists` alone would accept a soft-deleted one — the rule does not apply
     * the model's global scopes.
     */
    protected function instructorRule(): Exists
    {
        return Rule::exists('users', 'id')
            ->where('role', Role::Instructor->value)
            ->whereNull('deleted_at');
    }
}
