{{--
    Ask an admin to delete a student.

    Opened from a student row with

        $dispatch('open-delete-modal', { studentName, action, sessions, rejectedNote })

    This form deletes nothing. It records the reason and puts the student in the
    admin's deletion queue; the reason is what the admin decides on.
--}}

<div x-data="{
        show: false,
        row: null,
        start(detail) { this.row = detail; this.show = true },
     }"
     x-on:open-delete-modal.window="start($event.detail)"
     x-on:keydown.escape.window="show = false">

    <div x-show="show" x-cloak class="relative z-50" role="dialog" aria-modal="true" aria-labelledby="delete-title">
        <div x-show="show" x-transition.opacity x-on:click="show = false" class="fixed inset-0 bg-gray-900/50"></div>

        <div class="fixed inset-0 flex items-end justify-center overflow-y-auto p-4 sm:items-center">
            <div x-show="show" x-transition x-on:click.stop class="card w-full max-w-md p-5">
                <template x-if="row">
                    <form method="POST" x-bind:action="row.action" class="space-y-4">
                        @csrf

                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="delete-title" class="text-sm font-semibold text-white">Request deletion</h2>
                                <p class="mt-0.5 truncate text-xs text-gray-500" x-text="row.studentName"></p>
                            </div>

                            <button type="button" x-on:click="show = false" class="btn-ghost !p-1.5" aria-label="Close">
                                <x-icon name="x" class="h-4 w-4" />
                            </button>
                        </div>

                        <p class="rounded-lg border border-warning-500/30 bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                            An admin has to approve this first — nothing happens until then. Once
                            approved the student is gone from your lists for good and cannot be
                            added back by you.
                        </p>

                        <p x-show="row.sessions > 0" x-cloak class="text-xs text-gray-500">
                            Your
                            <span class="numeric font-semibold text-gray-300" x-text="row.sessions"></span>
                            recorded <span x-text="row.sessions === 1 ? 'class' : 'classes'"></span>
                            with this student are kept, so your payslips do not change.
                        </p>

                        <div x-show="row.rejectedNote" x-cloak
                             class="rounded-lg border border-warning-500/30 bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                            <span class="font-semibold">Previously rejected:</span>
                            <span x-text="row.rejectedNote"></span>
                        </div>

                        <div>
                            <label for="delete-reason" class="form-label">Why should this student be deleted?</label>
                            <textarea id="delete-reason"
                                      name="reason"
                                      rows="4"
                                      required
                                      minlength="5"
                                      maxlength="1000"
                                      class="form-textarea"
                                      placeholder="e.g. Enrolled twice by mistake — this account has never been used and the classes are all on the other one."></textarea>
                            <p class="mt-1.5 text-xs text-gray-500">
                                The admin sees this and nothing else, so give them enough to decide on.
                            </p>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-gray-700 pt-4">
                            <button type="button" x-on:click="show = false" class="btn-secondary">Cancel</button>
                            <button type="submit" class="btn-danger">Send for approval</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>
</div>
