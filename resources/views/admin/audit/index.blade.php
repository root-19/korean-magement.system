@extends('layouts.app')

@section('title', 'Audit Log')
@section('heading', 'Audit Log')
@section('subheading', $entries->total().' recorded '.Str::plural('action', $entries->total()))

@section('content')
    {{-- Replaces the legacy backup/restore screens. Those existed because rows
         were hard-deleted and six backup tables were the only record of what had
         gone; soft deletes made them redundant, so what is actually useful is a
         log of who did what. --}}
    <div class="mb-4 flex items-start gap-3 rounded-card border border-brand-500/30 bg-brand-500/10 px-4 py-3 text-sm text-brand-400">
        <x-icon name="info" class="mt-px h-5 w-5 shrink-0" />
        <p>
            Nothing in this system is hard-deleted — archiving a student or instructor keeps
            every attendance and report row, so earnings are never lost. This log records
            who changed what.
        </p>
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        <select name="action" onchange="this.form.submit()" class="form-select !w-auto text-xs"
                aria-label="Filter by action">
            <option value="">All actions</option>
            @foreach ($actions as $option)
                <option value="{{ $option }}" @selected($action === $option)>{{ $option }}</option>
            @endforeach
        </select>

        @if ($action !== '')
            <a href="{{ route('admin.audit.index') }}" class="btn-ghost btn-sm">Clear</a>
        @endif
    </form>

    <x-card flush>
        @if ($entries->isEmpty())
            <x-empty-state icon="database"
                           title="Nothing recorded yet"
                           message="Actions that move money or change enrolment are logged here." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>Subject</th>
                            <th>By</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="numeric whitespace-nowrap text-gray-400">
                                    {{ $entry->created_at?->format('M j, Y g:i A') }}
                                    <span class="block text-[11px] text-gray-600">
                                        {{ $entry->created_at?->diffForHumans() }}
                                    </span>
                                </td>

                                <td>
                                    <span class="badge-brand">{{ $entry->action }}</span>
                                </td>

                                <td class="truncate font-medium text-white">
                                    {{ $entry->target_name ?: '—' }}
                                </td>

                                <td class="truncate text-gray-300">
                                    {{ $entry->user?->name ?? 'system' }}
                                </td>

                                <td class="max-w-sm">
                                    @if ($entry->details)
                                        <details class="text-xs">
                                            <summary class="cursor-pointer text-gray-400 hover:text-brand-400">
                                                {{ count($entry->details) }} {{ Str::plural('field', count($entry->details)) }}
                                            </summary>
                                            <dl class="mt-1.5 space-y-0.5">
                                                @foreach ($entry->details as $key => $value)
                                                    <div class="flex gap-2">
                                                        <dt class="text-gray-500">{{ $key }}:</dt>
                                                        <dd class="numeric truncate text-gray-300">
                                                            {{ is_scalar($value) ? $value : json_encode($value) }}
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </details>
                                    @else
                                        <span class="text-xs text-gray-600">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($entries->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">{{ $entries->links() }}</div>
            @endif
        @endif
    </x-card>
@endsection
