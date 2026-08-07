@extends('layouts.app')

@section('title', $title)
@section('heading', $title)
@section('subheading', $subtitle.' · '.$students->total().' '.Str::plural('student', $students->total()))

@section('content')
    <x-card flush>
        @if ($students->isEmpty())
            <x-empty-state icon="users"
                           title="No students in this list"
                           :message="$subtitle.'. Students appear here once they match.'">
                <a href="{{ route('instructor.students.index') }}" class="btn-secondary btn-sm">All students</a>
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th class="text-center">Present</th>
                            <th class="text-center">Absent</th>
                            <th class="text-center">Left</th>
                            <th>Last class</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($students as $profile)
                            @php
                                $student = $profile->user;
                                $stat = $stats->get($profile->user_id);
                            @endphp

                            <tr>
                                <td>
                                    <a href="{{ route('instructor.students.show', $profile->user_id) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$student" class="h-8 w-8" />
                                        <span class="block truncate font-medium text-white">{{ $student?->name }}</span>
                                    </a>
                                </td>

                                <td class="whitespace-nowrap text-gray-400">
                                    {{ $profile->teaching_method?->label() ?? '—' }}
                                    <span class="numeric text-xs text-gray-500">
                                        {{ $profile->learning_time ? $profile->learning_time.'m' : '' }}
                                    </span>
                                </td>

                                <td>
                                    @if ($student && $student->schedules->isNotEmpty())
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($student->schedules as $slot)
                                                <span class="badge-neutral numeric"
                                                      title="{{ $slot->dayName() }} {{ $slot->formattedTime() }}">
                                                    {{ $slot->shortDayName() }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-500">Not set</span>
                                    @endif
                                </td>

                                <td class="numeric text-center text-success-400">{{ (int) ($stat->present ?? 0) ?: '—' }}</td>
                                <td class="numeric text-center text-warning-400">{{ (int) ($stat->student_absent ?? 0) ?: '—' }}</td>

                                <td class="numeric text-center">
                                    <span @class([
                                        'font-medium',
                                        'text-danger-400' => $profile->sessions_remaining === 0,
                                        'text-gray-200' => $profile->sessions_remaining > 0,
                                    ])>{{ $profile->sessions_remaining }}</span>
                                </td>

                                <td class="numeric whitespace-nowrap text-gray-400">
                                    {{ $stat?->last_class
                                        ? \Carbon\Carbon::parse($stat->last_class)->format('M j, Y')
                                        : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($students->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">{{ $students->links() }}</div>
            @endif
        @endif
    </x-card>
@endsection
