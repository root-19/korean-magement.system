@extends('layouts.app')

@section('title', 'Teacher Schedules')
@section('heading', 'Teacher Schedules')
@section('subheading', $publishedCount.' of '.$instructors->count().' instructors have published hours')

@section('content')
    @if ($publishedCount < $instructors->count())
        {{-- Instructors can only publish hours themselves, so this is a nudge
             rather than something an admin can fix here. --}}
        <div class="mb-4 flex items-start gap-3 rounded-card border border-warning-500/30 bg-warning-500/10 px-4 py-3 text-sm text-warning-400">
            <x-icon name="alert" class="mt-px h-5 w-5 shrink-0" />
            <p>
                {{ $instructors->count() - $publishedCount }} instructors have not published any
                availability. Their public profile shows no bookable hours, and the schedule
                below falls back to the class times their students already hold.
            </p>
        </div>
    @endif

    @if ($instructors->isEmpty())
        <x-card>
            <x-empty-state icon="users" title="No active instructors" />
        </x-card>
    @else
        {{-- Instructor picker. Plain links, so each instructor's week is a
             shareable URL and it works without JavaScript. --}}
        <x-card class="mb-4" flush>
            <div class="flex gap-2 overflow-x-auto p-3">
                @foreach ($instructors as $instructor)
                    @php $isSelected = $selected && $instructor->id === $selected->id; @endphp

                    <a href="{{ route('admin.schedules.index', ['instructor' => $instructor->id]) }}"
                       @class([
                           'flex shrink-0 items-center gap-2.5 rounded-lg border px-3 py-2 transition',
                           'border-brand-400/60 bg-brand-500/10' => $isSelected,
                           'border-gray-700 hover:border-gray-600 hover:bg-gray-700/50' => ! $isSelected,
                       ])
                       @if ($isSelected) aria-current="true" @endif>
                        <x-avatar :user="$instructor" class="h-8 w-8" />
                        <span class="text-left">
                            <span class="block whitespace-nowrap text-sm font-medium text-white">
                                {{ $instructor->name }}
                            </span>
                            <span class="numeric block whitespace-nowrap text-[11px] text-gray-500">
                                {{ $instructor->availabilities_count }}
                                {{ Str::plural('slot', $instructor->availabilities_count) }}
                                · {{ $instructor->student_count }}
                                {{ Str::plural('student', $instructor->student_count) }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-card>

        <x-card>
            <x-slot:title>{{ $selected?->name }}</x-slot:title>
            <x-slot:subtitle>
                {{ $grid && $grid->isDeclared
                    ? $grid->availableHours().' '.Str::plural('open hour', $grid->availableHours()).' published'
                    : 'No published hours — showing existing class times' }}
            </x-slot:subtitle>

            <x-slot:actions>
                <a href="{{ route('admin.instructors.show', $selected) }}" class="btn-secondary btn-sm">
                    View instructor
                </a>
            </x-slot:actions>

            <x-schedule-grid :grid="$grid" :days="$days" :label="$selected?->name.'\'s weekly availability'" />
        </x-card>
    @endif
@endsection
