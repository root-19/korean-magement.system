{{--
    Record a class taught ahead of schedule.

    The instructor gives the date the class was actually TAUGHT and the future
    slot it covers. The session row stays on the future slot so the timetable
    stays honest, while `held_date` carries the real date so the work is paid in
    the week it was done.
--}}

<div x-data="{ open: false }"
     x-on:open-early-modal.window="open = true"
     x-on:keydown.escape.window="open = false">

    <div x-show="open" x-cloak class="relative z-50" role="dialog" aria-modal="true" aria-labelledby="early-title">
        <div x-show="open"
             x-transition.opacity
             x-on:click="open = false"
             class="fixed inset-0 bg-gray-900/50"></div>

        <div class="fixed inset-0 flex items-end justify-center p-4 sm:items-center">
            <div x-show="open"
                 x-transition
                 x-on:click.stop
                 class="card w-full max-w-md p-5">

                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="early-title" class="text-sm font-semibold text-white">
                            Record an early class
                        </h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            For a session you taught ahead of its scheduled date.
                        </p>
                    </div>

                    <button type="button" x-on:click="open = false" class="btn-ghost !p-1.5" aria-label="Close">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>

                <form method="POST" action="{{ route('instructor.classes.early') }}" class="mt-4 space-y-4">
                    @csrf

                    <div>
                        <label for="early-student" class="form-label">Student</label>
                        <select id="early-student" name="student_id" required class="form-select">
                            <option value="">Choose a student…</option>
                            @foreach ($roster as $row)
                                <option value="{{ $row['student']->id }}">
                                    {{ $row['student']->name }}
                                    ({{ $row['profile']?->sessions_remaining ?? 0 }} left)
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-gray-500">
                            Only students scheduled on {{ $date->format('l') }}s are listed here.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="early-held" class="form-label">Taught on</label>
                            {{-- `min` as well as `max`: paid_date follows this
                                 field, so a date in an earlier week pays into
                                 that week. The endpoint refuses a closed date
                                 outright; this keeps the picker from offering
                                 one in the first place. --}}
                            <input id="early-held"
                                   name="held_date"
                                   type="date"
                                   value="{{ $date->toDateString() }}"
                                   min="{{ $date->toDateString() }}"
                                   max="{{ now()->toDateString() }}"
                                   required
                                   class="form-input numeric">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Paid in the week this date falls in.
                            </p>
                        </div>

                        <div>
                            <label for="early-target" class="form-label">Covers session on</label>
                            <input id="early-target"
                                   name="target_date"
                                   type="date"
                                   min="{{ $date->addDay()->toDateString() }}"
                                   required
                                   class="form-input numeric">
                            <p class="mt-1.5 text-xs text-gray-500">
                                A future scheduled slot.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-gray-700 pt-4">
                        <button type="button" x-on:click="open = false" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Record class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
