@extends('layouts.app')

@section('title', 'Audit System')
@section('heading', 'Audit System')
@section('subheading', $window->label().($window->isCurrent() ? ' · current cut-off' : ''))

@section('actions')
    <form method="GET" class="flex items-center gap-1.5">
        <a href="{{ route('admin.attendance-audit.index', ['week' => $window->previous()->startDate()]) }}"
           class="btn-ghost !p-2" aria-label="Previous cut-off">
            <x-icon name="chevron-left" class="h-4 w-4" />
        </a>

        <select name="week" onchange="this.form.submit()" class="form-select !w-auto !py-1.5 text-xs" aria-label="Cut-off">
            @foreach ($windows as $option)
                <option value="{{ $option->startDate() }}" @selected($option->key() === $window->key())>
                    {{ $option->label() }}{{ $option->isCurrent() ? ' (current)' : '' }}
                </option>
            @endforeach
        </select>

        @if (! $window->isCurrent())
            <a href="{{ route('admin.attendance-audit.index', ['week' => $window->next()->startDate()]) }}"
               class="btn-ghost !p-2" aria-label="Next cut-off">
                <x-icon name="chevron-right" class="h-4 w-4" />
            </a>
        @endif
    </form>
@endsection

@section('content')
    @php
        // Chart colours: validated for the dark card surface — see x-line-chart.
        $chartSeries = [
            ['label' => 'Present', 'color' => '#199e70', 'values' => array_column($daily, 'present')],
            ['label' => 'Absent', 'color' => '#d95926', 'values' => array_column($daily, 'absent')],
            ['label' => 'Postponed', 'color' => '#3987e5', 'values' => array_column($daily, 'postponed')],
        ];
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-stat-card label="Total earnings" :value="money($totals['earned'])" icon="wallet" tone="brand"
                     hint="{{ money($totals['gross']) }} gross · −{{ money($totals['deductions']) }} deductions" />

        <x-stat-card label="Total minutes" value="{{ number_format($totals['total_minutes']) }}" icon="clock"
                     hint="All marked classes" />

        <x-stat-card label="Minutes used" value="{{ number_format($totals['used_minutes']) }}" icon="check-circle"
                     hint="Present classes only" />

        @foreach (App\Enums\TeachingMethod::cases() as $method)
            <x-stat-card :label="$method->label().' minutes'"
                         value="{{ number_format($totals['by_method'][$method->value]['total']) }}"
                         :icon="$method->isVideo() ? 'video' : 'chat'"
                         hint="{{ number_format($totals['by_method'][$method->value]['used']) }} used (Present)" />
        @endforeach

        <x-stat-card label="Present" value="{{ number_format($totals['present']) }}" icon="check" tone="success" />

        <x-stat-card label="Absent" value="{{ number_format($totals['absent']) }}" icon="x" tone="danger"
                     hint="{{ $totals['student_absent'] }} student · {{ $totals['teacher_absent'] }} teacher" />

        <x-stat-card label="Postponed" value="{{ number_format($totals['postponed']) }}" icon="refresh" tone="warning" />
    </div>

    <x-card class="mt-6">
        <x-slot:title>Classes per day</x-slot:title>
        <x-slot:subtitle>Every teacher, {{ $window->label() }}</x-slot:subtitle>

        <x-line-chart :labels="array_map(fn ($day) => $day['date']->format('D j'), $daily)"
                      :tooltip-labels="array_map(fn ($day) => $day['date']->format('l, M j'), $daily)"
                      :series="$chartSeries" />

        <details class="mt-4 text-sm">
            <summary class="cursor-pointer text-xs text-gray-400">Show as table</summary>

            <div class="table-wrap mt-2">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th class="text-right">Present</th>
                            <th class="text-right">Absent</th>
                            <th class="text-right">Postponed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($daily as $day)
                            <tr>
                                <td class="whitespace-nowrap">{{ $day['date']->format('D, M j') }}</td>
                                <td class="numeric text-right">{{ $day['present'] ?? '—' }}</td>
                                <td class="numeric text-right">{{ $day['absent'] ?? '—' }}</td>
                                <td class="numeric text-right">{{ $day['postponed'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </x-card>

    <x-card class="mt-6" flush>
        <x-slot:title>Teachers</x-slot:title>
        <x-slot:subtitle>
            No class type set counts as Audio, as payroll pays it. Earned is the live payroll figure: a class pays once its report is filed.
        </x-slot:subtitle>

        @if ($teachers->isEmpty())
            <x-empty-state icon="chart"
                           title="No marked classes in this cut-off"
                           message="Once teachers mark Present, Absent or Postponed, their figures appear here." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        {{-- Grouped so the column labels can stay short: ten columns
                             with full labels ran past the card at desktop width. --}}
                        <tr>
                            <th rowspan="2" class="align-bottom">Teacher</th>
                            <th colspan="5" class="border-l border-gray-700 text-center">Minutes</th>
                            <th colspan="3" class="border-l border-gray-700 text-center">Classes</th>
                            <th rowspan="2" class="border-l border-gray-700 text-right align-bottom">Earned</th>
                        </tr>
                        <tr>
                            @foreach (App\Enums\TeachingMethod::cases() as $method)
                                <th @class(['text-right', 'border-l border-gray-700' => $loop->first]) title="{{ $method->label() }} minutes">
                                    {{ str_replace(['(', ')'], '', $method->label()) }}
                                </th>
                            @endforeach
                            <th class="text-right">Total</th>
                            <th class="text-right" title="Present classes only">Used</th>
                            <th class="border-l border-gray-700 text-right">Present</th>
                            <th class="text-right">Absent</th>
                            <th class="text-right">Postponed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teachers as $row)
                            <tr>
                                <td class="whitespace-nowrap font-medium text-white">{{ $row['name'] }}</td>
                                @foreach (App\Enums\TeachingMethod::cases() as $method)
                                    <td class="numeric text-right text-gray-300">
                                        {{ number_format($row['by_method'][$method->value]['total']) }}
                                    </td>
                                @endforeach
                                <td class="numeric text-right font-medium text-white">{{ number_format($row['total_minutes']) }}</td>
                                <td class="numeric text-right">{{ number_format($row['used_minutes']) }}</td>
                                <td class="numeric text-right">{{ $row['present'] }}</td>
                                <td class="numeric text-right">
                                    {{ $row['absent'] }}
                                    @if ($row['teacher_absent'] > 0)
                                        <span class="block whitespace-nowrap text-xs text-danger-400">
                                            {{ $row['teacher_absent'] }} teacher
                                        </span>
                                    @endif
                                </td>
                                <td class="numeric text-right">{{ $row['postponed'] }}</td>
                                <td class="numeric whitespace-nowrap text-right font-semibold text-brand-400">
                                    @money($row['earned'])
                                    @if ($row['deductions'] > 0)
                                        <span class="block text-xs font-normal text-danger-400">−@money($row['deductions'])</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-700 font-semibold text-white [&>td]:px-4 [&>td]:py-3">
                            <td>Total</td>
                            @foreach (App\Enums\TeachingMethod::cases() as $method)
                                <td class="numeric text-right">{{ number_format($totals['by_method'][$method->value]['total']) }}</td>
                            @endforeach
                            <td class="numeric text-right">{{ number_format($totals['total_minutes']) }}</td>
                            <td class="numeric text-right">{{ number_format($totals['used_minutes']) }}</td>
                            <td class="numeric text-right">{{ $totals['present'] }}</td>
                            <td class="numeric text-right">{{ $totals['absent'] }}</td>
                            <td class="numeric text-right">{{ $totals['postponed'] }}</td>
                            <td class="numeric whitespace-nowrap text-right text-brand-400">@money($totals['earned'])</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>
@endsection
