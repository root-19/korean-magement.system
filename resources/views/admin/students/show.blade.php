@extends('layouts.app')

@section('title', $student->name)
@section('heading', $student->name)
@section('subheading', 'Student · '.($profile->instructor?->name ?? 'unassigned'))

@section('actions')
    <a href="{{ route('admin.students.index') }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        All students
    </a>
    <a href="{{ route('admin.students.edit', $student) }}" class="btn-primary btn-sm">
        <x-icon name="pencil" class="h-4 w-4" />
        Edit details
    </a>
@endsection

@section('content')
    {{-- purchased = attended + student-absent + remaining + deducted --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-stat-card label="Purchased" value="{{ $stats['purchased'] }}" icon="book" hint="Total plan" />
        <x-stat-card label="Attended" value="{{ $stats['present'] }}" icon="check-circle" tone="success" />
        <x-stat-card label="Student absent" value="{{ $stats['student_absent'] }}" icon="x-circle" tone="warning"
                     hint="Consumed a session" />
        <x-stat-card label="Remaining" value="{{ $stats['remaining'] }}" icon="clock"
                     :tone="$stats['remaining'] === 0 ? 'danger' : 'brand'" />
        <x-stat-card label="Deducted" value="{{ $stats['deducted'] }}" icon="alert" hint="At enrolment" />
    </div>

    @if ($stats['counter_attended'] !== $stats['present'])
        <div class="mt-4 flex items-start gap-3 rounded-card border border-warning-500/30 bg-warning-500/10 px-4 py-3 text-sm text-warning-400">
            <x-icon name="alert" class="mt-px h-5 w-5 shrink-0" />
            <p>
                Stored attendance counter reads
                <strong class="numeric">{{ $stats['counter_attended'] }}</strong>
                but <strong class="numeric">{{ $stats['present'] }}</strong>
                present {{ Str::plural('session', $stats['present']) }}
                {{ $stats['present'] === 1 ? 'is' : 'are' }} recorded. Drift inherited from the
                legacy data; attendance rows are the source of truth.
            </p>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Session history" :subtitle="$sessions->total().' recorded'" flush>
                @if ($sessions->isEmpty())
                    <x-empty-state icon="calendar" title="No sessions yet" />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Scheduled</th>
                                    <th>Instructor</th>
                                    <th>Status</th>
                                    <th>Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sessions as $session)
                                    <tr>
                                        <td class="numeric whitespace-nowrap">
                                            {{ $session->scheduled_date->format('D, M j, Y') }}
                                            @if ($session->isEarly())
                                                <span class="badge-brand mt-0.5 block w-fit">
                                                    Taught {{ $session->held_date->format('M j') }}
                                                </span>
                                            @endif
                                        </td>

                                        <td class="truncate text-gray-300">{{ $session->instructor?->name ?? '—' }}</td>

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
                                            @if ($session->report)
                                                <span class="badge-success">Filed</span>
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
        </div>

        <div class="space-y-4">
            {{-- Reassignment. Past sessions keep the instructor who taught them,
                 so this never moves historical earnings. --}}
            <x-card title="Assigned instructor">
                <form method="POST" action="{{ route('admin.students.reassign', $student) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')

                    <select name="instructor_id" class="form-select" aria-label="Instructor">
                        <option value="">— Unassigned —</option>
                        @foreach ($instructors as $instructor)
                            <option value="{{ $instructor->id }}"
                                @selected($profile->instructor_id === $instructor->id)>
                                {{ $instructor->name }}
                            </option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn-primary w-full">Save assignment</button>
                </form>

                <p class="mt-3 text-xs text-gray-500">
                    Past sessions keep whoever taught them — reassigning does not move
                    earnings already recorded.
                </p>
            </x-card>

            <x-card title="Enrolment">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'Status' => $profile->enrollment_status->label(),
                        'Regular' => $profile->is_regular ? 'Yes' : 'No',
                        'Type' => $profile->teaching_method?->label() ?? '—',
                        'Duration' => $profile->learning_time ? $profile->learning_time.' min' : '—',
                        'Started' => $profile->start_date?->format('M j, Y') ?? '—',
                        'KakaoTalk' => $profile->kakaotalk_id ?: '—',
                        'Phone' => $student->phone ?: '—',
                        'Email' => $student->email ?: '—',
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-400">{{ $label }}</dt>
                            <dd class="truncate text-right font-medium text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Weekly schedule">
                @if ($student->schedules->isEmpty())
                    <p class="text-sm text-gray-400">No timetable set.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($student->schedules as $slot)
                            <li class="flex items-center justify-between gap-3">
                                <span class="text-gray-300">{{ $slot->dayName() }}</span>
                                <span class="numeric font-medium text-white">{{ $slot->formattedTime() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Account">
                @if ($student->trashed())
                    <p class="mb-3 rounded-lg border border-danger-500/30 bg-danger-500/10 px-3 py-2 text-xs text-danger-400">
                        Deleted {{ $student->deleted_at->format('M j, Y') }}
                        @if ($student->deletedBy)
                            by {{ $student->deletedBy->name }}
                        @endif.
                        They are gone from every list and cannot sign in. Restoring puts them back.
                    </p>
                @endif

                <form method="POST" action="{{ route('admin.students.status', $student) }}"
                      onsubmit="return confirm('{{ $student->is_active ? 'Archive' : 'Restore' }} {{ $student->name }}?')">
                    @csrf
                    @method('PATCH')
                    <button type="submit" @class(['w-full', 'btn-danger' => $student->is_active, 'btn-primary' => ! $student->is_active])>
                        {{ $student->is_active ? 'Archive student' : 'Restore student' }}
                    </button>
                </form>

                <p class="mt-3 text-xs text-gray-500">
                    Archiving and deleting both keep every attendance and report row, so the
                    instructor's earnings are unaffected either way. Nothing is ever hard-deleted.
                </p>
            </x-card>
        </div>
    </div>
@endsection
