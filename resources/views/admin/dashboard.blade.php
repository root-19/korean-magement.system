@extends('layouts.app')

@section('title', 'Admin dashboard')
@section('heading', 'Admin dashboard')
@section('subheading', 'Payout week '.$window->label())

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Instructors" value="{{ $counts['instructors'] }}" icon="users" tone="brand"
                     hint="Active" />

        <x-stat-card label="Students" value="{{ $counts['students'] }}" icon="users"
                     hint="Approved and active" />

        <x-stat-card label="Awaiting approval" value="{{ $counts['pending'] }}" icon="user-plus"
                     :tone="$counts['pending'] > 0 ? 'warning' : 'default'"
                     hint="Enrolled by instructors" />

        <x-stat-card label="Unassigned" value="{{ $counts['unassigned'] }}" icon="alert"
                     :tone="$counts['unassigned'] > 0 ? 'danger' : 'default'"
                     hint="No instructor" />
    </div>

    {{-- The approval queue is the one thing only an admin can unblock. --}}
    @if ($pending->isNotEmpty())
        <div class="card-accent mt-6 p-5">
            <div class="flex flex-wrap items-center gap-4">
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-bold text-white">
                        {{ $counts['pending'] }} {{ Str::plural('enrolment', $counts['pending']) }} waiting on you
                    </h2>
                    <p class="mt-0.5 text-sm text-white/80">
                        A student cannot be taught or billed until their enrolment is approved.
                    </p>
                </div>

                <a href="{{ route('admin.enrollments.index') }}"
                   class="focus-ring shrink-0 rounded-lg bg-white px-5 py-2.5 text-sm font-bold text-accent-600 shadow-lg transition hover:bg-brand-50">
                    Review queue
                </a>
            </div>

            <ul class="mt-4 flex flex-wrap gap-2">
                @foreach ($pending as $enrollment)
                    <li class="rounded-lg bg-black/20 px-3 py-1.5 text-xs font-medium text-white">
                        {{ $enrollment->user?->name }}
                        <span class="text-white/60">· {{ $enrollment->instructor?->name ?? 'unassigned' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        {{-- Payroll leaderboard --}}
        <div class="lg:col-span-2">
            <x-card title="This week's payroll" :subtitle="$window->label()" flush>
                <x-slot:actions>
                    <a href="{{ route('admin.payouts.index') }}" class="btn-secondary btn-sm">Open payroll</a>
                </x-slot:actions>

                @if ($topInstructors->isEmpty())
                    <x-empty-state icon="wallet"
                                   title="No earnings recorded this week"
                                   message="Sessions appear here once instructors mark attendance and file their reports." />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Instructor</th>
                                    <th class="text-center">Sessions</th>
                                    <th class="text-right">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topInstructors->take(8) as $row)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.instructors.show', $row['instructor']) }}"
                                               class="focus-ring flex items-center gap-2.5 rounded">
                                                <x-avatar :user="$row['instructor']" class="h-8 w-8" />
                                                <span class="truncate font-medium text-white">
                                                    {{ $row['instructor']->name }}
                                                </span>
                                            </a>
                                        </td>
                                        <td class="numeric text-center text-gray-300">{{ $row['sessions'] }}</td>
                                        <td class="numeric text-right font-semibold text-brand-400">
                                            @money($row['net'])
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-gray-700">
                                <tr>
                                    <td class="px-4 py-3 text-right text-sm font-medium text-gray-400" colspan="2">
                                        Academy total
                                    </td>
                                    <td class="numeric px-4 py-3 text-right text-base font-bold text-brand-400">
                                        @money($topInstructors->sum('net'))
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-4">
            {{-- Attendance mix for the week --}}
            <x-card title="Sessions this week">
                @php
                    $mix = [
                        ['label' => 'Present', 'value' => $weekTotals['present'], 'class' => 'bg-success-500'],
                        ['label' => 'Student absent', 'value' => $weekTotals['student_absent'], 'class' => 'bg-warning-500'],
                        ['label' => 'Teacher absent', 'value' => $weekTotals['teacher_absent'], 'class' => 'bg-danger-500'],
                        ['label' => 'Postponed', 'value' => $weekTotals['postponed'], 'class' => 'bg-gray-500'],
                    ];
                    $mixTotal = max(1, array_sum(array_column($mix, 'value')));
                @endphp

                <p class="numeric text-3xl font-bold text-white">{{ $weekTotals['total'] }}</p>
                <p class="text-xs text-gray-400">Total class slots</p>

                <span class="mt-4 flex h-2 gap-px overflow-hidden rounded-full bg-gray-700">
                    @foreach ($mix as $slice)
                        @if ($slice['value'] > 0)
                            <span class="{{ $slice['class'] }}"
                                  style="width: {{ $slice['value'] / $mixTotal * 100 }}%"
                                  title="{{ $slice['label'] }}: {{ $slice['value'] }}"></span>
                        @endif
                    @endforeach
                </span>

                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach ($mix as $slice)
                        <li class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2 text-gray-300">
                                <span class="h-2 w-2 rounded-full {{ $slice['class'] }}"></span>
                                {{ $slice['label'] }}
                            </span>
                            <span class="numeric font-medium text-white">{{ $slice['value'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            {{-- Audit trail --}}
            <x-card title="Recent activity" flush>
                @if ($recentActivity->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-400">Nothing recorded yet.</p>
                @else
                    <ul class="divide-y divide-gray-700/50">
                        @foreach ($recentActivity as $entry)
                            <li class="px-4 py-2.5">
                                <p class="text-sm text-gray-200">
                                    <span class="font-medium text-brand-400">{{ $entry->action }}</span>
                                    @if ($entry->target_name)
                                        <span class="text-gray-400">· {{ $entry->target_name }}</span>
                                    @endif
                                </p>
                                <p class="numeric mt-0.5 text-[11px] text-gray-500">
                                    {{ $entry->user?->name ?? 'system' }} ·
                                    {{ $entry->created_at?->diffForHumans() }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
