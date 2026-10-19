@extends('layouts.app')

@section('title', __('Instructors'))
@section('heading', __('Instructors'))
@section('subheading', trans_choice(':count instructor|:count instructors', $instructors->total()))

@section('actions')
    <a href="{{ route('admin.instructors.create') }}" class="btn-primary btn-sm">
        <x-icon name="user-plus" class="h-4 w-4" />
        {{ __('Add instructor') }}
    </a>
@endsection

@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="relative flex-1 sm:max-w-xs">
            <input type="hidden" name="status" value="{{ $statusFilter }}">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search instructors…') }}"
                   class="form-input pl-9" aria-label="{{ __('Search instructors') }}">
        </form>

        <div class="flex items-center gap-1 rounded-lg border border-gray-700 bg-gray-800 p-1">
            @foreach (['active' => __('Active'), 'inactive' => __('Inactive'), 'all' => __('All')] as $value => $label)
                <a href="{{ route('admin.instructors.index', ['status' => $value, 'q' => $search ?: null]) }}"
                   @class([
                       'focus-ring rounded-md px-3 py-1.5 text-xs font-semibold transition',
                       'bg-gray-700 text-brand-400' => $statusFilter === $value,
                       'text-gray-400 hover:text-white' => $statusFilter !== $value,
                   ])>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <x-card flush>
        @if ($instructors->isEmpty())
            <x-empty-state icon="users"
                           :title="$search !== '' ? __('No instructors match “:search”', ['search' => $search]) : __('No instructors')"
                           :message="__('Instructors are created by an administrator.')">
                @if ($search !== '')
                    <a href="{{ route('admin.instructors.index') }}" class="btn-secondary btn-sm">{{ __('Clear search') }}</a>
                @else
                    <a href="{{ route('admin.instructors.create') }}" class="btn-primary btn-sm">{{ __('Add instructor') }}</a>
                @endif
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Instructor') }}</th>
                            <th class="text-center">{{ __('Students') }}</th>
                            <th class="text-center">{{ __('Sessions this week') }}</th>
                            <th>{{ __('Bank') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($instructors as $instructor)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.instructors.show', $instructor) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$instructor" class="h-9 w-9" />
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-white">{{ $instructor->name }}</span>
                                            @if ($instructor->email)
                                                <span class="block truncate text-xs text-gray-500">{{ $instructor->email }}</span>
                                            @endif
                                        </span>
                                    </a>
                                </td>

                                <td class="numeric text-center font-medium text-gray-200">
                                    {{ $instructor->student_count }}
                                </td>

                                <td class="numeric text-center text-gray-300">
                                    {{ $sessionCounts[$instructor->id] ?? 0 }}
                                </td>

                                <td class="truncate text-gray-400">
                                    {{ $instructor->instructorProfile?->bank_name ?: '—' }}
                                </td>

                                <td>
                                    @if ($instructor->is_active)
                                        <span class="badge-success">{{ __('Active') }}</span>
                                    @else
                                        <span class="badge-neutral">{{ __('Inactive') }}</span>
                                    @endif
                                </td>

                                <td class="text-right">
                                    <form method="POST" action="{{ route('admin.instructors.status', $instructor) }}"
                                          onsubmit="return confirm(@js($instructor->is_active ? __('Deactivate :name?', ['name' => $instructor->name]) : __('Activate :name?', ['name' => $instructor->name])))">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                @class(['btn-sm', 'btn-secondary' => ! $instructor->is_active, 'btn-ghost' => $instructor->is_active])>
                                            {{ $instructor->is_active ? __('Deactivate') : __('Activate') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($instructors->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">{{ $instructors->links() }}</div>
            @endif
        @endif
    </x-card>
@endsection
