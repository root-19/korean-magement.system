<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Sign in') · {{ config('app.name') }}</title>

    <script>
        (function () {
            var stored = localStorage.getItem('theme');
            var dark = stored ? stored === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
    <div class="mx-auto w-full max-w-sm">
        <div class="flex flex-col items-center">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">
                10
            </span>
            <h1 class="mt-4 text-center text-lg font-semibold text-white">
                {{ config('app.name') }}
            </h1>
            <p class="mt-1 text-center text-sm text-gray-500">
                @yield('tagline', 'Sign in to your account')
            </p>
        </div>

        <div class="card mt-6 p-6">
            <x-flash />
            @yield('content')
        </div>

        <p class="mt-6 text-center text-xs text-gray-400">
            &copy; {{ date('Y') }} {{ config('app.name') }}
        </p>
    </div>
</div>
</body>
</html>
