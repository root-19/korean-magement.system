@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="login" class="form-label">Email or username</label>
            <input id="login"
                   name="login"
                   type="text"
                   value="{{ old('login') }}"
                   required
                   autofocus
                   autocomplete="username"
                   class="form-input"
                   @error('login') aria-invalid="true" aria-describedby="login-error" @enderror>

            @error('login')
                <p id="login-error" class="form-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="form-label">Password</label>
            <div x-data="{ show: false }" class="relative">
                <input id="password"
                       name="password"
                       :type="show ? 'text' : 'password'"
                       type="password"
                       required
                       autocomplete="current-password"
                       class="form-input pr-10"
                       @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>

                <button type="button"
                        x-on:click="show = !show"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 transition hover:text-gray-200"
                        :aria-label="show ? 'Hide password' : 'Show password'">
                    <x-icon name="search" class="h-4 w-4" x-show="!show" />
                    <x-icon name="x" class="h-4 w-4" x-show="show" x-cloak />
                </button>
            </div>

            @error('password')
                <p id="password-error" class="form-error">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-300">
            <input type="checkbox"
                   name="remember"
                   value="1"
                   class="rounded border-gray-600 text-brand-400 focus:ring-brand-500 bg-gray-800">
            Keep me signed in
        </label>

        <button type="submit" class="btn-primary w-full">Sign in</button>
    </form>
@endsection
