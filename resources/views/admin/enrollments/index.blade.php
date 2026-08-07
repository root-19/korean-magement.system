@extends('layouts.app')

@section('title', 'Enrolments')
@section('heading', 'Enrolments')
@section('subheading', $enrollments->total().' '.$status->label().' '.Str::plural('enrolment', $enrollments->total()))

@section('content')
    {{-- Status tabs with live counts. --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 rounded-lg border border-gray-700 bg-gray-800 p-1">
        @foreach (App\Enums\EnrollmentStatus::cases() as $case)
            <a href="{{ route('admin.enrollments.index', ['status' => $case->value]) }}"
               @class([
                   'focus-ring flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                   'bg-gray-700 text-brand-400' => $status === $case,
                   'text-gray-400 hover:text-white' => $status !== $case,
               ])>
                {{ $case->label() }}
                <span class="numeric rounded-full bg-gray-900/60 px-1.5 py-0.5 text-[10px]">
                    {{ $counts[$case->value] }}
                </span>
            </a>
        @endforeach
    </div>

    @if ($enrollments->isEmpty())
        <x-card>
            <x-empty-state icon="check-circle"
                           title="Nothing {{ strtolower($status->label()) }}"
                           message="{{ $status === App\Enums\EnrollmentStatus::Pending
                               ? 'Every enrolment has been decided. Instructors can enrol new students at any time.'
                               : 'No enrolments in this state.' }}" />
        </x-card>
    @else
        <div class="space-y-4">
            @foreach ($enrollments as $enrollment)
                @php $student = $enrollment->user; @endphp

                <x-card>
                    <div class="flex flex-wrap items-start gap-4">
                        <x-avatar :user="$student" class="h-12 w-12" />

                        {{-- Identity + plan --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-semibold text-white">{{ $student?->name }}</h2>
                                <span class="{{ $enrollment->enrollment_status->badgeClass() }}">
                                    {{ $enrollment->enrollment_status->label() }}
                                </span>
                                @if ($student && ! $student->is_active)
                                    <span class="badge-neutral">Deactivated</span>
                                @endif
                            </div>

                            <p class="mt-1 text-sm text-gray-400">
                                Enrolled by
                                <span class="font-medium text-gray-300">
                                    {{ $enrollment->instructor?->name ?? 'nobody — unassigned' }}
                                </span>
                                · {{ $enrollment->created_at?->format('M j, Y') }}
                            </p>

                            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ([
                                    'Type' => $enrollment->teaching_method?->label() ?? '—',
                                    'Duration' => $enrollment->learning_time ? $enrollment->learning_time.' min' : '—',
                                    'Sessions' => $enrollment->sessions_remaining,
                                    'Starts' => $enrollment->start_date?->format('M j, Y') ?? '—',
                                ] as $label => $value)
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                                        <dd class="numeric font-medium text-white">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if ($student && $student->schedules->isNotEmpty())
                                <div class="mt-3 flex flex-wrap gap-1">
                                    @foreach ($student->schedules as $slot)
                                        <span class="badge-neutral numeric">
                                            {{ $slot->shortDayName() }} {{ $slot->formattedTime() }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            @if ($enrollment->rejection_reason)
                                <p class="mt-3 rounded-lg bg-danger-500/10 px-3 py-2 text-xs text-danger-400">
                                    Rejected: {{ $enrollment->rejection_reason }}
                                </p>
                            @endif
                        </div>

                        {{-- Decision --}}
                        <div class="flex shrink-0 flex-col gap-2" x-data="{ rejecting: false }">
                            @if ($enrollment->enrollment_status === App\Enums\EnrollmentStatus::Pending)
                                <div x-show="!rejecting" class="flex flex-col gap-2">
                                    <form method="POST" action="{{ route('admin.enrollments.approve', $enrollment) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-primary w-full">
                                            <x-icon name="check" class="h-4 w-4" />
                                            Approve
                                        </button>
                                    </form>

                                    <button type="button" x-on:click="rejecting = true" class="btn-secondary">
                                        Reject
                                    </button>
                                </div>

                                {{-- A reason is asked for, not required: it is the only
                                     record of why, and it goes into the audit log. --}}
                                <form method="POST"
                                      action="{{ route('admin.enrollments.reject', $enrollment) }}"
                                      x-show="rejecting"
                                      x-cloak
                                      class="w-56 space-y-2">
                                    @csrf
                                    @method('PATCH')

                                    <label for="reason-{{ $enrollment->id }}" class="form-label !text-xs">
                                        Reason (optional)
                                    </label>
                                    <textarea id="reason-{{ $enrollment->id }}"
                                              name="reason"
                                              rows="2"
                                              class="form-textarea text-xs"
                                              placeholder="Why is this being rejected?"></textarea>

                                    <div class="flex gap-2">
                                        <button type="submit" class="btn-danger btn-sm flex-1">Confirm</button>
                                        <button type="button" x-on:click="rejecting = false" class="btn-ghost btn-sm">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.enrollments.reinstate', $enrollment) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-secondary w-full">Reinstate</button>
                                </form>
                            @endif

                            @if ($student)
                                <a href="{{ route('admin.students.show', $student) }}" class="btn-ghost btn-sm">
                                    View student
                                </a>
                            @endif
                        </div>
                    </div>

                    @if ($enrollment->enrollment_decided_at)
                        <p class="mt-3 border-t border-gray-700 pt-3 text-xs text-gray-500">
                            Decided {{ $enrollment->enrollment_decided_at->format('M j, Y g:i A') }}
                            @if ($enrollment->decidedBy)
                                by {{ $enrollment->decidedBy->name }}
                            @endif
                        </p>
                    @endif
                </x-card>
            @endforeach
        </div>

        @if ($enrollments->hasPages())
            <div class="mt-4">{{ $enrollments->links() }}</div>
        @endif
    @endif
@endsection
