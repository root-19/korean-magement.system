@extends('layouts.app')

@section('title', __('Audit System'))
@section('heading', __('Audit System'))
@section('subheading', $window->label().($window->isCurrent() ? ' · '.__('current cut-off') : ''))

@section('actions')
    <form method="GET" class="flex items-center gap-1.5">
        <a href="{{ route('admin.attendance-audit.index', ['week' => $window->previous()->startDate()]) }}"
           class="btn-ghost !p-2" aria-label="{{ __('Previous cut-off') }}">
            <x-icon name="chevron-left" class="h-4 w-4" />
        </a>

        <select name="week" onchange="this.form.submit()" class="form-select !w-auto !py-1.5 text-xs" aria-label="{{ __('Cut-off') }}">
            @foreach ($windows as $option)
                <option value="{{ $option->startDate() }}" @selected($option->key() === $window->key())>
                    {{ $option->label() }}{{ $option->isCurrent() ? ' ('.__('current').')' : '' }}
                </option>
            @endforeach
        </select>

        @if (! $window->isCurrent())
            <a href="{{ route('admin.attendance-audit.index', ['week' => $window->next()->startDate()]) }}"
               class="btn-ghost !p-2" aria-label="{{ __('Next cut-off') }}">
                <x-icon name="chevron-right" class="h-4 w-4" />
            </a>
        @endif
    </form>
@endsection

@section('content')
    @php
        // Chart colours: validated for the dark card surface — see x-line-chart.
        $chartSeries = [
            ['label' => __('Present'), 'color' => '#199e70', 'values' => array_column($daily, 'present')],
            ['label' => __('Absent'), 'color' => '#d95926', 'values' => array_column($daily, 'absent')],
            ['label' => __('Postponed'), 'color' => '#3987e5', 'values' => array_column($daily, 'postponed')],
        ];
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-stat-card :label="__('Total earnings')" :value="money($totals['earned'])" icon="wallet" tone="brand"
                     :hint="__(':gross gross · −:deductions deductions', ['gross' => money($totals['gross']), 'deductions' => money($totals['deductions'])])" />

        <x-stat-card :label="__('Total minutes')" value="{{ number_format($totals['total_minutes']) }}" icon="clock"
                     :hint="__('All marked classes')" />

        <x-stat-card :label="__('Minutes used')" value="{{ number_format($totals['used_minutes']) }}" icon="check-circle"
                     :hint="__('Present classes only')" />

        @foreach (App\Enums\TeachingMethod::cases() as $method)
            <x-stat-card :label="__(':method minutes', ['method' => $method->label()])"
                         value="{{ number_format($totals['by_method'][$method->value]['total']) }}"
                         :icon="$method->isVideo() ? 'video' : 'chat'"
                         :hint="__(':minutes used (Present)', ['minutes' => number_format($totals['by_method'][$method->value]['used'])])" />
        @endforeach

        <x-stat-card :label="__('Present')" value="{{ number_format($totals['present']) }}" icon="check" tone="success" />

        <x-stat-card :label="__('Absent')" value="{{ number_format($totals['absent']) }}" icon="x" tone="danger"
                     :hint="__(':student student · :teacher teacher', ['student' => $totals['student_absent'], 'teacher' => $totals['teacher_absent']])" />

        <x-stat-card :label="__('Postponed')" value="{{ number_format($totals['postponed']) }}" icon="refresh" tone="warning" />
    </div>

    <x-card class="mt-6">
        <x-slot:title>{{ __('Classes per day') }}</x-slot:title>
        <x-slot:subtitle>{{ __('Every teacher, :week', ['week' => $window->label()]) }}</x-slot:subtitle>

        <x-line-chart :labels="array_map(fn ($day) => $day['date']->translatedFormat('D j'), $daily)"
                      :tooltip-labels="array_map(fn ($day) => $day['date']->translatedFormat('l, M j'), $daily)"
                      :series="$chartSeries" />

        <details class="mt-4 text-sm">
            <summary class="cursor-pointer text-xs text-gray-400">{{ __('Show as table') }}</summary>

            <div class="table-wrap mt-2">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Day') }}</th>
                            <th class="text-right">{{ __('Present') }}</th>
                            <th class="text-right">{{ __('Absent') }}</th>
                            <th class="text-right">{{ __('Postponed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($daily as $day)
                            <tr>
                                <td class="whitespace-nowrap">{{ $day['date']->translatedFormat('D, M j') }}</td>
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
        <x-slot:title>{{ __('Teachers') }}</x-slot:title>
        <x-slot:subtitle>
            {{ __('No class type set counts as Audio, as payroll pays it. Earned is the live payroll figure: a class pays once its report is filed.') }}
        </x-slot:subtitle>

        @if ($teachers->isEmpty())
            <x-empty-state icon="chart"
                           :title="__('No marked classes in this cut-off')"
                           :message="__('Once teachers mark Present, Absent or Postponed, their figures appear here.')" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        {{-- Grouped so the column labels can stay short: ten columns
                             with full labels ran past the card at desktop width. --}}
                        <tr>
                            <th rowspan="2" class="align-bottom">{{ __('Teacher') }}</th>
                            <th colspan="5" class="border-l border-gray-700 text-center">{{ __('Minutes') }}</th>
                            <th colspan="3" class="border-l border-gray-700 text-center">{{ __('Classes') }}</th>
                            <th rowspan="2" class="border-l border-gray-700 text-right align-bottom">{{ __('Earned') }}</th>
                        </tr>
                        <tr>
                            @foreach (App\Enums\TeachingMethod::cases() as $method)
                                <th @class(['text-right', 'border-l border-gray-700' => $loop->first]) title="{{ __(':method minutes', ['method' => $method->label()]) }}">
                                    {{ str_replace(['(', ')'], '', $method->label()) }}
                                </th>
                            @endforeach
                            <th class="text-right">{{ __('Total') }}</th>
                            <th class="text-right" title="{{ __('Present classes only') }}">{{ __('Used') }}</th>
                            <th class="border-l border-gray-700 text-right">{{ __('Present') }}</th>
                            <th class="text-right">{{ __('Absent') }}</th>
                            <th class="text-right">{{ __('Postponed') }}</th>
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
                                            {{ __(':count teacher', ['count' => $row['teacher_absent']]) }}
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
                            <td>{{ __('Total') }}</td>
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
