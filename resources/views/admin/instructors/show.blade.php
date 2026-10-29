@extends('layouts.app')

@section('title', $instructor->name)
@section('heading', $instructor->name)
@section('subheading', __('Instructor · :week', ['week' => $window->label()]))

@section('actions')
    <a href="{{ route('admin.instructors.index') }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        {{ __('All instructors') }}
    </a>
@endsection

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card :label="__('Net this week')" :value="money($summary->net())" icon="wallet" tone="brand" />
        <x-stat-card :label="__('Sessions paid')" value="{{ $summary->sessionsPaid() }}" icon="check-circle" tone="success" />
        <x-stat-card :label="__('Deductions')" :value="'−'.money($summary->deductions())" icon="alert"
                     :tone="$summary->sessionsDeducted() > 0 ? 'danger' : 'default'"
                     :hint="__(':count teacher-absent', ['count' => $summary->sessionsDeducted()])" />
        <x-stat-card :label="__('Students')" value="{{ $students->count() }}" icon="users" :hint="__('Approved and active')" />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            {{-- Payslip line items for the selected week --}}
            <x-card :subtitle="$window->label()" flush>
                <x-slot:title>{{ __('Earnings') }}</x-slot:title>

                <x-slot:actions>
                    <form method="GET" class="flex items-center gap-1.5">
                        <select name="week" onchange="this.form.submit()"
                                class="form-select !w-auto !py-1.5 text-xs" aria-label="{{ __('Payout week') }}">
                            @foreach ($windows as $option)
                                <option value="{{ $option->startDate() }}" @selected($option->key() === $window->key())>
                                    {{ $option->label() }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                </x-slot:actions>

                @if ($summary->isEmpty())
                    <x-empty-state icon="wallet" :title="__('Nothing earned this week')"
                                   :message="__('A session pays once it is marked and its report is filed.')" />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Student') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="text-right">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary->lines as $line)
                                    <tr>
                                        <td class="numeric whitespace-nowrap">
                                            {{ $line->paidDate->translatedFormat('D, M j') }}
                                            @if ($line->isEarly())
                                                <span class="badge-brand mt-0.5 block w-fit">{{ __('Early') }}</span>
                                            @endif
                                        </td>
                                        <td class="font-medium text-white">{{ $line->studentName }}</td>
                                        <td class="whitespace-nowrap text-gray-400">
                                            {{ $line->teachingMethod?->label() ?? __('Unspecified') }}
                                            <span class="numeric text-xs text-gray-500">{{ $line->learningTime }}m</span>
                                        </td>
                                        <td>
                                            <span class="{{ $line->statusBadgeClass() }}">{{ $line->statusLabel() }}</span>
                                            @if ($line->isAdjustment())
                                                <span class="mt-0.5 block text-xs text-gray-500">{{ $line->adjustmentNote }}</span>
                                            @endif
                                        </td>
                                        <td @class([
                                            'numeric whitespace-nowrap text-right font-medium',
                                            'text-danger-400' => $line->isDeduction,
                                            'text-gray-200' => ! $line->isDeduction,
                                        ])>
                                            {{ $line->isDeduction ? '−' : '' }}@money2($line->amount)
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-gray-700">
                                <tr>
                                    <td colspan="4" class="px-4 py-3 text-right text-sm font-medium text-gray-400">{{ __('Net') }}</td>
                                    <td class="numeric px-4 py-3 text-right text-base font-bold text-brand-400">
                                        @money($summary->net())
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-card>

            {{-- Weekly availability --}}
            <x-card :title="__('Weekly schedule')">
                <x-schedule-grid :grid="$grid" :days="$days" :label="__(':name\'s weekly availability', ['name' => $instructor->name])" />
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card :title="__('Profile')">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        __('Email') => $instructor->email ?: '—',
                        __('Phone') => $instructor->phone ?: '—',
                        __('Bank') => $instructor->instructorProfile?->bank_name ?: '—',
                        __('Account') => $instructor->instructorProfile?->bank_account ?: '—',
                        __('Status') => $instructor->is_active ? __('Active') : __('Inactive'),
                        __('Last login') => $instructor->last_login_at?->translatedFormat('M j, Y') ?? __('Never'),
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-400">{{ $label }}</dt>
                            <dd class="truncate text-right font-medium text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($instructor->instructorProfile?->bio)
                    <p class="mt-3 border-t border-gray-700 pt-3 text-sm text-gray-300">
                        {{ $instructor->instructorProfile->bio }}
                    </p>
                @endif
            </x-card>

            <x-card :title="__('Recent payslips')" flush>
                @if ($payouts->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-400">{{ __('No payslip finalised yet.') }}</p>
                @else
                    <ul class="divide-y divide-gray-700/50">
                        @foreach ($payouts as $payout)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <span class="numeric text-xs text-gray-400">{{ $payout->weekLabel() }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="numeric text-sm font-semibold text-white">
                                        @money($payout->net_earnings)
                                    </span>
                                    <span class="{{ $payout->status->badgeClass() }}">{{ $payout->status->label() }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card :title="__('Students')" flush>
                @if ($students->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-400">{{ __('No students assigned.') }}</p>
                @else
                    <ul class="divide-y divide-gray-700/50">
                        @foreach ($students as $profile)
                            <li>
                                <a href="{{ route('admin.students.show', $profile->user_id) }}"
                                   class="focus-ring flex items-center gap-2.5 px-4 py-2.5 transition hover:bg-gray-700/40">
                                    <x-avatar :user="$profile->user" class="h-8 w-8" />
                                    <span class="min-w-0 flex-1 truncate text-sm text-gray-200">
                                        {{ $profile->user?->name }}
                                    </span>
                                    <span class="numeric text-xs text-gray-500">
                                        {{ __(':count left', ['count' => $profile->sessions_remaining]) }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
