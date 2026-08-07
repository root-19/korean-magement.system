@extends('layouts.app')

@section('title', 'Earnings')
@section('heading', 'Earnings')
@section('subheading', $window->label().($window->isCurrent() ? ' · current week' : ''))

@section('actions')
    {{-- A payslip gets printed and handed over. Printing the page itself rather
         than a separate print view keeps one set of figures. --}}
    <button type="button" onclick="window.print()" class="btn-secondary btn-sm">
        <x-icon name="printer" class="h-4 w-4" />
        Print
    </button>

    <form method="GET" class="flex items-center gap-1.5">
        <a href="{{ route('instructor.earnings.index', ['week' => $window->previous()->startDate()]) }}"
           class="btn-ghost !p-2" aria-label="Previous week">
            <x-icon name="chevron-left" class="h-4 w-4" />
        </a>

        <select name="week" onchange="this.form.submit()" class="form-select !w-auto !py-1.5 text-xs" aria-label="Payout week">
            @foreach ($windows as $option)
                <option value="{{ $option->startDate() }}" @selected($option->key() === $window->key())>
                    {{ $option->label() }}{{ $option->isCurrent() ? ' (current)' : '' }}
                </option>
            @endforeach
        </select>

        @if (! $window->isCurrent())
            <a href="{{ route('instructor.earnings.index', ['week' => $window->next()->startDate()]) }}"
               class="btn-ghost !p-2" aria-label="Next week">
                <x-icon name="chevron-right" class="h-4 w-4" />
            </a>
        @endif
    </form>
@endsection

@section('content')
    {{-- Print only: the app header carrying the instructor and the week is
         hidden on paper, and a payslip with neither is useless. --}}
    <div class="print-only mb-6 border-b border-gray-400 pb-4">
        <div class="flex items-start justify-between gap-6">
            <div>
                <h1 class="text-xl font-bold">Payslip · {{ config('app.name') }}</h1>
                <p class="mt-1 text-sm">{{ $instructor->name }}</p>
                <p class="text-sm">Payout week {{ $window->label() }} (Saturday to Friday, KST)</p>
            </div>

            <div class="text-right">
                <p class="text-xs uppercase tracking-wide">Net payable</p>
                <p class="numeric text-xl font-bold">{{ money($summary->net()) }}</p>
                <p class="mt-1 text-xs">Printed {{ now()->format('M j, Y g:i A') }}</p>
            </div>
        </div>

        @if ($payout)
            <p class="mt-3 text-xs">
                Finalised at {{ money($payout->net_earnings) }} · {{ $payout->status->label() }}
                @if ($payout->paid_at) · paid {{ $payout->paid_at->format('M j, Y') }} @endif
            </p>
        @else
            <p class="mt-3 text-xs">
                Not yet finalised — these figures are live and can still change.
            </p>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Gross" :value="money($summary->gross())" icon="wallet"
                     hint="{{ $summary->sessionsPaid() }} {{ Str::plural('session', $summary->sessionsPaid()) }}" />

        <x-stat-card label="Deductions" :value="'−'.money($summary->deductions())" icon="alert"
                     :tone="$summary->sessionsDeducted() > 0 ? 'danger' : 'default'"
                     hint="{{ $summary->sessionsDeducted() }} teacher-absent" />

        <x-stat-card label="Net payable" :value="money($summary->net())" icon="check-circle" tone="brand"
                     hint="Saturday to Friday" />

        <x-stat-card label="Students taught" value="{{ $summary->studentCount() }}" icon="users"
                     hint="{{ $summary->audioSessions() }} audio · {{ $summary->videoSessions() }} video" />
    </div>

    @if ($payout)
        <div class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 text-sm
                    {{ $payout->status === App\Enums\PayoutStatus::Paid
                        ? 'border-success-500/25 bg-success-500/10 text-success-400 bg-success-500/10 text-success-400'
                        : 'border-warning-500/25 bg-warning-500/10 text-warning-400 bg-warning-500/10 text-warning-400' }}">
            <x-icon :name="$payout->status === App\Enums\PayoutStatus::Paid ? 'check-circle' : 'clock'" class="h-5 w-5 shrink-0" />
            <p class="flex-1">
                This week has been finalised at <strong class="numeric">@money($payout->net_earnings)</strong>
                and is marked <strong>{{ $payout->status->label() }}</strong>.
                @if ($payout->paid_at)
                    Paid {{ $payout->paid_at->format('M j, Y') }}.
                @endif
            </p>
        </div>
    @endif

    @if ($summary->historicalLines()->isNotEmpty())
        <div class="mt-4 flex items-start gap-3 rounded-card border border-brand-500/30 bg-brand-500/10 px-4 py-3 text-sm text-brand-400">
            <x-icon name="info" class="mt-px h-5 w-5 shrink-0" />
            <p>
                {{ $summary->historicalLines()->count() }} of these
                {{ Str::plural('session', $summary->historicalLines()->count()) }} predate the
                report requirement ({{ config('academy.feedback_required_from') }}) and are paid without one.
            </p>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        {{-- Line items --}}
        <div class="lg:col-span-2">
            <x-card title="Sessions" :subtitle="$summary->lines->count().' line items'" flush>
                @if ($summary->isEmpty())
                    <x-empty-state icon="wallet"
                                   title="No earnings this week"
                                   message="A session appears here once it is marked present or student-absent AND its report is filed." />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Type</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary->lines as $line)
                                    <tr>
                                        <td class="numeric whitespace-nowrap">
                                            {{ $line->paidDate->format('D, M j') }}
                                            @if ($line->isEarly())
                                                <span class="badge-brand mt-0.5 block w-fit">
                                                    Early · slot {{ $line->scheduledDate->format('M j') }}
                                                </span>
                                            @endif
                                        </td>

                                        <td class="font-medium text-white">
                                            {{ $line->studentName }}
                                        </td>

                                        <td class="whitespace-nowrap text-gray-500">
                                            {{ $line->teachingMethod?->label() ?? 'Unspecified' }}
                                        </td>

                                        <td class="numeric whitespace-nowrap text-gray-500">
                                            {{ $line->learningTime }} min
                                        </td>

                                        <td>
                                            <span class="{{ $line->statusBadgeClass() }}">{{ $line->statusLabel() }}</span>
                                        </td>

                                        <td @class([
                                            'numeric text-right font-medium whitespace-nowrap',
                                            'text-danger-400' => $line->isDeduction,
                                            'text-white' => ! $line->isDeduction,
                                        ])>
                                            {{ $line->isDeduction ? '−' : '' }}@money2($line->amount)
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-gray-700">
                                <tr>
                                    <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-gray-500">
                                        Net payable
                                    </td>
                                    <td class="numeric px-4 py-3 text-right text-base font-semibold text-brand-400">
                                        @money($summary->net())
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-4">
            {{-- Rate breakdown --}}
            <x-card title="By teaching method">
                @php
                    $gross = $summary->grossByMethod();
                    $counts = $summary->sessionsByMethod();
                    $total = max(0.01, array_sum($gross));
                @endphp

                <ul class="space-y-3">
                    @foreach (App\Enums\TeachingMethod::cases() as $method)
                        <li>
                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="text-gray-300">{{ $method->label() }}</span>
                                <span class="numeric font-medium text-white">
                                    @money($gross[$method->value])
                                </span>
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-800">
                                    <span class="block h-full rounded-full bg-brand-500"
                                          style="width: {{ $gross[$method->value] / $total * 100 }}%"></span>
                                </span>
                                <span class="numeric w-20 shrink-0 text-right text-xs text-gray-400">
                                    {{ $counts[$method->value] }} × {{ money($method->hourlyRate()) }}/h
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            {{-- Per-student roll-up --}}
            <x-card title="By student" flush>
                @if ($summary->byStudent()->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-500">No students this week.</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th class="text-center">P</th>
                                    <th class="text-center">A</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary->byStudent() as $row)
                                    <tr>
                                        <td class="max-w-[9rem] truncate font-medium text-white">
                                            {{ $row['student_name'] }}
                                        </td>
                                        <td class="numeric text-center text-success-400">
                                            {{ $row['present'] ?: '—' }}
                                        </td>
                                        <td class="numeric text-center text-danger-400">
                                            {{ $row['absent'] ?: '—' }}
                                        </td>
                                        <td class="numeric text-right font-medium text-white">
                                            @money($row['amount'])
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-400">
        The payout week runs Saturday to Friday. A class taught ahead of schedule is paid in the week it was
        taught, not the week of the slot it covers.
    </p>
@endsection
