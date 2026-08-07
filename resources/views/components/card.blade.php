@props(['title' => null, 'subtitle' => null, 'flush' => false])

{{--
    Panel with an optional header and an `actions` slot.

        <x-card title="Earnings" subtitle="This week">
            <x-slot:actions><button class="btn-secondary btn-sm">Export</button></x-slot:actions>
            ...
        </x-card>

    `flush` drops the body padding — use it when the body is a full-bleed table.
--}}

<section {{ $attributes->merge(['class' => 'card animate-fadeIn overflow-hidden']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center gap-3 border-b border-gray-700 px-4 py-4 sm:px-5">
            <div class="min-w-0 flex-1">
                @if ($title)
                    <h2 class="heading truncate text-lg">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 truncate text-xs text-gray-400">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['px-4 py-4 sm:px-5' => ! $flush])>
        {{ $slot }}
    </div>
</section>
