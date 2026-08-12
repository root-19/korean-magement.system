{{--
    Postpone a class: who postponed it, why, and when it comes back.

    Included once per page. Rows open it with

        $dispatch('open-postpone-modal', { studentId, studentName, date, dateLabel, makeup })

    where `makeup` is MakeupSchedule::toArray(). The row's plain-form Postpone
    button stays a real submit, so with JavaScript off the class is still
    postponed and the server picks the makeup date itself; this modal is what
    lets the instructor override it.

    A postponed class never pays and never deducts, and the student keeps the
    prepaid session — see AttendanceService::consumesSession and rule 1 in
    EarningsCalculator. The banner says so because the legacy modal did, and
    instructors asked.
--}}

<div x-data="{
        show: false,
        row: null,
        mode: 'auto',
        manualDate: '',
        time: '',

        // A student can have dozens of classes left, and the full preview then
        // pushes the time field and the buttons off the modal. Only the first
        // few dates show until the instructor asks for the rest.
        previewLimit: 4,
        previewOpen: false,

        get previewHidden() {
            return Math.max(0, (this.row?.makeup.preview.length ?? 0) - this.previewLimit);
        },

        start(detail) {
            this.row = detail;
            // No timetable means no slot to append to, so there is nothing to
            // compute and the date has to be picked by hand.
            this.mode = detail.makeup.autoDate ? 'auto' : 'manual';
            this.manualDate = detail.makeup.autoDate || detail.makeup.minDate;
            this.time = detail.makeup.defaultTime || '';
            this.previewOpen = false;
            this.show = true;
        },
     }"
     x-on:open-postpone-modal.window="start($event.detail)"
     x-on:keydown.escape.window="show = false">

    <div x-show="show" x-cloak class="relative z-50" role="dialog" aria-modal="true" aria-labelledby="postpone-title">
        <div x-show="show"
             x-transition.opacity
             x-on:click="show = false"
             class="fixed inset-0 bg-gray-900/50"></div>

        <div class="fixed inset-0 flex items-end justify-center overflow-y-auto p-4 sm:items-center">
            <div x-show="show" x-transition x-on:click.stop class="card w-full max-w-lg p-5">
                <template x-if="row">
                    <form method="POST" action="{{ route('instructor.classes.attendance') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="status" value="postponed">
                        <input type="hidden" name="student_id" x-bind:value="row.studentId">
                        <input type="hidden" name="date" x-bind:value="row.date">

                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="postpone-title" class="text-sm font-semibold text-white">Postpone class</h2>
                                <p class="mt-0.5 truncate text-xs text-gray-500">
                                    <span x-text="row.studentName"></span> ·
                                    <span x-text="row.dateLabel"></span>
                                </p>
                            </div>

                            <button type="button" x-on:click="show = false" class="btn-ghost !p-1.5" aria-label="Close">
                                <x-icon name="x" class="h-4 w-4" />
                            </button>
                        </div>

                        <p class="rounded-lg border border-success-500/30 bg-success-500/10 px-3 py-2 text-xs text-success-400">
                            <strong class="font-semibold">No deduction, no session used.</strong>
                            Postponed by student, by you, or by anyone else — the class was not
                            taught, so it neither pays nor is deducted, and the student keeps the
                            prepaid session.
                        </p>

                        <div>
                            <label for="postpone-party" class="form-label">Who is postponing?</label>
                            <select id="postpone-party" name="party" required class="form-select">
                                <option value="">Select…</option>
                                <option value="student">Student</option>
                                <option value="teacher">Me (the teacher)</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div>
                            <label for="postpone-reason" class="form-label">Reason <span class="text-gray-500">(optional)</span></label>
                            <textarea id="postpone-reason"
                                      name="reason"
                                      rows="2"
                                      maxlength="1000"
                                      class="form-input"
                                      placeholder="Why the class is being moved…"></textarea>
                        </div>

                        <div class="rounded-lg bg-gray-800/60 px-3 py-2 text-xs text-gray-400">
                            <strong class="font-semibold text-gray-300">Schedule:</strong>
                            <span x-text="row.makeup.scheduleLabel"></span>
                            <span class="text-gray-600">|</span>
                            <strong class="font-semibold text-gray-300">Remaining:</strong>
                            <span class="numeric" x-text="row.makeup.sessionsRemaining"></span>
                            classes
                        </div>

                        <fieldset class="space-y-2">
                            <legend class="form-label">When does it come back?</legend>

                            <label x-show="row.makeup.autoDate"
                                   class="flex cursor-pointer gap-2.5 rounded-lg border border-gray-700 p-3 transition"
                                   x-bind:class="mode === 'auto' ? 'border-brand-400 bg-brand-500/5' : 'hover:border-gray-600'">
                                <input type="radio" name="reschedule" value="auto" x-model="mode" class="mt-0.5 form-radio">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-white">
                                        Auto — after their last remaining class
                                    </span>
                                    <span class="mt-0.5 block text-xs text-success-400" x-text="row.makeup.autoLabel"></span>
                                    <span class="mt-1 block text-[11px] text-gray-500">
                                        The postponed class is added after the last class the student
                                        still has left, so it becomes their new final class.
                                    </span>

                                    <span class="mt-2 block rounded-md bg-gray-900/60 p-2 text-[11px] leading-relaxed">
                                        <span class="block text-gray-500">Schedule preview</span>

                                        <span class="block"
                                              x-bind:class="previewOpen ? 'max-h-48 overflow-y-auto pr-1' : ''">
                                            <span class="block text-danger-400">
                                                <s x-text="row.dateShort"></s> postponed
                                            </span>
                                            <template x-for="(slot, i) in row.makeup.preview" x-bind:key="slot.label">
                                                <span class="block"
                                                      x-show="previewOpen || i < previewLimit"
                                                      x-bind:class="slot.isMakeup ? 'text-success-400 font-medium' : 'text-gray-400'">
                                                    <span x-text="slot.label"></span>
                                                    <span x-show="slot.isMakeup">— makeup class</span>
                                                </span>
                                            </template>
                                        </span>

                                        {{-- Inside a <label>, so the click must not reach it and flip the radio. --}}
                                        <button type="button"
                                                x-show="previewHidden > 0"
                                                x-on:click.prevent.stop="previewOpen = ! previewOpen"
                                                class="mt-1.5 font-medium text-brand-400 hover:text-accent-400"
                                                x-text="previewOpen
                                                    ? 'See less'
                                                    : 'See more (' + previewHidden + ' more ' + (previewHidden === 1 ? 'class' : 'classes') + ')'">
                                        </button>
                                    </span>
                                </span>
                            </label>

                            <label class="flex cursor-pointer gap-2.5 rounded-lg border border-gray-700 p-3 transition"
                                   x-bind:class="mode === 'manual' ? 'border-brand-400 bg-brand-500/5' : 'hover:border-gray-600'">
                                <input type="radio" name="reschedule" value="manual" x-model="mode" class="mt-0.5 form-radio">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-white">Pick the date myself</span>

                                    <input type="date"
                                           name="rescheduled_date"
                                           class="form-input numeric mt-2"
                                           x-model="manualDate"
                                           x-bind:min="row.makeup.minDate"
                                           x-bind:required="mode === 'manual'"
                                           x-bind:disabled="mode !== 'manual'">

                                    <span x-show="! row.makeup.autoDate"
                                          class="mt-1.5 block text-[11px] text-warning-400">
                                        This student has no weekly timetable, so there is no slot to
                                        append to — choose the date here.
                                    </span>
                                </span>
                            </label>
                        </fieldset>

                        <div>
                            <label for="postpone-time" class="form-label">Makeup class time</label>
                            <input id="postpone-time"
                                   type="time"
                                   name="rescheduled_time"
                                   class="form-input numeric"
                                   x-model="time">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Left empty, the makeup takes the student's usual time on that weekday.
                            </p>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-gray-700 pt-4">
                            <button type="button" x-on:click="show = false" class="btn-secondary">Cancel</button>
                            <button type="submit" class="btn-primary">Postpone class</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>
</div>
