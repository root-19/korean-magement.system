@props([
    'icon' => 'inbox',
    'title' => 'Nothing here yet',
    'message' => null,
])

{{--
    Shown in place of an empty table or list. States what is missing and, via the
    default slot, what to do about it — an empty screen with no next step is the
    single most common dead end in the legacy app.
--}}

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-700/60 text-gray-400">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>

    <p class="mt-3 text-sm font-semibold text-white">{{ $title }}</p>

    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-gray-400">{{ $message }}</p>
    @endif

    @if (trim($slot) !== '')
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
