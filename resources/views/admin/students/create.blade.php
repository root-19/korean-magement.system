@extends('layouts.app')

@section('title', __('Add Student'))
@section('heading', __('Add Student'))
@section('subheading', __('Enrol a student and assign their instructor'))

@section('actions')
    <a href="{{ route('admin.students.index') }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        {{ __('All students') }}
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.students.store') }}" class="grid gap-4 lg:grid-cols-3">
        @csrf

        <div class="space-y-4 lg:col-span-2">
            <x-card :title="__('Student details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="form-label">{{ __('Name') }}</label>
                        <input id="name" name="name" type="text" required value="{{ old('name') }}"
                               class="form-input" placeholder="A540 Hyun Seo">
                        @error('name') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            {{ __('Include the enrolment code if there is one, as in “A540 Hyun Seo”.') }}
                        </p>
                    </div>

                    <div>
                        <label for="kakaotalk_id" class="form-label">{{ __('KakaoTalk ID') }}</label>
                        <input id="kakaotalk_id" name="kakaotalk_id" type="text" value="{{ old('kakaotalk_id') }}"
                               class="form-input">
                        @error('kakaotalk_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="phone" class="form-label">{{ __('Phone') }}</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone') }}" class="form-input">
                        @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="email" class="form-label">{{ __('Email') }} <span class="text-gray-500">{{ __('(optional)') }}</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" class="form-input">
                        @error('email') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            {{ __('Most students have none — a temporary password is generated either way, and shown to you once after saving.') }}
                        </p>
                    </div>
                </div>
            </x-card>

            <x-card :title="__('Plan')">
                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- Regular or trial, asked outright rather than left to a
                         single ticked-by-default box. It decides which class list
                         the student lands on and whether their name carries a
                         Trial tag on the dashboard and class roster. --}}
                    <div class="sm:col-span-2" x-data="{ type: '{{ old('is_regular', '') }}' }">
                        <span class="form-label">{{ __('Enrolment type') }}</span>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ([
                                '1' => [__('Regular'), __('Fixed weekly timetable and an ongoing plan.')],
                                '0' => [__('Trial'), __('A trial or one-off. Tagged “Trial” on the dashboard and classes.')],
                            ] as $value => $option)
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 transition"
                                       x-bind:class="type === '{{ $value }}' ? 'border-brand-400 bg-brand-500/10' : 'border-gray-700'">
                                    <input type="radio"
                                           name="is_regular"
                                           value="{{ $value }}"
                                           required
                                           x-model="type"
                                           {{-- (string): PHP casts the numeric keys of the array above to ints. --}}
                                           @checked((string) $value === old('is_regular'))
                                           class="mt-0.5 border-gray-600 bg-gray-800 text-brand-400 focus:ring-brand-400">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-white">{{ $option[0] }}</span>
                                        <span class="block text-xs text-gray-500">{{ $option[1] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @error('is_regular') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="teaching_method" class="form-label">{{ __('Class type') }}</label>
                        <select id="teaching_method" name="teaching_method" required class="form-select">
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}" @selected(old('teaching_method') === $method->value)>
                                    {{ __(':method — :rate/hr', ['method' => $method->label(), 'rate' => money($method->hourlyRate())]) }}
                                </option>
                            @endforeach
                        </select>
                        @error('teaching_method') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="learning_time" class="form-label">{{ __('Minutes per class') }}</label>
                        <select id="learning_time" name="learning_time" required class="form-select">
                            @foreach ($learningTimes as $minutes)
                                <option value="{{ $minutes }}" @selected((int) old('learning_time', 25) === $minutes)>
                                    {{ __(':minutes min', ['minutes' => $minutes]) }}
                                </option>
                            @endforeach
                        </select>
                        @error('learning_time') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="sessions_purchased" class="form-label">{{ __('Sessions purchased') }}</label>
                        <input id="sessions_purchased" name="sessions_purchased" type="number" min="1" max="500"
                               required value="{{ old('sessions_purchased', 30) }}" class="form-input numeric">
                        @error('sessions_purchased') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="sessions_deducted" class="form-label">
                            {{ __('Sessions written off') }} <span class="text-gray-500">{{ __('(optional)') }}</span>
                        </label>
                        <input id="sessions_deducted" name="sessions_deducted" type="number" min="0" max="500"
                               value="{{ old('sessions_deducted', 0) }}" class="form-input numeric">
                        @error('sessions_deducted') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            {{ __('Deducted from the purchased total at enrolment. Legacy name: “deduction days”.') }}
                        </p>
                    </div>

                    <div>
                        <label for="start_date" class="form-label">{{ __('Start date') }}</label>
                        <input id="start_date" name="start_date" type="date"
                               value="{{ old('start_date', now()->toDateString()) }}" class="form-input numeric">
                        @error('start_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-card>

            {{-- One row per class day, not a comma-joined string plus seven
                 nullable time columns. --}}
            <x-card :title="__('Weekly schedule')" :subtitle="__('Tick each class day and set its time')">
                <div class="space-y-2">
                    @foreach ($days as $iso => $name)
                        <div x-data="{ on: {{ old('schedule.'.$iso) ? 'true' : 'false' }} }"
                             class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-700 px-3 py-2">
                            <label class="flex w-32 items-center gap-2 text-sm text-gray-300">
                                <input type="checkbox" x-model="on"
                                       class="rounded border-gray-600 bg-gray-800 text-brand-400 focus:ring-brand-400">
                                {{ $name }}
                            </label>

                            <input type="time"
                                   name="schedule[{{ $iso }}]"
                                   value="{{ old('schedule.'.$iso) }}"
                                   x-bind:disabled="!on"
                                   class="form-input numeric !w-auto !py-1 text-xs disabled:opacity-40"
                                   aria-label="{{ __(':day class time', ['day' => $name]) }}">

                            <span x-show="!on" class="text-xs text-gray-500">{{ __('No class') }}</span>
                        </div>
                    @endforeach
                </div>

                @error('schedule.*') <p class="form-error">{{ $message }}</p> @enderror
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card :title="__('Assigned instructor')">
                <select name="instructor_id" class="form-select" aria-label="{{ __('Instructor') }}">
                    <option value="">{{ __('— Unassigned —') }}</option>
                    @foreach ($instructors as $instructor)
                        <option value="{{ $instructor->id }}"
                            @selected((int) old('instructor_id') === $instructor->id)>
                            {{ $instructor->name }}
                        </option>
                    @endforeach
                </select>
                @error('instructor_id') <p class="form-error">{{ $message }}</p> @enderror

                <p class="mt-3 text-xs text-gray-500">
                    {{ __('Leave unassigned to park the student until an instructor is free — they appear under the Unassigned filter until then.') }}
                </p>
            </x-card>

            <x-card :title="__('What happens next')">
                <p class="text-sm text-gray-300">
                    {{ __('An admin enrolment is approved on save: there is nobody left to approve it. The student is teachable and billable straight away.') }}
                </p>

                <p class="mt-3 rounded-lg bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                    {{ __('The temporary password is shown once, on the confirmation banner after saving. Note it down then.') }}
                </p>
            </x-card>

            <div class="flex flex-col gap-2">
                <button type="submit" class="btn-primary">
                    <x-icon name="user-plus" class="h-4 w-4" />
                    {{ __('Add student') }}
                </button>
                <a href="{{ route('admin.students.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
@endsection
