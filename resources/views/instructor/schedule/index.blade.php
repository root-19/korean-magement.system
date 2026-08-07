@extends('layouts.app')

@section('title', 'Teacher Schedule')
@section('heading', 'Teacher Schedule')
@section('subheading', 'The hours you are open for bookings')

@section('content')
    @if ($instructor->availabilities->isEmpty())
        {{-- The legacy dashboard nagged about this but the only way to fix it was
             a page most instructors never found. Say what it unlocks. --}}
        <div class="card-accent mb-6 p-5">
            <div class="flex flex-wrap items-center gap-4">
                <span class="rounded-full bg-white/20 p-3">
                    <x-icon name="calendar" class="h-6 w-6 text-white" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-bold text-white">Set up your teaching schedule</h2>
                    <p class="mt-0.5 text-sm text-white/85">
                        You have not published any hours yet. Until you do, your public profile
                        shows no availability and students cannot see when to book you.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Add a slot --}}
        <div class="space-y-4">
            <x-card title="Add a time slot">
                <form method="POST" action="{{ route('instructor.schedule.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="day_of_week" class="form-label">Day</label>
                        <select id="day_of_week" name="day_of_week" required class="form-select">
                            @foreach ($days as $iso => $name)
                                <option value="{{ $iso }}" @selected(old('day_of_week') == $iso)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('day_of_week') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="start_time" class="form-label">From</label>
                            <input id="start_time" name="start_time" type="time" required
                                   value="{{ old('start_time', '09:00') }}" class="form-input numeric">
                            @error('start_time') <p class="form-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="end_time" class="form-label">To</label>
                            <input id="end_time" name="end_time" type="time" required
                                   value="{{ old('end_time', '12:00') }}" class="form-input numeric">
                            @error('end_time') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="is_available" value="1" checked
                               class="rounded border-gray-600 bg-gray-800 text-brand-400 focus:ring-brand-400">
                        Open for bookings
                    </label>
                    <p class="text-xs text-gray-500">
                        Untick to record a slot you are explicitly <em>not</em> available for.
                    </p>

                    <button type="submit" class="btn-primary w-full">
                        <x-icon name="plus" class="h-4 w-4" />
                        Add slot
                    </button>
                </form>
            </x-card>

            {{-- Copy a day's hours onto other days — the legacy page made you
                 retype identical hours five times. --}}
            @if ($instructor->availabilities->isNotEmpty())
                <x-card title="Copy a day">
                    <form method="POST" action="{{ route('instructor.schedule.copy') }}" class="space-y-3">
                        @csrf

                        <div>
                            <label for="from_day" class="form-label">Copy from</label>
                            <select id="from_day" name="from_day" required class="form-select">
                                @foreach ($days as $iso => $name)
                                    @if (($byDay[$iso] ?? collect())->isNotEmpty())
                                        <option value="{{ $iso }}">
                                            {{ $name }} ({{ $byDay[$iso]->count() }} {{ Str::plural('slot', $byDay[$iso]->count()) }})
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                            @error('from_day') <p class="form-error">{{ $message }}</p> @enderror
                        </div>

                        <fieldset>
                            <legend class="form-label">Copy to</legend>
                            <div class="grid grid-cols-2 gap-1.5">
                                @foreach ($days as $iso => $name)
                                    <label class="flex items-center gap-2 text-sm text-gray-300">
                                        <input type="checkbox" name="to_days[]" value="{{ $iso }}"
                                               class="rounded border-gray-600 bg-gray-800 text-brand-400 focus:ring-brand-400">
                                        {{ substr($name, 0, 3) }}
                                    </label>
                                @endforeach
                            </div>
                            @error('to_days') <p class="form-error">{{ $message }}</p> @enderror
                        </fieldset>

                        <button type="submit" class="btn-secondary w-full">Copy hours</button>
                    </form>
                </x-card>
            @endif
        </div>

        {{-- Current slots, day by day --}}
        <div class="space-y-4 lg:col-span-2">
            <x-card title="Your week" :subtitle="$instructor->availabilities->count().' '.Str::plural('slot', $instructor->availabilities->count()).' published'">
                <div class="space-y-4">
                    @foreach ($days as $iso => $name)
                        @php $slots = $byDay[$iso] ?? collect(); @endphp

                        <div class="rounded-lg border border-gray-700 p-3">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-brand-400">{{ $name }}</h3>

                                @if (isset($bookedHours[$iso]))
                                    {{-- Existing classes, so availability is not published
                                         over something already committed. --}}
                                    <span class="text-[11px] text-gray-500">
                                        {{ count($bookedHours[$iso]) }} class{{ count($bookedHours[$iso]) === 1 ? '' : 'es' }} booked
                                    </span>
                                @endif
                            </div>

                            @if ($slots->isEmpty())
                                <p class="text-xs text-gray-500">No hours published.</p>
                            @else
                                <ul class="space-y-2">
                                    @foreach ($slots as $slot)
                                        <li>
                                            <form method="POST"
                                                  action="{{ route('instructor.schedule.update', $slot) }}"
                                                  class="flex flex-wrap items-center gap-2">
                                                @csrf
                                                @method('PATCH')

                                                <input type="time" name="start_time"
                                                       value="{{ substr($slot->start_time, 0, 5) }}"
                                                       class="form-input numeric !w-auto !py-1 text-xs"
                                                       aria-label="{{ $name }} start time">

                                                <span class="text-xs text-gray-500">to</span>

                                                <input type="time" name="end_time"
                                                       value="{{ substr($slot->end_time, 0, 5) }}"
                                                       class="form-input numeric !w-auto !py-1 text-xs"
                                                       aria-label="{{ $name }} end time">

                                                <label class="flex items-center gap-1.5 text-xs text-gray-300">
                                                    <input type="checkbox" name="is_available" value="1"
                                                           @checked($slot->is_available)
                                                           class="rounded border-gray-600 bg-gray-800 text-brand-400 focus:ring-brand-400">
                                                    Open
                                                </label>

                                                <button type="submit" class="btn-secondary btn-sm">Save</button>
                                            </form>

                                            <form method="POST"
                                                  action="{{ route('instructor.schedule.destroy', $slot) }}"
                                                  class="mt-1 inline"
                                                  onsubmit="return confirm('Remove the {{ $name }} {{ $slot->formattedRange() }} slot?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-sm !px-1.5 text-danger-400">
                                                    <x-icon name="x" class="h-3.5 w-3.5" />
                                                    Remove
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-card>

            {{-- What a visitor sees on the public profile. --}}
            <x-card title="How students see it" subtitle="Your public schedule">
                <x-schedule-grid :grid="$grid" :days="$days" :label="'Your weekly availability'" />
            </x-card>
        </div>
    </div>
@endsection
