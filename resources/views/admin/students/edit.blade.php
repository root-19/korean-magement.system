@extends('layouts.app')

@section('title', 'Edit '.$student->name)
@section('heading', 'Edit '.$student->name)
@section('subheading', 'Student · '.($profile->instructor?->name ?? 'unassigned'))

@section('actions')
    <a href="{{ route('admin.students.show', $student) }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        Back to student
    </a>
@endsection

@section('content')
    @if ($student->trashed())
        <div class="mb-4 flex items-start gap-3 rounded-card border border-danger-500/30 bg-danger-500/10 px-4 py-3 text-sm text-danger-400">
            <x-icon name="alert" class="mt-px h-5 w-5 shrink-0" />
            <p>
                This student is deleted and appears in no list. Edits still save, but
                restore them from the student page for anything here to take effect.
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.students.update', $student) }}" class="grid gap-4 lg:grid-cols-3">
        @csrf
        @method('PATCH')

        <div class="space-y-4 lg:col-span-2">
            <x-card title="Account">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" type="text" required value="{{ old('name', $student->name) }}"
                               class="form-input" placeholder="A540 Hyun Seo">
                        @error('name') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            The enrolment code is part of the name, as in “A540 Hyun Seo”.
                            Renaming does not change any attendance or report already filed.
                        </p>
                    </div>

                    <div>
                        <label for="kakaotalk_id" class="form-label">KakaoTalk ID</label>
                        <input id="kakaotalk_id" name="kakaotalk_id" type="text"
                               value="{{ old('kakaotalk_id', $profile->kakaotalk_id) }}" class="form-input">
                        @error('kakaotalk_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="phone" class="form-label">Phone</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone', $student->phone) }}"
                               class="form-input">
                        @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="form-label">Email <span class="text-gray-500">(optional)</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email', $student->email) }}"
                               class="form-input">
                        @error('email') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="birthday" class="form-label">Birthday <span class="text-gray-500">(optional)</span></label>
                        <input id="birthday" name="birthday" type="date"
                               value="{{ old('birthday', $student->birthday?->format('Y-m-d')) }}"
                               class="form-input numeric">
                        @error('birthday') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-card>

            <x-card title="Plan">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"
                         x-data="{ type: '{{ old('is_regular', $profile->is_regular ? '1' : '0') }}' }">
                        <span class="form-label">Enrolment type</span>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ([
                                '1' => ['Regular', 'Fixed weekly timetable and an ongoing plan.'],
                                '0' => ['Trial', 'A trial or one-off. Tagged “Trial” on the dashboard and classes.'],
                            ] as $value => $option)
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 transition"
                                       x-bind:class="type === '{{ $value }}' ? 'border-brand-400 bg-brand-500/10' : 'border-gray-700'">
                                    <input type="radio"
                                           name="is_regular"
                                           value="{{ $value }}"
                                           required
                                           x-model="type"
                                           {{-- (string): PHP casts the numeric keys of the array above to ints. --}}
                                           @checked((string) $value === old('is_regular', $profile->is_regular ? '1' : '0'))
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

                    {{-- Both left blank-able: legacy rows arrived with no class type
                         or duration, and forcing a value on the next edit would
                         silently restate what that student's classes are worth. --}}
                    <div>
                        <label for="teaching_method" class="form-label">Class type</label>
                        <select id="teaching_method" name="teaching_method" class="form-select">
                            <option value="">— Not set —</option>
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}"
                                    @selected(old('teaching_method', $profile->teaching_method?->value) === $method->value)>
                                    {{ $method->label() }} — {{ money($method->hourlyRate()) }}/hr
                                </option>
                            @endforeach
                        </select>
                        @error('teaching_method') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            Sets the pay rate for classes taught from now on. Sessions
                            already recorded keep what they were worth.
                        </p>
                    </div>

                    <div>
                        <label for="learning_time" class="form-label">Minutes per class</label>
                        <select id="learning_time" name="learning_time" class="form-select">
                            <option value="">— Not set —</option>
                            @foreach ($learningTimes as $minutes)
                                <option value="{{ $minutes }}"
                                    @selected((int) old('learning_time', $profile->learning_time) === $minutes)>
                                    {{ $minutes }} min
                                </option>
                            @endforeach
                        </select>
                        @error('learning_time') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="start_date" class="form-label">Start date</label>
                        <input id="start_date" name="start_date" type="date"
                               value="{{ old('start_date', $profile->start_date?->format('Y-m-d')) }}"
                               class="form-input numeric">
                        @error('start_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="end_date" class="form-label">End date <span class="text-gray-500">(optional)</span></label>
                        <input id="end_date" name="end_date" type="date"
                               value="{{ old('end_date', $profile->end_date?->format('Y-m-d')) }}"
                               class="form-input numeric">
                        @error('end_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-card>

            {{-- purchased = attended + student-absent + remaining + deducted --}}
            <x-card title="Session counters" subtitle="What the student has left, used and written off">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="sessions_remaining" class="form-label">Remaining</label>
                        <input id="sessions_remaining" name="sessions_remaining" type="number" min="0" max="65535"
                               required value="{{ old('sessions_remaining', $profile->sessions_remaining) }}"
                               class="form-input numeric">
                        @error('sessions_remaining') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="sessions_attended" class="form-label">Attended</label>
                        <input id="sessions_attended" name="sessions_attended" type="number" min="0" max="65535"
                               required value="{{ old('sessions_attended', $profile->sessions_attended) }}"
                               class="form-input numeric">
                        @error('sessions_attended') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="sessions_deducted" class="form-label">Written off</label>
                        <input id="sessions_deducted" name="sessions_deducted" type="number" min="0" max="65535"
                               required value="{{ old('sessions_deducted', $profile->sessions_deducted) }}"
                               class="form-input numeric">
                        @error('sessions_deducted') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="mt-3 text-xs text-gray-500">
                    Purchased = attended + student-absent + remaining + written off.
                    This student currently totals
                    <strong class="numeric text-gray-300">{{ $stats['purchased'] }}</strong>,
                    including <span class="numeric">{{ $stats['student_absent'] }}</span>
                    student-absent {{ Str::plural('class', $stats['student_absent']) }}.
                </p>

                @if ($stats['counter_attended'] !== $stats['present'])
                    <p class="mt-3 rounded-lg bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                        The attended counter reads
                        <strong class="numeric">{{ $stats['counter_attended'] }}</strong>
                        but <strong class="numeric">{{ $stats['present'] }}</strong>
                        present {{ Str::plural('session', $stats['present']) }}
                        {{ $stats['present'] === 1 ? 'is' : 'are' }} recorded. Attendance rows
                        are the source of truth — correcting the counter here does not change
                        anyone's earnings.
                    </p>
                @endif
            </x-card>

            <x-card title="Weekly schedule" subtitle="Tick each class day and set its time">
                <div class="space-y-2">
                    @foreach ($days as $iso => $name)
                        @php $time = old('schedule.'.$iso, $schedule[$iso] ?? ''); @endphp

                        <div x-data="{ on: {{ $time ? 'true' : 'false' }} }"
                             class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-700 px-3 py-2">
                            <label class="flex w-32 items-center gap-2 text-sm text-gray-300">
                                <input type="checkbox" x-model="on"
                                       class="rounded border-gray-600 bg-gray-800 text-brand-400 focus:ring-brand-400">
                                {{ $name }}
                            </label>

                            <input type="time"
                                   name="schedule[{{ $iso }}]"
                                   value="{{ $time }}"
                                   x-bind:disabled="!on"
                                   class="form-input numeric !w-auto !py-1 text-xs disabled:opacity-40"
                                   aria-label="{{ $name }} class time">

                            <span x-show="!on" class="text-xs text-gray-500">No class</span>
                        </div>
                    @endforeach
                </div>

                @error('schedule.*') <p class="form-error">{{ $message }}</p> @enderror

                <p class="mt-3 text-xs text-gray-500">
                    Unticking a day removes it from the timetable. Classes already
                    recorded on that day are untouched.
                </p>
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card title="Assigned instructor">
                <select name="instructor_id" class="form-select" aria-label="Instructor">
                    <option value="">— Unassigned —</option>
                    @foreach ($instructors as $instructor)
                        <option value="{{ $instructor->id }}"
                            @selected((int) old('instructor_id', $profile->instructor_id) === $instructor->id)>
                            {{ $instructor->name }}
                        </option>
                    @endforeach
                </select>
                @error('instructor_id') <p class="form-error">{{ $message }}</p> @enderror

                <p class="mt-3 text-xs text-gray-500">
                    Past sessions keep whoever taught them — reassigning does not move
                    earnings already recorded.
                </p>
            </x-card>

            <x-card title="Enrolment">
                <div x-data="{ status: '{{ old('enrollment_status', $profile->enrollment_status->value) }}' }">
                    <label for="enrollment_status" class="form-label">Status</label>
                    <select id="enrollment_status" name="enrollment_status" x-model="status" class="form-select">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}"
                                @selected(old('enrollment_status', $profile->enrollment_status->value) === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('enrollment_status') <p class="form-error">{{ $message }}</p> @enderror

                    <div x-show="status === 'rejected'" x-cloak class="mt-3">
                        <label for="rejection_reason" class="form-label">Reason</label>
                        <textarea id="rejection_reason" name="rejection_reason" rows="3"
                                  class="form-textarea">{{ old('rejection_reason', $profile->rejection_reason) }}</textarea>
                        @error('rejection_reason') <p class="form-error">{{ $message }}</p> @enderror

                        <p class="mt-1.5 text-xs text-warning-400">
                            Rejecting also archives the student. Nothing is deleted — their
                            attendance and reports stay, so instructor earnings are unaffected.
                        </p>
                    </div>
                </div>

                @if ($profile->enrollment_decided_at)
                    <p class="mt-3 text-xs text-gray-500">
                        Last decided {{ $profile->enrollment_decided_at->format('M j, Y') }}.
                    </p>
                @endif
            </x-card>

            <div class="flex flex-col gap-2">
                <button type="submit" class="btn-primary">
                    <x-icon name="check" class="h-4 w-4" />
                    Save changes
                </button>
                <a href="{{ route('admin.students.show', $student) }}" class="btn-secondary">Cancel</a>
            </div>

            <p class="text-xs text-gray-500">
                Archiving, restoring and deletion live on the student page — they are
                account decisions, not details.
            </p>
        </div>
    </form>
@endsection
