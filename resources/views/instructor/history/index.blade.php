@extends('layouts.app')

@section('title', 'Class History')
@section('heading', 'Class History')
@section('subheading', $totals['total'].' '.Str::plural('class', $totals['total']).' taught'.($totals['first_class'] ? ' since '.\Carbon\Carbon::parse($totals['first_class'])->format('M Y') : ''))

@section('content')
    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-5">
        <x-stat-card label="Total classes" value="{{ $totals['total'] }}" icon="book" tone="brand" />
        <x-stat-card label="Present" value="{{ $totals['present'] }}" icon="check-circle" tone="success" />
        <x-stat-card label="Absent" value="{{ $totals['absent'] }}" icon="x-circle" tone="warning" />
        <x-stat-card label="Students taught" value="{{ $totals['students'] }}" icon="users" />
        <x-stat-card label="Reports filed" value="{{ $totals['reports'] }}" icon="clipboard"
                     hint="{{ $totals['present'] > 0 ? round($totals['reports'] / max(1, $totals['present']) * 100).'% of present' : '' }}" />
    </div>

    {{-- Date range + status filter --}}
    <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="from" class="form-label !text-xs">From</label>
            <input id="from" type="date" name="from" value="{{ $from }}" class="form-input numeric !w-auto !py-1.5 text-xs">
        </div>
        <div>
            <label for="to" class="form-label !text-xs">To</label>
            <input id="to" type="date" name="to" value="{{ $to }}" class="form-input numeric !w-auto !py-1.5 text-xs">
        </div>
        <div>
            <label for="status" class="form-label !text-xs">Status</label>
            <select id="status" name="status" class="form-select !w-auto !py-1.5 text-xs">
                <option value="">Any</option>
                @foreach (['present' => 'Present', 'absent' => 'Absent', 'postponed' => 'Postponed'] as $value => $label)
                    <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn-primary btn-sm">Filter</button>

        @if ($from || $to || $selectedStatus)
            <a href="{{ route('instructor.history.index') }}" class="btn-ghost btn-sm">Clear</a>
        @endif
    </form>

    <x-card class="mt-4" flush>
        @if ($sessions->isEmpty())
            <x-empty-state icon="book"
                           title="No classes in this range"
                           message="Marked classes appear here permanently — this record is never deleted." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="numeric whitespace-nowrap">
                                    {{ $session->paid_date->format('D, M j, Y') }}
                                    @if ($session->isEarly())
                                        <span class="badge-brand mt-0.5 block w-fit"
                                              title="Occupied the {{ $session->scheduled_date->format('M j') }} slot">
                                            Early
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <x-avatar :user="$session->student" class="h-8 w-8" />
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-white">
                                                {{ $session->student?->name ?? 'Deleted student' }}
                                            </span>
                                            @if ($session->student?->trashed())
                                                {{-- Archiving a student never erases the record of
                                                     having taught them. --}}
                                                <span class="badge-neutral mt-0.5">Archived</span>
                                            @endif
                                        </span>
                                    </div>
                                </td>

                                <td class="whitespace-nowrap text-gray-400">
                                    {{ $session->student?->studentProfile?->teaching_method?->label() ?? '—' }}
                                    <span class="numeric text-xs text-gray-500">
                                        {{ $session->student?->studentProfile?->learning_time
                                            ? $session->student->studentProfile->learning_time.'m' : '' }}
                                    </span>
                                </td>

                                <td>
                                    <span class="{{ $session->status->badgeClass() }}">
                                        {{ $session->status->label() }}
                                        @if ($session->absent_by)
                                            ({{ $session->absent_by->label() }})
                                        @endif
                                    </span>
                                </td>

                                <td>
                                    @if ($session->report)
                                        {{-- Bound to the report, so it opens even for a student
                                             who has since been archived or reassigned. --}}
                                        <a href="{{ route('instructor.reports.edit', $session->report) }}"
                                           class="btn-secondary btn-sm">
                                            <x-icon name="pencil" class="h-3.5 w-3.5" />
                                            Edit report
                                        </a>
                                    @elseif ($session->isPayable())
                                        <a href="{{ route('instructor.reports.create', [
                                                'student_id' => $session->student_id,
                                                'date' => $session->paid_date->toDateString(),
                                            ]) }}" class="btn-secondary btn-sm">File</a>
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
@endsection
