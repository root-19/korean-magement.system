<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InstructorProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The instructor's own profile. Replaces app/views/instructor/profile.php plus
 * AuthController::updateInstructorProfile and ::updateInstructorPassword.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $instructor = $request->user();
        $instructor->load('instructorProfile');

        return view('instructor.profile.edit', [
            'instructor' => $instructor,
            'profile' => $instructor->instructorProfile,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($instructor->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:60'],
            'avatar' => ['nullable', 'image', 'max:4096'],
        ]);

        $instructor->fill([
            'name' => $data['name'],
            // Normalised to NULL so a blank does not collide under the unique
            // index — most legacy accounts have no email at all.
            'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
            'phone' => $data['phone'] ?? null,
            'birthday' => $data['birthday'] ?? null,
        ]);

        if ($request->hasFile('avatar')) {
            $instructor->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $instructor->save();

        InstructorProfile::updateOrCreate(
            ['user_id' => $instructor->id],
            [
                'bio' => $data['bio'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
            ],
        );

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Change the instructor's own password.
     *
     * The legacy endpoint took the new password and wrote it without ever
     * checking the current one, so anyone with a borrowed session could lock the
     * real owner out.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $instructor->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $instructor->update(['password' => $data['password']]);

        AuditLog::record(
            action: 'password.changed',
            subject: $instructor,
            targetName: $instructor->name,
            userId: $instructor->id,
        );

        return back()->with('success', 'Password changed.');
    }
}
