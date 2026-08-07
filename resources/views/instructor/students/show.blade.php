@extends('layouts.app')

@section('title', $student->name)
@section('heading', $student->name)
@section('subheading', $profile->teaching_method?->label().' · '.$profile->learning_time.' min sessions')

@section('actions')
    <a href="{{ route('instructor.students.index') }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        All students
    </a>
@endsection

@section('content')
    {{-- Session accounting.

         purchased = attended + student-absent + remaining + deducted

         A student-absent class burns a prepaid session but counts as neither
         attended nor remaining, which is why it is its own column. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-stat-card label="Purchased" value="{{ $stats['purchased'] }}" icon="book" hint="Total plan" />
        <x-stat-card label="Attended" value="{{ $stats['present'] }}" icon="check-circle" tone="success" />
        <x-stat-card label="Student absent" value="{{ $stats['student_absent'] }}" icon="x-circle" tone="warning"
                     hint="Consumed a session" />
        <x-stat-card label="Remaining" value="{{ $stats['remaining'] }}" icon="clock"
                     :tone="$stats['remaining'] === 0 ? 'danger' : 'brand'" />
        <x-stat-card label="Deducted" value="{{ $stats['deducted'] }}" icon="alert"
                     hint="Written off at enrolment" />
    </div>

    {{-- The legacy schema kept `sessions_attended` as a hand-maintained counter
         updated outside a transaction, so it could drift from the attendance
         rows. Surfacing the mismatch beats hiding it. --}}
    @if ($stats['counter_attended'] !== $stats['present'])
        <div class="mt-4 flex items-start gap-3 rounded-xl border border-warning-500/25 bg-warning-500/10 px-4 py-3 text-sm text-warning-400">
            <x-icon name="alert" class="mt-px h-5 w-5 shrink-0" />
            <p>
                The stored attendance counter reads
                <strong class="numeric">{{ $stats['counter_attended'] }}</strong>
                but <strong class="numeric">{{ $stats['present'] }}</strong>
                present {{ Str::plural('session', $stats['present']) }}
                {{ $stats['present'] === 1 ? 'is' : 'are' }} recorded.
                This drift was inherited from the legacy data; attendance rows are the source of truth.
            </p>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Session history" :subtitle="$sessions->total().' recorded'" flush>
                @if ($sessions->isEmpty())
                    <x-empty-state icon="calendar" title="No sessions yet"
                                   message="Attendance you record for this student will appear here." />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Scheduled</th>
                                    <th>Status</th>
                                    <th>Note</th>
                                    <th>Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sessions as $session)
                                    <tr>
                                        <td class="numeric whitespace-nowrap">
                                            {{ $session->scheduled_date->format('D, M j, Y') }}
                                            @if ($session->isEarly())
                                                <span class="badge-brand mt-0.5 block w-fit">
                                                    Taught {{ $session->held_date->format('M j') }}
                                                </span>
                                            @endif
                                        </td>

                                        <td>
                                            @if ($session->status)
                                                <span class="{{ $session->status->badgeClass() }}">
                                                    {{ $session->status->label() }}
                                                    @if ($session->absent_by)
                                                        ({{ $session->absent_by->label() }})
                                                    @endif
                                                </span>
                                            @else
                                                <span class="badge-neutral">Not marked</span>
                                            @endif
                                        </td>

                                        <td class="max-w-xs truncate text-gray-500">
                                            {{ $session->postpone_reason ?: '—' }}
                                        </td>

                                        <td>
                                            @if ($session->report)
                                                <span class="badge-success">
                                                    <x-icon name="check" class="h-3 w-3" />
                                                    Filed
                                                </span>
                                            @elseif ($session->isPayable())
                                                <a href="{{ route('instructor.reports.create', [
                                                        'student_id' => $student->id,
                                                        'date' => $session->paid_date->toDateString(),
                                                    ]) }}"
                                                   class="btn-secondary btn-sm">File</a>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($sessions->hasPages())
                        <div class="border-t border-gray-700 px-4 py-3">
                            {{ $sessions->links() }}
                        </div>
                    @endif
                @endif
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card title="Enrolment">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'Status' => $profile->enrollment_status->label(),
                        'Regular' => $profile->is_regular ? 'Yes' : 'No',
                        'Started' => $profile->start_date?->format('M j, Y') ?? '—',
                        'Ends' => $profile->end_date?->format('M j, Y') ?? '—',
                        'KakaoTalk' => $profile->kakaotalk_id ?: '—',
                        'Phone' => $student->phone ?: '—',
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd class="text-right font-medium text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Weekly schedule">
                @if ($student->schedules->isEmpty())
                    <p class="text-sm text-gray-500">No timetable set.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($student->schedules as $slot)
                            <li class="flex items-center justify-between gap-3">
                                <span class="text-gray-300">{{ $slot->dayName() }}</span>
                                <span class="numeric font-medium text-white">
                                    {{ $slot->formattedTime() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Other outcomes">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Teacher absent</dt>
                        <dd class="numeric font-medium text-danger-400">
                            {{ $stats['teacher_absent'] }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Postponed</dt>
                        <dd class="numeric font-medium text-warning-400">
                            {{ $stats['postponed'] }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-gray-400">
                    Neither consumes a prepaid session.
                </p>
            </x-card>
        </div>
    </div>
@endsection
