@extends('layouts.app')

@section('title', 'My Classes')
@section('heading', 'Welcome, '.$student->name)
@section('subheading', $profile?->instructor ? 'Teacher: '.$profile->instructor->name : 'Your class overview')

@section('content')
    @if ($progress === null)
        <x-card>
            <x-empty-state icon="book-open"
                           title="No class plan yet"
                           message="Your account has no enrolment on record. Please contact the academy." />
        </x-card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Attended" :value="$progress['attended'].'/'.$progress['purchased']"
                         icon="check" tone="success" hint="classes taught" />
            <x-stat-card label="Remaining" :value="$progress['remaining']"
                         icon="clock" tone="brand" hint="classes left to use" />
            <x-stat-card label="Absent" :value="$progress['student_absent'] + $progress['teacher_absent']"
                         icon="x" tone="danger"
                         :hint="$progress['student_absent'].' student · '.$progress['teacher_absent'].' teacher'" />
            <x-stat-card label="Postponed" :value="$progress['student_postponed'] + $progress['teacher_postponed']"
                         icon="refresh" tone="warning"
                         :hint="$progress['student_postponed'].' student · '.$progress['teacher_postponed'].' teacher'" />
        </div>

        @if ($progress['deducted'] > 0)
            <p class="mt-3 text-xs text-gray-400">
                <span class="numeric">{{ $progress['deducted'] }}</span> session(s) were deducted at enrolment.
            </p>
        @endif
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="My Schedule" subtitle="Weekly class times" flush>
            @if ($schedules->isEmpty())
                <x-empty-state icon="calendar" title="No schedule yet" message="Your weekly class times will appear here." />
            @else
                <ul class="divide-y divide-gray-700">
                    @foreach ($schedules as $slot)
                        <li class="flex items-center justify-between px-4 py-3 text-sm sm:px-5">
                            <span class="font-medium text-white">
                                {{ now()->startOfWeek()->addDays($slot->day_of_week - 1)->format('l') }}
                            </span>
                            <span class="numeric text-gray-300">
                                {{ \Illuminate\Support\Carbon::parse($slot->start_time)->format('g:i A') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="Recent Classes" subtitle="Your last 10 marked classes" flush>
            @if ($recent->isEmpty())
                <x-empty-state icon="book" title="No classes yet" message="Your class history will appear here." />
            @else
                <ul class="divide-y divide-gray-700">
                    @foreach ($recent as $class)
                        <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm sm:px-5">
                            <span class="numeric text-white">{{ $class->scheduled_date->format('M j, Y') }}</span>
                            <span class="min-w-0 flex-1 truncate text-xs text-gray-400">{{ $class->instructor?->name }}</span>
                            <span class="{{ $class->status->badgeClass() }}">{{ $class->status->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    <h2 class="heading mb-3 mt-8 text-lg">Learning Materials</h2>

    @if ($materials->isEmpty())
        <x-card>
            <x-empty-state icon="book-open"
                           title="No materials published yet"
                           message="When the academy posts a learning material it appears here, ready to download." />
        </x-card>
    @else
        @foreach ($materials as $folderName => $items)
            <h3 class="mb-2 mt-5 text-sm font-semibold uppercase tracking-wide text-gray-400">{{ $folderName }}</h3>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($items as $material)
                    <x-card class="flex flex-col">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-danger-500/15 text-danger-400">
                                <x-icon name="book-open" class="h-5 w-5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <h4 class="font-semibold text-white">{{ $material->title }}</h4>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    PDF · <span class="numeric">{{ $material->readableSize() }}</span>
                                </p>
                            </div>
                        </div>

                        @if ($material->description)
                            <p class="mt-3 flex-1 text-sm text-gray-300">{{ $material->description }}</p>
                        @endif

                        <a href="{{ route('student.materials.download', $material) }}" class="btn-secondary mt-4 w-full">
                            <x-icon name="download" class="h-4 w-4" />
                            Download
                        </a>
                    </x-card>
                @endforeach
            </div>
        @endforeach
    @endif
@endsection
