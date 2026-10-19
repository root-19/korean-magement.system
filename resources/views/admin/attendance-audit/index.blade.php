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

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-stat-card label="Total minutes" value="{{ number_format($totals['total_minutes']) }}" icon="clock"
                     hint="All marked classes" />

        <x-stat-card label="Minutes used" value="{{ number_format($totals['used_minutes']) }}" icon="check-circle" tone="brand"
                     hint="Present classes only" />

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
                                <td class="numeric text-right">{{ $day['present'] }}</td>
                                <td class="numeric text-right">{{ $day['absent'] }}</td>
                                <td class="numeric text-right">{{ $day['postponed'] }}</td>
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
            Minutes use each student's class length. Classes are counted in the cut-off their payslip falls in.
        </x-slot:subtitle>

        @if ($teachers->isEmpty())
            <x-empty-state icon="chart"
                           title="No marked classes in this cut-off"
                           message="Once teachers mark Present, Absent or Postponed, their figures appear here." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th class="text-right">Total minutes</th>
                            <th class="text-right">Minutes used</th>
                            <th class="text-right">Present</th>
                            <th class="text-right">Absent</th>
                            <th class="text-right">Postponed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teachers as $row)
                            <tr>
                                <td class="whitespace-nowrap font-medium text-white">{{ $row['name'] }}</td>
                                <td class="numeric text-right">{{ number_format($row['total_minutes']) }}</td>
                                <td class="numeric text-right">{{ number_format($row['used_minutes']) }}</td>
                                <td class="numeric text-right">{{ $row['present'] }}</td>
                                <td class="numeric text-right">
                                    {{ $row['absent'] }}
                                    @if ($row['absent'] > 0)
                                        <span class="block text-xs text-gray-500">
                                            {{ $row['student_absent'] }} student · {{ $row['teacher_absent'] }} teacher
                                        </span>
                                    @endif
                                </td>
                                <td class="numeric text-right">{{ $row['postponed'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-700 font-semibold text-white">
                            <td>Total</td>
                            <td class="numeric text-right">{{ number_format($totals['total_minutes']) }}</td>
                            <td class="numeric text-right">{{ number_format($totals['used_minutes']) }}</td>
                            <td class="numeric text-right">{{ $totals['present'] }}</td>
                            <td class="numeric text-right">{{ $totals['absent'] }}</td>
                            <td class="numeric text-right">{{ $totals['postponed'] }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>
@endsection
