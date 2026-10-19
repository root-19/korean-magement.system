@extends('layouts.app')

@section('title', $student->name)
@section('heading', $student->name)
@section('subheading', __('Student · :instructor', ['instructor' => $profile->instructor?->name ?? __('unassigned')]))

@section('actions')
    <a href="{{ route('admin.students.index') }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        {{ __('All students') }}
    </a>
    <a href="{{ route('admin.students.edit', $student) }}" class="btn-primary btn-sm">
        <x-icon name="pencil" class="h-4 w-4" />
        {{ __('Edit details') }}
    </a>
@endsection

@section('content')
    {{-- purchased = attended + student-absent + remaining + deducted --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-stat-card :label="__('Purchased')" value="{{ $stats['purchased'] }}" icon="book" :hint="__('Total plan')" />
        <x-stat-card :label="__('Attended')" value="{{ $stats['present'] }}" icon="check-circle" tone="success" />
        <x-stat-card :label="__('Student absent')" value="{{ $stats['student_absent'] }}" icon="x-circle" tone="warning"
                     :hint="__('Consumed a session')" />
        <x-stat-card :label="__('Remaining')" value="{{ $stats['remaining'] }}" icon="clock"
                     :tone="$stats['remaining'] === 0 ? 'danger' : 'brand'" />
        <x-stat-card :label="__('Deducted')" value="{{ $stats['deducted'] }}" icon="alert" :hint="__('At enrolment')" />
    </div>

    @if ($stats['counter_attended'] !== $stats['present'])
        <div class="mt-4 flex items-start gap-3 rounded-card border border-warning-500/30 bg-warning-500/10 px-4 py-3 text-sm text-warning-400">
            <x-icon name="alert" class="mt-px h-5 w-5 shrink-0" />
            <p>
                {!! trans_choice('Stored attendance counter reads :counter but :present present session is recorded.|Stored attendance counter reads :counter but :present present sessions are recorded.', $stats['present'], [
                    'counter' => '<strong class="numeric">'.e($stats['counter_attended']).'</strong>',
                    'present' => '<strong class="numeric">'.e($stats['present']).'</strong>',
                ]) !!}
                {{ __('Drift inherited from the legacy data; attendance rows are the source of truth.') }}
            </p>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :title="__('Session history')" :subtitle="__(':count recorded', ['count' => $sessions->total()])" flush>
                @if ($sessions->isEmpty())
                    <x-empty-state icon="calendar" :title="__('No sessions yet')" />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Scheduled') }}</th>
                                    <th>{{ __('Instructor') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Report') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sessions as $session)
                                    <tr>
                                        <td class="numeric whitespace-nowrap">
                                            {{ $session->scheduled_date->translatedFormat('D, M j, Y') }}
                                            @if ($session->isEarly())
                                                <span class="badge-brand mt-0.5 block w-fit">
                                                    {{ __('Taught :date', ['date' => $session->held_date->translatedFormat('M j')]) }}
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
                                                <span class="badge-neutral">{{ __('Not marked') }}</span>
                                            @endif
                                        </td>

                                        <td>
                                            @if ($session->report)
                                                <span class="badge-success">{{ __('Filed') }}</span>
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
            <x-card :title="__('Assigned instructor')">
                <form method="POST" action="{{ route('admin.students.reassign', $student) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')

                    <select name="instructor_id" class="form-select" aria-label="{{ __('Instructor') }}">
                        <option value="">{{ __('— Unassigned —') }}</option>
                        @foreach ($instructors as $instructor)
                            <option value="{{ $instructor->id }}"
                                @selected($profile->instructor_id === $instructor->id)>
                                {{ $instructor->name }}
                            </option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn-primary w-full">{{ __('Save assignment') }}</button>
                </form>

                <p class="mt-3 text-xs text-gray-500">
                    {{ __('Past sessions keep whoever taught them — reassigning does not move earnings already recorded.') }}
                </p>
            </x-card>

            <x-card :title="__('Enrolment')">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        __('Status') => $profile->enrollment_status->label(),
                        __('Regular') => $profile->is_regular ? __('Yes') : __('No'),
                        __('Type') => $profile->teaching_method?->label() ?? '—',
                        __('Duration') => $profile->learning_time ? __(':minutes min', ['minutes' => $profile->learning_time]) : '—',
                        __('Started') => $profile->start_date?->translatedFormat('M j, Y') ?? '—',
                        __('KakaoTalk') => $profile->kakaotalk_id ?: '—',
                        __('Phone') => $student->phone ?: '—',
                        __('Email') => $student->email ?: '—',
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-400">{{ $label }}</dt>
                            <dd class="truncate text-right font-medium text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card :title="__('Weekly schedule')">
                @if ($student->schedules->isEmpty())
                    <p class="text-sm text-gray-400">{{ __('No timetable set.') }}</p>
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

            <x-card :title="__('Account')">
                @if ($student->trashed())
                    <p class="mb-3 rounded-lg border border-danger-500/30 bg-danger-500/10 px-3 py-2 text-xs text-danger-400">
                        @if ($student->deletedBy)
                            {{ __('Deleted :date by :name.', ['date' => $student->deleted_at->translatedFormat('M j, Y'), 'name' => $student->deletedBy->name]) }}
                        @else
                            {{ __('Deleted :date.', ['date' => $student->deleted_at->translatedFormat('M j, Y')]) }}
                        @endif
                        {{ __('They are gone from every list and cannot sign in. Restoring puts them back.') }}
                    </p>
                @endif

                <form method="POST" action="{{ route('admin.students.status', $student) }}"
                      onsubmit="return confirm(@js($student->is_active ? __('Archive :name?', ['name' => $student->name]) : __('Restore :name?', ['name' => $student->name])))">
                    @csrf
                    @method('PATCH')
                    <button type="submit" @class(['w-full', 'btn-danger' => $student->is_active, 'btn-primary' => ! $student->is_active])>
                        {{ $student->is_active ? __('Archive student') : __('Restore student') }}
                    </button>
                </form>

                {{-- An admin deletes outright: they are the one who would approve
                     an instructor's request anyway. Restore above undoes it. --}}
                @unless ($student->trashed())
                    <form method="POST" action="{{ route('admin.students.destroy', $student) }}"
                          class="mt-3 space-y-2 border-t border-gray-700 pt-3"
                          onsubmit="return confirm(@js(__('Delete :name? They are removed from every list and can no longer sign in. Their classes and reports are kept.', ['name' => $student->name])))">
                        @csrf
                        @method('DELETE')

                        <input type="text" name="reason" maxlength="1000" class="form-input"
                               aria-label="{{ __('Reason for deleting :name', ['name' => $student->name]) }}"
                               placeholder="{{ __('Reason (optional)') }}">

                        <button type="submit" class="btn-danger w-full">
                            <x-icon name="trash" class="h-4 w-4" />
                            {{ __('Delete student') }}
                        </button>
                    </form>
                @endunless

                <p class="mt-3 text-xs text-gray-500">
                    {{ __('Archiving and deleting both keep every attendance and report row, so the instructor\'s earnings are unaffected either way. Nothing is ever hard-deleted.') }}
                </p>
            </x-card>
        </div>
    </div>
@endsection
