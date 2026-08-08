{{--
    Ask an admin to reopen a class that has already passed.

    Opened from a roster row with

        $dispatch('open-evaluation-modal', { studentId, studentName, date, dateLabel, rejectedNote })

    Marking a session releases its payment, so a class stops being markable the
    day after it happened. This is the way back in, and the reason is what the
    admin decides on.
--}}

<div x-data="{
        show: false,
        row: null,
        start(detail) { this.row = detail; this.show = true },
     }"
     x-on:open-evaluation-modal.window="start($event.detail)"
     x-on:keydown.escape.window="show = false">

    <div x-show="show" x-cloak class="relative z-50" role="dialog" aria-modal="true" aria-labelledby="evaluation-title">
        <div x-show="show" x-transition.opacity x-on:click="show = false" class="fixed inset-0 bg-gray-900/50"></div>

        <div class="fixed inset-0 flex items-end justify-center overflow-y-auto p-4 sm:items-center">
            <div x-show="show" x-transition x-on:click.stop class="card w-full max-w-md p-5">
                <template x-if="row">
                    <form method="POST" action="{{ route('instructor.classes.evaluation') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="student_id" x-bind:value="row.studentId">
                        <input type="hidden" name="date" x-bind:value="row.date">

                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="evaluation-title" class="text-sm font-semibold text-white">Send for evaluation</h2>
                                <p class="mt-0.5 truncate text-xs text-gray-500">
                                    <span x-text="row.studentName"></span> · <span x-text="row.dateLabel"></span>
                                </p>
                            </div>

                            <button type="button" x-on:click="show = false" class="btn-ghost !p-1.5" aria-label="Close">
                                <x-icon name="x" class="h-4 w-4" />
                            </button>
                        </div>

                        <p class="rounded-lg border border-warning-500/30 bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                            This class is no longer today, so marking it would change a past payout.
                            An admin has to approve it first. Once approved, Present, Absent and
                            Postpone come back for this class only.
                        </p>

                        <div x-show="row.rejectedNote" x-cloak
                             class="rounded-lg border border-danger-500/30 bg-danger-500/10 px-3 py-2 text-xs text-danger-400">
                            <span class="font-semibold">Previously rejected:</span>
                            <span x-text="row.rejectedNote"></span>
                        </div>

                        <div>
                            <label for="evaluation-reason" class="form-label">Why was it not marked on the day?</label>
                            <textarea id="evaluation-reason"
                                      name="reason"
                                      rows="4"
                                      required
                                      minlength="5"
                                      maxlength="1000"
                                      class="form-textarea"
                                      placeholder="e.g. Internet went down during the class and I could not open the system until today."></textarea>
                            <p class="mt-1.5 text-xs text-gray-500">
                                The admin sees this and nothing else, so give them enough to decide on.
                            </p>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-gray-700 pt-4">
                            <button type="button" x-on:click="show = false" class="btn-secondary">Cancel</button>
                            <button type="submit" class="btn-primary">Send for evaluation</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>
</div>
