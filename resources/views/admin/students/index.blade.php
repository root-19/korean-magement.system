@extends('layouts.app')

@section('title', 'Students')
@section('heading', 'Students')
@section('subheading', $students->total().' '.Str::plural('student', $students->total()))

@section('actions')
    <a href="{{ route('admin.students.create') }}" class="btn-primary btn-sm">
        <x-icon name="user-plus" class="h-4 w-4" />
        Add student
    </a>
@endsection

@section('content')
    {{-- The legacy student, user_table, free_students and re_enrolled pages,
         collapsed into filters on one list. --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 rounded-lg border border-gray-700 bg-gray-800 p-1">
        @foreach ([
            'active' => 'Active',
            'unassigned' => 'Unassigned',
            'no_sessions' => 'No sessions left',
            'pending' => 'Pending',
            'archived' => 'Archived',
        ] as $value => $label)
            <a href="{{ route('admin.students.index', ['filter' => $value, 'q' => $search ?: null]) }}"
               @class([
                   'focus-ring flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                   'bg-gray-700 text-brand-400' => $filter === $value,
                   'text-gray-400 hover:text-white' => $filter !== $value,
               ])>
                {{ $label }}
                <span class="numeric rounded-full bg-gray-900/60 px-1.5 py-0.5 text-[10px]">
                    {{ $filters[$value] ?? 0 }}
                </span>
            </a>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="relative flex-1 sm:max-w-xs">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search students…"
                   class="form-input pl-9" aria-label="Search students">
        </form>

        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="filter" value="{{ $filter }}">
            @if ($search !== '')
                <input type="hidden" name="q" value="{{ $search }}">
            @endif
            <select name="instructor" onchange="this.form.submit()" class="form-select !w-auto text-xs"
                    aria-label="Filter by instructor">
                <option value="">All instructors</option>
                @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" @selected((int) $selectedInstructor === $instructor->id)>
                        {{ $instructor->name }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    <x-card flush>
        @if ($students->isEmpty())
            <x-empty-state icon="users"
                           :title="$search !== '' ? 'No students match “'.$search.'”' : 'No students in this view'"
                           message="Try another filter, or clear the search.">
                <a href="{{ route('admin.students.index') }}" class="btn-secondary btn-sm">Reset filters</a>
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Instructor</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th class="text-center">Left</th>
                            <th>Status</th>
                            <th class="w-px"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($students as $profile)
                            @php $student = $profile->user; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.students.show', $student) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$student" class="h-8 w-8" />
                                        <span class="block truncate font-medium text-white">{{ $student?->name }}</span>
                                    </a>
                                </td>

                                <td class="truncate">
                                    @if ($profile->instructor)
                                        <a href="{{ route('admin.instructors.show', $profile->instructor) }}"
                                           class="text-brand-400 hover:text-accent-400 hover:underline">
                                            {{ $profile->instructor->name }}
                                        </a>
                                    @else
                                        <span class="badge-danger">Unassigned</span>
                                    @endif
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
                                                <span class="badge-neutral numeric">{{ $slot->shortDayName() }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-500">Not set</span>
                                    @endif
                                </td>

                                <td class="numeric text-center">
                                    <span @class([
                                        'font-medium',
                                        'text-danger-400' => $profile->sessions_remaining === 0,
                                        'text-gray-200' => $profile->sessions_remaining > 0,
                                    ])>{{ $profile->sessions_remaining }}</span>
                                </td>

                                <td>
                                    @if ($student?->trashed())
                                        {{-- Deleted, not archived: an approved deletion
                                             request took them out of the app entirely. --}}
                                        <span class="badge-danger">Deleted</span>
                                    @elseif ($student && ! $student->is_active)
                                        <span class="badge-neutral">Archived</span>
                                    @else
                                        <span class="{{ $profile->enrollment_status->badgeClass() }}">
                                            {{ $profile->enrollment_status->label() }}
                                        </span>
                                    @endif
                                </td>

                                <td class="text-right">
                                    @if ($student)
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('admin.students.edit', $student) }}"
                                               class="btn-ghost btn-sm" aria-label="Edit {{ $student->name }}">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                                Edit
                                            </a>

                                            {{-- Already-deleted students keep the row for the
                                                 audit trail; Restore lives on their own page. --}}
                                            @unless ($student->trashed())
                                                <form method="POST"
                                                      action="{{ route('admin.students.destroy', $student) }}"
                                                      onsubmit="return confirm('Delete {{ $student->name }}? They are removed from every list and can no longer sign in. Their classes and reports are kept.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-sm text-danger-400"
                                                            aria-label="Delete {{ $student->name }}">
                                                        <x-icon name="trash" class="h-4 w-4" />
                                                        Delete
                                                    </button>
                                                </form>
                                            @endunless
                                        </div>
                                    @endif
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
