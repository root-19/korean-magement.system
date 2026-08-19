@extends('layouts.app')

@section('title', 'Add Instructor')
@section('heading', 'Add Instructor')
@section('subheading', 'Create an instructor account')

@section('actions')
    <a href="{{ route('admin.instructors.index') }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        All instructors
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.instructors.store') }}" class="grid gap-4 lg:grid-cols-3">
        @csrf

        <div class="space-y-4 lg:col-span-2">
            <x-card title="Instructor details">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" type="text" required value="{{ old('name') }}"
                               class="form-input">
                        @error('name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="phone" class="form-label">Phone</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone') }}" class="form-input">
                        @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="form-label">Email <span class="text-gray-500">(optional)</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" class="form-input">
                        @error('email') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            Without an email, they sign in with their exact name instead.
                        </p>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="password" class="form-label">Password <span class="text-gray-500">(optional)</span></label>
                        <input id="password" name="password" type="text" value="{{ old('password') }}"
                               minlength="8" class="form-input" placeholder="Leave blank to generate one">
                        @error('password') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            Leave blank to generate a random password, shown to you once after saving.
                            At least 8 characters if you set your own.
                        </p>
                    </div>
                </div>
            </x-card>

            <x-card title="Bank details" subtitle="Optional — can be filled in later from their profile">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="bank_name" class="form-label">Bank name</label>
                        <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name') }}"
                               class="form-input">
                        @error('bank_name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="bank_account" class="form-label">Account number</label>
                        <input id="bank_account" name="bank_account" type="text" value="{{ old('bank_account') }}"
                               class="form-input numeric">
                        @error('bank_account') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card title="What happens next">
                <p class="text-sm text-gray-300">
                    The account is active immediately, ready to be assigned students.
                </p>

                <p class="mt-3 rounded-lg bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                    The temporary password is shown once, on the confirmation banner
                    after saving. Note it down then.
                </p>
            </x-card>

            <div class="flex flex-col gap-2">
                <button type="submit" class="btn-primary">
                    <x-icon name="user-plus" class="h-4 w-4" />
                    Add instructor
                </button>
                <a href="{{ route('admin.instructors.index') }}" class="btn-secondary">Cancel</a>
            </div>
        </div>
    </form>
@endsection
