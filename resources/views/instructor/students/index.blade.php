@extends('layouts.app')

@section('title', 'Students')
@section('heading', 'Students')
@section('subheading', $students->total().' '.Str::plural('student', $students->total()).($showingArchived ? ' · archived' : ''))

@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="relative flex-1 sm:max-w-xs">
            @if ($showingArchived)
                <input type="hidden" name="status" value="archived">
            @endif
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <input type="search"
                   name="q"
                   value="{{ $search }}"
                   placeholder="Search students…"
                   class="form-input pl-9"
                   aria-label="Search students">
        </form>

        <div class="flex items-center gap-1 rounded-lg border border-gray-700 p-0.5">
            <a href="{{ route('instructor.students.index', ['q' => $search ?: null]) }}"
               @class([
                   'focus-ring rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                   'bg-brand-500/10 text-brand-400 bg-brand-500/10 text-brand-400' => ! $showingArchived,
                   'text-gray-400 hover:text-gray-500 hover:text-white' => $showingArchived,
               ])>
                Active
            </a>
            <a href="{{ route('instructor.students.index', ['status' => 'archived', 'q' => $search ?: null]) }}"
               @class([
                   'focus-ring rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                   'bg-brand-500/10 text-brand-400 bg-brand-500/10 text-brand-400' => $showingArchived,
                   'text-gray-400 hover:text-gray-500 hover:text-white' => ! $showingArchived,
               ])>
                Archived
            </a>
        </div>
    </div>

    <x-card flush>
        @if ($students->isEmpty())
            <x-empty-state icon="users"
                           :title="$search !== '' ? 'No students match “'.$search.'”' : ($showingArchived ? 'No archived students' : 'No students assigned yet')"
                           :message="$search !== '' ? 'Try a different name or clear the search.' : 'Students appear here once an administrator assigns them to you.'">
                @if ($search !== '')
                    <a href="{{ route('instructor.students.index') }}" class="btn-secondary btn-sm">Clear search</a>
                @endif
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th class="text-center">Attended</th>
                            <th class="text-center">Remaining</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($students as $profile)
                            @php $student = $profile->user; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('instructor.students.show', $student) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$student" class="h-8 w-8" />
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-white">
                                                {{ $student->name }}
                                            </span>
                                            @if ($student->email)
                                                <span class="block truncate text-xs text-gray-500">
                                                    {{ $student->email }}
                                                </span>
                                            @endif
                                        </span>
                                    </a>
                                </td>

                                <td class="whitespace-nowrap text-gray-500">
                                    {{ $profile->teaching_method?->label() ?? '—' }}
                                    <span class="numeric text-xs text-gray-400">
                                        {{ $profile->learning_time ? $profile->learning_time.'m' : '' }}
                                    </span>
                                </td>

                                {{-- Schedule rows, not a comma-joined string plus seven
                                     nullable time columns. --}}
                                <td>
                                    @if ($student->schedules->isEmpty())
                                        <span class="text-xs text-gray-400">Not set</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($student->schedules as $slot)
                                                <span class="badge-neutral numeric" title="{{ $slot->dayName() }} {{ $slot->formattedTime() }}">
                                                    {{ $slot->shortDayName() }} {{ $slot->formattedTime() }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>

                                <td class="numeric text-center font-medium text-gray-300">
                                    {{ $profile->sessions_attended }}
                                </td>

                                <td class="numeric text-center">
                                    <span @class([
                                        'font-medium',
                                        'text-danger-400' => $profile->sessions_remaining === 0,
                                        'text-gray-300' => $profile->sessions_remaining > 0,
                                    ])>
                                        {{ $profile->sessions_remaining }}
                                    </span>
                                </td>

                                <td>
                                    @if (! $student->is_active)
                                        <span class="badge-neutral">Archived</span>
                                    @else
                                        <span class="{{ $profile->enrollment_status->badgeClass() }}">
                                            {{ $profile->enrollment_status->label() }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($students->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">
                    {{ $students->links() }}
                </div>
            @endif
        @endif
    </x-card>
@endsection
