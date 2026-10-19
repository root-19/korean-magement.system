@props([
    'labels',          // x-axis labels, one per point
    'series',          // [['label' => string, 'color' => hex, 'values' => (int|null)[]], ...] — null = no data yet
    'tooltipLabels' => null, // longer x labels for the tooltip; defaults to $labels
    'surface' => '#1f2937',  // card surface, for the marker rings
])

{{--
    Multi-series line chart as inline SVG — no chart library.

    Colours are validated for the dark card surface (dataviz validator: lightness
    band, chroma, colour-blind separation, contrast). Identity never rides on
    colour alone: there is always a legend, and end labels are added where they
    do not collide. The crosshair tooltip lists every series at the hovered or
    focused day; the same numbers are in the table the page renders beside it.
--}}

@php
    $width = 800;
    $height = 260;
    $pad = ['top' => 16, 'right' => 56, 'bottom' => 28, 'left' => 36];
    $plotW = $width - $pad['left'] - $pad['right'];
    $plotH = $height - $pad['top'] - $pad['bottom'];

    $count = count($labels);
    $known = fn (array $values) => array_filter($values, fn ($v) => $v !== null);
    $max = max(1, ...array_map(fn ($s) => max([0, ...$known($s['values'])]), $series));

    // Clean tick step: 1, 2 or 5 × 10^n, about four intervals.
    $raw = $max / 4;
    $magnitude = 10 ** floor(log10(max($raw, 1)));
    $step = collect([1, 2, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($s) => $s >= $raw);
    $top = (int) (ceil($max / $step) * $step);
    $ticks = range(0, $top, $step);

    $x = fn (int $i) => $pad['left'] + ($count > 1 ? $i * $plotW / ($count - 1) : $plotW / 2);
    $y = fn (int|float $v) => $pad['top'] + $plotH - ($v / $top) * $plotH;

    // A line is drawn through consecutive known values only, so a day with no
    // data yet breaks it instead of pulling it to zero.
    $segments = fn (array $values) => collect($values)
        ->map(fn ($v, $i) => $v === null ? null : $x($i).','.$y($v))
        ->chunkWhile(fn ($point, $i, $chunk) => ($point === null) === ($chunk->last() === null))
        ->reject(fn ($chunk) => $chunk->first() === null)
        ->map(fn ($chunk) => $chunk->implode(' '));

    // End labels sit on each series' last known point; one is kept only if it
    // is clear of the last one kept.
    $endLabels = collect($series)
        ->map(function ($s) use ($known, $y) {
            $values = $known($s['values']);
            $last = array_key_last($values);

            return $last === null ? null : [
                'label' => $s['label'], 'value' => $values[$last], 'index' => $last, 'y' => $y($values[$last]),
            ];
        })
        ->filter()
        ->sortBy('y')
        ->reduce(function ($kept, $label) {
            $last = $kept->last();

            return $last === null || $label['y'] - $last['y'] >= 14 ? $kept->push($label) : $kept;
        }, collect());

    $points = collect(range(0, $count - 1))->map(fn ($i) => [
        'x' => round($x($i) / $width * 100, 3),
        'label' => ($tooltipLabels ?? $labels)[$i],
        'rows' => collect($series)->map(fn ($s) => [
            'label' => $s['label'],
            'color' => $s['color'],
            'value' => $s['values'][$i],
        ])->all(),
    ]);
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-300">
        @foreach ($series as $s)
            <li class="flex items-center gap-1.5">
                <svg class="h-2 w-4" viewBox="0 0 16 8" aria-hidden="true">
                    <line x1="0" y1="4" x2="16" y2="4" stroke="{{ $s['color'] }}" stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ $s['label'] }}
            </li>
        @endforeach
    </ul>

    {{-- Scrolls inside the card on a phone rather than shrinking the text. --}}
    <div class="overflow-x-auto">
    <div class="relative min-w-[560px]" x-data="{ active: null, points: @js($points) }" x-on:mouseleave="active = null">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="block h-auto w-full" role="img"
             aria-label="{{ collect($series)->pluck('label')->implode(', ') }} {{ __('per day') }}">
            @foreach ($ticks as $tick)
                <line x1="{{ $pad['left'] }}" x2="{{ $width - $pad['right'] }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}"
                      stroke="#374151" stroke-width="1" />
                <text x="{{ $pad['left'] - 8 }}" y="{{ $y($tick) + 4 }}" text-anchor="end"
                      class="fill-gray-400" font-size="11" style="font-variant-numeric: tabular-nums">{{ $tick }}</text>
            @endforeach

            @foreach ($labels as $i => $label)
                <text x="{{ $x($i) }}" y="{{ $height - 8 }}" text-anchor="middle" class="fill-gray-400" font-size="11">{{ $label }}</text>
            @endforeach

            <line x-show="active !== null" x-cloak
                  x-bind:x1="active === null ? 0 : points[active].x / 100 * {{ $width }}"
                  x-bind:x2="active === null ? 0 : points[active].x / 100 * {{ $width }}"
                  y1="{{ $pad['top'] }}" y2="{{ $pad['top'] + $plotH }}" stroke="#6b7280" stroke-width="1" />

            @foreach ($series as $s)
                @foreach ($segments($s['values']) as $points)
                    <polyline fill="none" stroke="{{ $s['color'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"
                              points="{{ $points }}" />
                @endforeach

                @foreach ($known($s['values']) as $i => $v)
                    <circle cx="{{ $x($i) }}" cy="{{ $y($v) }}" r="4" fill="{{ $s['color'] }}"
                            stroke="{{ $surface }}" stroke-width="2" />
                @endforeach
            @endforeach

            @foreach ($endLabels as $end)
                <text x="{{ $x($end['index']) + 10 }}" y="{{ $end['y'] + 4 }}" class="fill-gray-300" font-size="11"
                      style="font-variant-numeric: tabular-nums">{{ $end['value'] }} {{ $end['label'] }}</text>
            @endforeach

            {{-- Hit targets: a full-height column per day, wider than any mark. --}}
            @foreach ($labels as $i => $label)
                <rect x="{{ $x($i) - ($count > 1 ? $plotW / ($count - 1) / 2 : $plotW / 2) }}" y="0"
                      width="{{ $count > 1 ? $plotW / ($count - 1) : $plotW }}" height="{{ $height }}"
                      fill="transparent" tabindex="0" class="focus:outline-none"
                      aria-label="{{ ($tooltipLabels ?? $labels)[$i] }}: {{ collect($series)->map(fn ($s) => $s['label'].' '.($s['values'][$i] ?? __('no data yet')))->implode(', ') }}"
                      x-on:mouseenter="active = {{ $i }}" x-on:focus="active = {{ $i }}" x-on:blur="active = null" />
            @endforeach
        </svg>

        <template x-if="active !== null">
            <div class="pointer-events-none absolute top-2 z-10 min-w-[9rem] -translate-x-1/2 rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 text-xs shadow-xl"
                 x-bind:style="`left: min(max(${points[active].x}%, 4.5rem), calc(100% - 4.5rem))`">
                <p class="mb-1 text-gray-400" x-text="points[active].label"></p>
                <template x-for="row in points[active].rows" x-bind:key="row.label">
                    <p class="flex items-center gap-2">
                        <span class="inline-block h-0.5 w-3 rounded" x-bind:style="`background: ${row.color}`"></span>
                        <span class="numeric font-semibold text-white" x-text="row.value ?? '—'"></span>
                        <span class="text-gray-400" x-text="row.label"></span>
                    </p>
                </template>
            </div>
        </template>
    </div>
    </div>
</div>
