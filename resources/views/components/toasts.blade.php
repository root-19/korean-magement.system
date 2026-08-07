{{--
    Transient notifications, driven by the Alpine `toasts` store in
    resources/js/app.js. Fire one from anywhere with:

        window.notify('success', 'Attendance saved')
        $dispatch('notify', { type: 'error', message: '...' })

    Replaces the legacy SweetAlert2 CDN dependency.
--}}

<div class="no-print pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end"
     x-data
     x-on:notify.window="$store.toasts.push($event.detail.type, $event.detail.message)"
     aria-live="polite"
     aria-atomic="true">

    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-y-2 opacity-0"
             x-transition:enter-end="translate-y-0 opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-end="opacity-0"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-card border bg-gray-800 px-4 py-3 text-sm shadow-xl"
             :class="{
                 'border-success-500/40 text-success-400': toast.type === 'success',
                 'border-danger-500/40 text-danger-400': toast.type === 'error',
                 'border-warning-500/40 text-warning-400': toast.type === 'warning',
                 'border-gray-600 text-gray-200':
                     !['success','error','warning'].includes(toast.type),
             }">
            <p class="flex-1" x-text="toast.message"></p>
            <button type="button"
                    x-on:click="$store.toasts.dismiss(toast.id)"
                    class="shrink-0 opacity-60 transition hover:opacity-100"
                    aria-label="Dismiss">
                <x-icon name="x" class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>
