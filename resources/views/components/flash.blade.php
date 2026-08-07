{{--
    Server-side flash messages. Client-side transient notices use <x-toasts>.
--}}

@php
    $messages = array_filter([
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('status') ?? session('info'),
    ]);

    $styles = [
        'success' => ['icon' => 'check-circle', 'class' => 'border-success-500/30 bg-success-500/10 text-success-400'],
        'error' => ['icon' => 'x-circle', 'class' => 'border-danger-500/30 bg-danger-500/10 text-danger-400'],
        'warning' => ['icon' => 'alert', 'class' => 'border-warning-500/30 bg-warning-500/10 text-warning-400'],
        'info' => ['icon' => 'info', 'class' => 'border-brand-500/30 bg-brand-500/10 text-brand-400'],
    ];
@endphp

@if ($messages || $errors->any())
    <div class="mb-5 space-y-3">
        @foreach ($messages as $type => $message)
            <div class="flex items-start gap-3 rounded-card border px-4 py-3 text-sm {{ $styles[$type]['class'] }}"
                 role="{{ $type === 'error' ? 'alert' : 'status' }}">
                <x-icon :name="$styles[$type]['icon']" class="mt-px h-5 w-5 shrink-0" />
                <p class="flex-1">{{ $message }}</p>
            </div>
        @endforeach

        {{-- Validation errors also render inline next to each field; this is the
             summary for errors whose field is not on screen. --}}
        @if ($errors->any())
            <div class="rounded-card border border-danger-500/30 bg-danger-500/10 px-4 py-3 text-sm text-danger-400"
                 role="alert">
                <div class="flex items-start gap-3">
                    <x-icon name="x-circle" class="mt-px h-5 w-5 shrink-0" />
                    <div class="flex-1">
                        <p class="font-semibold">
                            {{ $errors->count() === 1
                                ? 'There is a problem with this form'
                                : "There are {$errors->count()} problems with this form" }}
                        </p>
                        <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
