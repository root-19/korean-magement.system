@extends('layouts.app')

@section('title', 'Profile')
@section('heading', 'Profile')
@section('subheading', 'Your details and password')

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form method="POST" action="{{ route('instructor.profile.update') }}"
                  enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PATCH')

                <x-card title="Your details">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-4">
                            <x-avatar :user="$instructor" class="h-16 w-16" />
                            <div class="min-w-0 flex-1">
                                <label for="avatar" class="form-label">Profile photo</label>
                                <input id="avatar" name="avatar" type="file" accept="image/*"
                                       class="form-input file:mr-3 file:rounded-md file:border-0 file:bg-brand-400 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-900">
                                @error('avatar') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="name" class="form-label">Name</label>
                                <input id="name" name="name" type="text" required
                                       value="{{ old('name', $instructor->name) }}" class="form-input">
                                @error('name') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="email" class="form-label">Email</label>
                                <input id="email" name="email" type="email"
                                       value="{{ old('email', $instructor->email) }}" class="form-input">
                                @error('email') <p class="form-error">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-xs text-gray-500">Used to sign in and reset your password.</p>
                            </div>

                            <div>
                                <label for="phone" class="form-label">Phone</label>
                                <input id="phone" name="phone" type="text"
                                       value="{{ old('phone', $instructor->phone) }}" class="form-input">
                                @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="birthday" class="form-label">Birthday</label>
                                <input id="birthday" name="birthday" type="date"
                                       value="{{ old('birthday', $instructor->birthday?->toDateString()) }}"
                                       class="form-input numeric">
                                @error('birthday') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="bio" class="form-label">Bio</label>
                            <textarea id="bio" name="bio" rows="4" class="form-textarea"
                                      placeholder="Shown on your public profile page">{{ old('bio', $profile?->bio) }}</textarea>
                            @error('bio') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-card>

                <x-card title="Payout details" subtitle="Where your earnings are sent">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="bank_name" class="form-label">Bank</label>
                            <input id="bank_name" name="bank_name" type="text"
                                   value="{{ old('bank_name', $profile?->bank_name) }}" class="form-input">
                            @error('bank_name') <p class="form-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bank_account" class="form-label">Account number</label>
                            <input id="bank_account" name="bank_account" type="text"
                                   value="{{ old('bank_account', $profile?->bank_account) }}"
                                   class="form-input numeric">
                            @error('bank_account') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-card>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary">Save changes</button>
                </div>
            </form>

            {{-- Password. Separate form so saving details never touches it. --}}
            <x-card title="Change password">
                <form method="POST" action="{{ route('instructor.profile.password') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="current_password" class="form-label">Current password</label>
                        <input id="current_password" name="current_password" type="password" required
                               autocomplete="current-password" class="form-input">
                        @error('current_password') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            Required — it proves the change is really you.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="password" class="form-label">New password</label>
                            <input id="password" name="password" type="password" required
                                   autocomplete="new-password" class="form-input">
                            @error('password') <p class="form-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required
                                   autocomplete="new-password" class="form-input">
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn-secondary">Change password</button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card title="Account">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'Role' => $instructor->role->label(),
                        'Status' => $instructor->is_active ? 'Active' : 'Inactive',
                        'Students' => $instructor->students()->count(),
                        'Last login' => $instructor->last_login_at?->format('M j, Y g:i A') ?? 'Never',
                        'Joined' => $instructor->created_at?->format('M j, Y'),
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-400">{{ $label }}</dt>
                            <dd class="truncate text-right font-medium text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Your public schedule">
                <p class="text-sm text-gray-400">
                    Students see your published hours on your profile page and on the
                    home page.
                </p>
                <a href="{{ route('instructor.schedule.index') }}" class="btn-secondary mt-3 w-full">
                    <x-icon name="calendar" class="h-4 w-4" />
                    Manage schedule
                </a>
            </x-card>
        </div>
    </div>
@endsection
