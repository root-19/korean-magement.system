@props(['user', 'src' => null])

{{--
    Avatar with an initials fallback.

    Student names embed an enrolment code ("A540 Hyun Seo"), so User::initials()
    skips it and yields "HS" rather than "AH".

    Legacy avatars were `rounded-full ... ring-yellow-400/50`; kept.
--}}

@php
    $path = $src ?? $user?->avatar_path;

    // Legacy stored bare filenames and served them from /uploads.
    $url = match (true) {
        $path === null || $path === '' => null,
        str_starts_with($path, 'http') => $path,
        str_contains($path, '/') => asset('storage/'.$path),
        default => asset('uploads/'.$path),
    };
@endphp

@if ($url)
    <img src="{{ $url }}"
         alt="{{ $user?->name }}"
         loading="lazy"
         {{ $attributes->merge(['class' => 'h-9 w-9 shrink-0 rounded-full object-cover ring-2 ring-brand-400/40']) }}>
@else
    <span {{ $attributes->merge(['class' => 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500/20 text-xs font-bold text-brand-400 ring-2 ring-brand-400/30']) }}
          aria-hidden="true">
        {{ $user?->initials() ?? '?' }}
    </span>
@endif
