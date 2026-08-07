@extends('layouts.app')

@section('title', 'Payroll')
@section('heading', 'Payroll')
@section('subheading', $window->label().($window->isCurrent() ? ' · current week' : ''))

@section('actions')
    <form method="GET" class="flex items-center gap-1.5">
        <a href="{{ route('admin.payouts.index', ['week' => $window->previous()->startDate()]) }}"
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
            <a href="{{ route('admin.payouts.index', ['week' => $window->next()->startDate()]) }}"
               class="btn-ghost !p-2" aria-label="Next week">
                <x-icon name="chevron-right" class="h-4 w-4" />
            </a>
        @endif
    </form>
@endsection

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Gross" :value="money($totals['gross'])" icon="wallet"
                     hint="{{ $totals['sessions'] }} paid {{ Str::plural('session', $totals['sessions']) }}" />

        <x-stat-card label="Deductions" :value="'−'.money($totals['deductions'])" icon="alert"
                     :tone="$totals['deductions'] > 0 ? 'danger' : 'default'" />

        <x-stat-card label="Net payroll" :value="money($totals['net'])" icon="check-circle" tone="brand"
                     hint="Academy total" />

        <x-stat-card label="Instructors" value="{{ $rows->count() }}" icon="users"
                     hint="With sessions this week" />
    </div>

    {{-- Freezing the week is what stops a later attendance edit from silently
         restating what was already paid. --}}
    <div class="mt-4 flex flex-wrap items-center gap-3 rounded-card border border-gray-700 bg-gray-800 px-4 py-3">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-white">Finalise this week</p>
            <p class="mt-0.5 text-xs text-gray-400">
                Writes each instructor's figures to a payslip. Until then earnings
                are recomputed live from attendance on every view.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.payouts.finalise-week') }}"
              onsubmit="return confirm('Finalise payslips for every instructor in {{ $window->label() }}?')">
            @csrf
            <input type="hidden" name="week" value="{{ $window->startDate() }}">
            <button type="submit" class="btn-primary" @disabled($rows->isEmpty())>
                Finalise all
            </button>
        </form>
    </div>

    <x-card class="mt-6" flush>
        <x-slot:title>Instructor payslips</x-slot:title>
        <x-slot:subtitle>{{ $window->label() }}</x-slot:subtitle>

        @if ($rows->isEmpty())
            <x-empty-state icon="wallet"
                           title="No sessions this week"
                           message="Nothing to pay: no instructor has a settled session in this window." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Instructor</th>
                            <th class="text-center">Sessions</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right">Deductions</th>
                            <th class="text-right">Net</th>
                            <th>Payslip</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $instructor = $row['instructor'];
                                $summary = $row['summary'];
                                $payout = $row['payout'];
                                // A frozen figure that no longer matches the live one
                                // means attendance changed after finalisation.
                                $drifted = $payout && abs((float) $payout->net_earnings - $summary->net()) > 0.01;
                            @endphp

                            <tr>
                                <td>
                                    <a href="{{ route('admin.instructors.show', $instructor) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$instructor" class="h-8 w-8" />
                                        <span class="truncate font-medium text-white">{{ $instructor->name }}</span>
                                    </a>
                                </td>

                                <td class="numeric text-center text-gray-300">{{ $summary->sessionsPaid() }}</td>

                                <td class="numeric text-right text-gray-200">@money($summary->gross())</td>

                                <td class="numeric text-right {{ $summary->deductions() > 0 ? 'text-danger-400' : 'text-gray-500' }}">
                                    {{ $summary->deductions() > 0 ? '−' : '' }}@money($summary->deductions())
                                </td>

                                <td class="numeric text-right font-semibold text-brand-400">@money($summary->net())</td>

                                <td>
                                    @if ($payout)
                                        <span class="{{ $payout->status->badgeClass() }}">
                                            {{ $payout->status->label() }}
                                        </span>
                                        @if ($drifted)
                                            <span class="badge-warning mt-1 block w-fit"
                                                  title="Frozen at {{ money($payout->net_earnings) }}, live figure is {{ money($summary->net()) }}">
                                                Drifted
                                            </span>
                                        @endif
                                    @else
                                        <span class="badge-neutral">Not finalised</span>
                                    @endif
                                </td>

                                <td class="text-right">
                                    <div class="flex justify-end gap-1.5">
                                        @if (! $payout)
                                            <form method="POST" action="{{ route('admin.payouts.finalise', $instructor) }}">
                                                @csrf
                                                <input type="hidden" name="week" value="{{ $window->startDate() }}">
                                                <button type="submit" class="btn-secondary btn-sm">Finalise</button>
                                            </form>
                                        @elseif ($payout->status === App\Enums\PayoutStatus::Pending)
                                            <form method="POST" action="{{ route('admin.payouts.paid', $payout) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-primary btn-sm">Mark paid</button>
                                            </form>

                                            @if ($drifted)
                                                <form method="POST" action="{{ route('admin.payouts.finalise', $instructor) }}">
                                                    @csrf
                                                    <input type="hidden" name="week" value="{{ $window->startDate() }}">
                                                    <button type="submit" class="btn-ghost btn-sm">Restate</button>
                                                </form>
                                            @endif
                                        @else
                                            <span class="numeric text-xs text-gray-500">
                                                {{ $payout->paid_at?->format('M j') }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot class="border-t-2 border-gray-700">
                        <tr>
                            <td class="px-4 py-3 text-right text-sm font-medium text-gray-400" colspan="4">
                                Net payroll
                            </td>
                            <td class="numeric px-4 py-3 text-right text-base font-bold text-brand-400">
                                @money($totals['net'])
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>

    <p class="mt-4 text-xs text-gray-500">
        The payout week runs Saturday to Friday. A session pays only once its report is
        filed; a class taught early is paid in the week it was taught.
    </p>
@endsection
