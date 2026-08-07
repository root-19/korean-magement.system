@props([
    'label',
    'value',
    'icon' => null,
    'hint' => null,
    'trend' => null,      // signed number: positive is good, negative is bad
    'tone' => 'default',  // default | success | danger | warning | brand
])

{{--
    Stat tile. Legacy shape: a gray-800 -> gray-900 gradient panel with the
    figure in yellow-400 and the icon in a tinted square.
--}}

@php
    $valueTones = [
        'default' => 'text-white',
        'success' => 'text-success-400',
        'danger' => 'text-danger-400',
        'warning' => 'text-warning-400',
        'brand' => 'text-brand-400',
    ];

    $iconTones = [
        'default' => 'bg-gray-700/60 text-gray-300',
        'success' => 'bg-success-500/15 text-success-400',
        'danger' => 'bg-danger-500/15 text-danger-400',
        'warning' => 'bg-warning-500/15 text-warning-400',
        'brand' => 'bg-brand-500/15 text-brand-400',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'card animate-fadeInUp p-5']) }}>
    <div class="flex items-start gap-3">
        @if ($icon)
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $iconTones[$tone] }}">
                <x-icon :name="$icon" class="h-5 w-5" />
            </span>
        @endif

        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                {{ $label }}
            </p>

            <p class="numeric mt-1 text-3xl font-bold leading-tight {{ $valueTones[$tone] }}">
                {{ $value }}
            </p>

            @if ($hint || $trend !== null)
                <p class="mt-1 flex items-center gap-1.5 text-xs text-gray-400">
                    @if ($trend !== null)
                        <span @class([
                            'numeric font-semibold',
                            'text-success-400' => $trend > 0,
                            'text-danger-400' => $trend < 0,
                        ])>
                            {{ $trend > 0 ? '+' : '' }}{{ $trend }}
                        </span>
                    @endif
                    @if ($hint)
                        <span class="truncate">{{ $hint }}</span>
                    @endif
                </p>
            @endif
        </div>
    </div>
</div>
