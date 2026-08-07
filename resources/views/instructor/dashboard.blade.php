@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', 'Payout week '.$window->label())

@section('content')
    {{-- ─────────────────────────────────────────── Welcome header ───── --}}
    <div class="card animate-fadeIn mb-6 p-6">
        <div class="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
            <div>
                <h1 class="heading text-2xl">Welcome back, {{ $instructor->name }}</h1>
                <p class="mt-1 text-sm text-gray-400">Here's what's happening with your classes today</p>
            </div>

            <span class="numeric inline-flex items-center gap-2 rounded-full bg-gray-700 px-4 py-2 text-sm text-gray-300">
                <x-icon name="clock" class="h-4 w-4" />
                {{ now()->format('M d, Y g:i A') }} (KST)
            </span>
        </div>
    </div>

    {{-- Schedule prompt. Legacy nagged about this too, but the page that fixed
         it was buried in the menu — this links straight there. --}}
    @unless ($hasSchedule)
        <div class="card-accent animate-fadeIn mb-6 p-5">
            <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                <span class="rounded-full bg-white/20 p-3">
                    <x-icon name="calendar" class="h-6 w-6 text-white" />
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-bold text-white">Set up your teaching schedule</h2>
                    <p class="mt-0.5 text-sm text-white/85">
                        You haven't published your teaching hours yet. Add your available
                        time slots so students can see when to book you.
                    </p>
                </div>

                <a href="{{ route('instructor.schedule.index') }}"
                   class="focus-ring shrink-0 rounded-lg bg-white px-5 py-2.5 text-sm font-bold text-accent-600 shadow-lg transition hover:bg-brand-50">
                    Add Schedule
                </a>
            </div>
        </div>
    @endunless

    {{-- ──────────────────────────────────────────────── Stat tiles ───── --}}
    @php
        $trend = $lastWeekNet > 0 ? (int) round(($summary->net() - $lastWeekNet) / $lastWeekNet * 100) : null;
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Total Students" value="{{ $studentCount }}" icon="users" tone="brand"
                     hint="Assigned to you" />

        <x-stat-card label="Net this week" :value="money($summary->net())" icon="wallet"
                     :trend="$trend"
                     :hint="$trend !== null ? '% vs last week' : 'No earnings last week'" />

        <x-stat-card label="Sessions paid" value="{{ $summary->sessionsPaid() }}" icon="check-circle" tone="success"
                     hint="{{ $summary->audioSessions() }} audio · {{ $summary->videoSessions() }} video" />

        <x-stat-card label="Deductions" :value="money($summary->deductions())" icon="alert"
                     :tone="$summary->sessionsDeducted() > 0 ? 'danger' : 'default'"
                     hint="{{ $summary->sessionsDeducted() }} teacher-absent" />
    </div>

    {{-- Unfiled reports are unpaid work, so this leads. --}}
    @if ($unreported->isNotEmpty())
        <div class="mt-6 rounded-card border border-warning-500/30 bg-warning-500/10 p-5">
            <div class="flex flex-wrap items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-warning-500/20 text-warning-400">
                    <x-icon name="clipboard" class="h-5 w-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-bold text-warning-400">
                        {{ $unreported->count() }} {{ Str::plural('session', $unreported->count()) }} awaiting a report
                    </h2>
                    <p class="mt-0.5 text-sm text-warning-400/80">
                        These are taught but unpaid — a session pays once its report is filed.
                    </p>

                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($unreported->take(10) as $session)
                            <li>
                                <a href="{{ route('instructor.reports.create', [
                                        'student_id' => $session->student_id,
                                        'date' => $session->paid_date->toDateString(),
                                    ]) }}"
                                   class="focus-ring inline-flex items-center gap-1.5 rounded-lg border border-warning-500/30 bg-gray-800 px-2.5 py-1.5 text-xs font-medium text-warning-400 transition hover:border-warning-500/60 hover:bg-gray-700">
                                    {{ $session->student->name }}
                                    <span class="numeric text-warning-400/60">{{ $session->paid_date->format('M j') }}</span>
                                </a>
                            </li>
                        @endforeach

                        @if ($unreported->count() > 10)
                            <li class="self-center text-xs text-warning-400/70">
                                +{{ $unreported->count() - 10 }} more
                            </li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- ────────────────────────────── Today / selected date + calendar ───── --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            {{-- Today's roster --}}
            <x-card title="Today's Schedule" :subtitle="now()->format('l, F j, Y')" flush>
                <x-slot:actions>
                    <a href="{{ route('instructor.classes.index') }}" class="btn-secondary btn-sm">Class list</a>
                </x-slot:actions>

                @include('instructor._roster', ['roster' => $today, 'date' => now()])
            </x-card>

            {{-- Tomorrow, so the next day's slots are visible without opening the
                 calendar. Read-only — attendance belongs to the day it happens.
                 Skipped when tomorrow is already the selected date below. --}}
            @unless ($selectedDate->isSameDay($tomorrowDate))
                <x-card title="Tomorrow's Schedule" :subtitle="$tomorrowDate->format('l, F j, Y')" flush>
                    <x-slot:actions>
                        <span class="badge-neutral numeric">
                            {{ $tomorrow->count() }} {{ Str::plural('class', $tomorrow->count()) }}
                        </span>
                        <a href="{{ route('instructor.classes.index', ['date' => $tomorrowDate->toDateString()]) }}"
                           class="btn-secondary btn-sm">Open day</a>
                    </x-slot:actions>

                    @include('instructor._roster', [
                        'roster' => $tomorrow,
                        'date' => $tomorrowDate,
                        'markable' => false,
                    ])
                </x-card>
            @endunless

            {{-- The selected date, when it is not today. Past dates are markable;
                 future ones are a preview of what is booked. --}}
            @if ($selectedRoster !== null)
                @php
                    $isUpcoming = $selectedDate->isFuture();

                    $presentMinutes = $selectedRoster
                        ->filter(fn ($r) => $r['session']?->status === App\Enums\SessionStatus::Present)
                        ->sum(fn ($r) => (int) ($r['profile']?->learning_time ?? 0));
                @endphp

                <x-card flush>
                    <x-slot:title>{{ ($isUpcoming ? 'Upcoming classes for ' : 'Students for ').$selectedDate->format('F j, Y') }}</x-slot:title>

                    <x-slot:actions>
                        @if ($isUpcoming)
                            <span class="badge-neutral numeric">
                                {{ $selectedRoster->count() }} scheduled
                            </span>
                        @else
                            {{-- Legacy showed this too: total taught minutes for the day. --}}
                            <span class="badge-brand numeric">Total present time: {{ $presentMinutes }} min</span>
                        @endif

                        <a href="{{ route('instructor.dashboard') }}" class="btn-ghost btn-sm">Back to today</a>
                    </x-slot:actions>

                    @include('instructor._roster', [
                        'roster' => $selectedRoster,
                        'date' => $selectedDate,
                        'markable' => ! $isUpcoming,
                    ])
                </x-card>
            @endif
        </div>

        <div class="space-y-4">
            {{-- Month calendar. Plain links, so it works without JavaScript and
                 every date is a shareable URL. --}}
            <x-card>
                <x-slot:title>{{ $month->format('F Y') }}</x-slot:title>

                <x-slot:actions>
                    <a href="{{ route('instructor.dashboard', ['month' => $month->subMonth()->month, 'year' => $month->subMonth()->year, 'date' => $selectedDate->toDateString()]) }}"
                       class="btn-ghost btn-sm !px-1.5" aria-label="Previous month">
                        <x-icon name="chevron-left" class="h-4 w-4" />
                    </a>
                    <a href="{{ route('instructor.dashboard', ['month' => $month->addMonth()->month, 'year' => $month->addMonth()->year, 'date' => $selectedDate->toDateString()]) }}"
                       class="btn-ghost btn-sm !px-1.5" aria-label="Next month">
                        <x-icon name="chevron-right" class="h-4 w-4" />
                    </a>
                </x-slot:actions>

                <div class="grid grid-cols-7 gap-1 text-center">
                    @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $initial)
                        <span class="pb-1 text-[11px] font-semibold uppercase text-gray-500">{{ $initial }}</span>
                    @endforeach

                    @foreach ($calendar as $cell)
                        @if ($cell['date'] === null)
                            <span></span>
                        @else
                            @php
                                $day = $cell['date'];
                                $isSelected = $day->isSameDay($selectedDate);
                                $isToday = $day->isSameDay(now());

                                // Recorded classes if there are any, otherwise the
                                // timetabled ones still to come.
                                $count = $cell['total'] ?: $cell['upcoming'];
                                $countLabel = $count
                                    ? ' — '.$count.' '.Str::plural('class', $count).($cell['total'] ? '' : ' scheduled')
                                    : '';
                            @endphp

                            <a href="{{ route('instructor.dashboard', ['date' => $day->toDateString(), 'month' => $month->month, 'year' => $month->year]) }}"
                               @class([
                                   'focus-ring relative flex h-9 items-center justify-center rounded-full text-sm font-semibold transition',
                                   'bg-brand-400 text-gray-900' => $isSelected,
                                   'ring-2 ring-brand-400 text-gray-200' => $isToday && ! $isSelected,
                                   'bg-gray-700 text-gray-200 hover:bg-accent-400 hover:text-white' => ! $isSelected && ! $isToday,
                                   'hover:bg-accent-400 hover:text-white' => $isToday && ! $isSelected,
                               ])
                               title="{{ $day->format('l, F j') }}{{ $countLabel }}"
                               @if ($isSelected) aria-current="date" @endif>
                                {{ $day->day }}

                                @if ($cell['total'] > 0)
                                    {{-- An unmarked class is work not yet recorded, so it
                                         gets the warning colour. --}}
                                    <span @class([
                                        'absolute bottom-1 h-1 w-1 rounded-full',
                                        'bg-warning-400' => $cell['unmarked'] > 0,
                                        'bg-success-400' => $cell['unmarked'] === 0,
                                        '!bg-gray-900' => $isSelected,
                                    ])></span>
                                @elseif ($cell['upcoming'] > 0)
                                    {{-- Nothing to record yet: a class the timetable says
                                         is coming. --}}
                                    <span @class([
                                        'absolute bottom-1 h-1 w-1 rounded-full bg-accent-400',
                                        '!bg-gray-900' => $isSelected,
                                    ])></span>
                                @endif
                            </a>
                        @endif
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-gray-700 pt-3 text-[11px] text-gray-500">
                    <span class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-success-400"></span> All marked
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-warning-400"></span> Needs marking
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-accent-400"></span> Upcoming
                    </span>
                </div>
            </x-card>

            {{-- Payout week at a glance --}}
            <x-card title="This payout week" :subtitle="$window->label()">
                @php
                    $max = max(1, collect($weekBreakdown)->max(fn ($d) => $d['present'] + $d['absent'] + $d['postponed']));
                @endphp

                <ul class="space-y-2.5">
                    @foreach ($weekBreakdown as $day)
                        @php $total = $day['present'] + $day['absent'] + $day['postponed']; @endphp
                        <li class="flex items-center gap-3">
                            <span @class([
                                'w-9 shrink-0 text-xs font-medium',
                                'text-brand-400' => $day['date']->isToday(),
                                'text-gray-400' => ! $day['date']->isToday(),
                            ])>{{ $day['label'] }}</span>

                            <span class="flex h-2 flex-1 gap-px overflow-hidden rounded-full bg-gray-700"
                                  role="img"
                                  aria-label="{{ $day['present'] }} present, {{ $day['absent'] }} absent, {{ $day['postponed'] }} postponed">
                                @if ($day['present'])
                                    <span class="bg-success-500" style="width: {{ $day['present'] / $max * 100 }}%"></span>
                                @endif
                                @if ($day['absent'])
                                    <span class="bg-danger-500" style="width: {{ $day['absent'] / $max * 100 }}%"></span>
                                @endif
                                @if ($day['postponed'])
                                    <span class="bg-warning-500" style="width: {{ $day['postponed'] / $max * 100 }}%"></span>
                                @endif
                            </span>

                            <span class="numeric w-4 shrink-0 text-right text-xs text-gray-400">{{ $total ?: '' }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="mt-4 space-y-1.5 border-t border-gray-700 pt-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-400">Gross</dt>
                        <dd class="numeric font-medium text-white">@money($summary->gross())</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-400">Deductions</dt>
                        <dd class="numeric font-medium text-danger-400">−@money($summary->deductions())</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-700 pt-1.5">
                        <dt class="font-medium text-white">Net</dt>
                        <dd class="numeric font-bold text-brand-400">@money($summary->net())</dd>
                    </div>
                </dl>

                <a href="{{ route('instructor.earnings.index') }}" class="btn-secondary mt-4 w-full">View payslip</a>
            </x-card>
        </div>
    </div>

    {{-- Shared by every roster panel above: today, tomorrow and the selected date. --}}
    @include('instructor._postpone-modal')
@endsection
