@extends('layouts.app')

@section('title', 'Classes')
@section('heading', 'Classes')
@section('subheading', $date->format('l, F j, Y').' · '.$totals['total'].' '.Str::plural('slot', $totals['total']))

@section('actions')
    <form method="GET" class="flex items-center gap-1.5">
        <a href="{{ route('admin.classes.index', ['date' => $date->subDay()->toDateString()]) }}"
           class="btn-ghost !p-2" aria-label="Previous day">
            <x-icon name="chevron-left" class="h-4 w-4" />
        </a>

        <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()"
               class="form-input numeric !w-auto !py-1.5 text-xs" aria-label="Date">

        <a href="{{ route('admin.classes.index', ['date' => $date->addDay()->toDateString()]) }}"
           class="btn-ghost !p-2" aria-label="Next day">
            <x-icon name="chevron-right" class="h-4 w-4" />
        </a>
    </form>
@endsection

@section('content')
    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-5">
        <x-stat-card label="Present" value="{{ $totals['present'] }}" icon="check-circle" tone="success" />
        <x-stat-card label="Student absent" value="{{ $totals['student_absent'] }}" icon="x-circle" tone="warning" />
        <x-stat-card label="Teacher absent" value="{{ $totals['teacher_absent'] }}" icon="alert"
                     :tone="$totals['teacher_absent'] > 0 ? 'danger' : 'default'" />
        <x-stat-card label="Postponed" value="{{ $totals['postponed'] }}" icon="clock" />
        <x-stat-card label="Unmarked" value="{{ $totals['unmarked'] }}" icon="inbox"
                     :tone="$totals['unmarked'] > 0 ? 'warning' : 'default'" />
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">

            <select name="instructor" onchange="this.form.submit()" class="form-select !w-auto text-xs"
                    aria-label="Filter by instructor">
                <option value="">All instructors</option>
                @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" @selected((int) $selectedInstructor === $instructor->id)>
                        {{ $instructor->name }}
                    </option>
                @endforeach
            </select>

            <select name="status" onchange="this.form.submit()" class="form-select !w-auto text-xs"
                    aria-label="Filter by status">
                <option value="">Any status</option>
                @foreach (['present' => 'Present', 'absent' => 'Absent', 'postponed' => 'Postponed', 'unmarked' => 'Unmarked'] as $value => $label)
                    <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>

        @if (! $date->isToday())
            <a href="{{ route('admin.classes.index') }}" class="btn-ghost btn-sm">Back to today</a>
        @endif
    </div>

    <x-card class="mt-4" flush>
        @if ($sessions->isEmpty())
            <x-empty-state icon="calendar"
                           title="No classes on this day"
                           message="Nothing recorded for {{ $date->format('l, F j') }} under the current filters." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Instructor</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="numeric whitespace-nowrap text-gray-300">
                                    {{ $session->scheduled_time
                                        ? \Carbon\Carbon::parse($session->scheduled_time)->format('g:i A')
                                        : '—' }}
                                    @if ($session->isEarly())
                                        <span class="badge-brand mt-0.5 block w-fit"
                                              title="Occupies the {{ $session->scheduled_date->format('M j') }} slot">
                                            Early
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    <a href="{{ route('admin.instructors.show', $session->instructor_id) }}"
                                       class="focus-ring flex items-center gap-2 rounded">
                                        <x-avatar :user="$session->instructor" class="h-7 w-7" />
                                        <span class="truncate text-gray-200">{{ $session->instructor?->name }}</span>
                                    </a>
                                </td>

                                <td>
                                    <a href="{{ route('admin.students.show', $session->student_id) }}"
                                       class="focus-ring flex items-center gap-2 rounded">
                                        <x-avatar :user="$session->student" class="h-7 w-7" />
                                        <span class="truncate font-medium text-white">{{ $session->student?->name }}</span>
                                    </a>
                                </td>

                                <td class="whitespace-nowrap text-gray-400">
                                    {{ $session->student?->studentProfile?->teaching_method?->label() ?? '—' }}
                                    <span class="numeric text-xs text-gray-500">
                                        {{ $session->student?->studentProfile?->learning_time
                                            ? $session->student->studentProfile->learning_time.'m' : '' }}
                                    </span>
                                </td>

                                <td>
                                    @if ($session->status)
                                        <span class="{{ $session->status->badgeClass() }}">
                                            {{ $session->status->label() }}
                                            @if ($session->absent_by)
                                                ({{ $session->absent_by->label() }})
                                            @endif
                                        </span>
                                    @else
                                        <span class="badge-neutral">Not marked</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($reported->has($session->instructor_id.'|'.$session->student_id))
                                        <span class="badge-success">Filed</span>
                                    @elseif ($session->isPayable())
                                        <span class="badge-warning" title="Unpaid until the report is filed">Missing</span>
                                    @else
                                        <span class="text-xs text-gray-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($sessions->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">{{ $sessions->links() }}</div>
            @endif
        @endif
    </x-card>

    <p class="mt-4 text-xs text-gray-500">
        Listed by the date each class was taught, so an early class appears on the day it
        happened rather than on the future slot it covers.
    </p>
@endsection
