@extends('layouts.app')

@section('title', __('Add paid class'))
@section('heading', __('Add paid class'))
@section('subheading', $instructor->name.' · '.$window->label())

@section('actions')
    <a href="{{ route('admin.payouts.index', ['week' => $window->startDate()]) }}" class="btn-secondary btn-sm">
        <x-icon name="chevron-left" class="h-4 w-4" />
        {{ __('Payroll') }}
    </a>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.payouts.adjustments.store', $instructor) }}">
                @csrf

                <x-card :title="__('Credit one paid class')"
                        :subtitle="__('Added to the payslip only. The student\'s sessions are not changed.')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="student_id" class="form-label">{{ __('Student') }}</label>
                            <select id="student_id" name="student_id" required class="form-select">
                                <option value="">{{ __('Select a student') }}</option>
                                @foreach ($students as $profile)
                                    <option value="{{ $profile->user_id }}" @selected((int) old('student_id') === $profile->user_id)>
                                        {{ $profile->user->name }}
                                        · {{ $profile->teaching_method?->label() ?? __('Unspecified') }}
                                        {{ $profile->learning_time }}m
                                    </option>
                                @endforeach
                            </select>
                            @error('student_id') <p class="form-error">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-xs text-gray-500">
                                {{ __('Priced from the student\'s teaching method and duration, like a normal class.') }}
                            </p>
                        </div>

                        <div>
                            <label for="paid_date" class="form-label">{{ __('Paid date') }}</label>
                            <input id="paid_date" name="paid_date" type="date" required
                                   value="{{ old('paid_date', $window->isCurrent() ? now()->toDateString() : $window->endDate()) }}"
                                   min="{{ $window->startDate() }}" max="{{ $window->endDate() }}"
                                   class="form-input">
                            @error('paid_date') <p class="form-error">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-xs text-gray-500">
                                {{ __('Decides which payout week the class lands in.') }}
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="note" class="form-label">{{ __('Reason') }}</label>
                            <textarea id="note" name="note" rows="3" required maxlength="500"
                                      class="form-textarea">{{ old('note') }}</textarea>
                            @error('note') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="btn-primary">{{ __('Add paid class') }}</button>
                    </div>
                </x-card>
            </form>
        </div>

        <x-card :title="__('Manual classes this week')" flush>
            @if ($adjustments->isEmpty())
                <p class="px-4 py-6 text-center text-sm text-gray-500">{{ __('None this week.') }}</p>
            @else
                <ul class="divide-y divide-gray-800">
                    @foreach ($adjustments as $adjustment)
                        <li class="flex items-start justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-white">{{ $adjustment->student_name }}</p>
                                <p class="numeric text-xs text-gray-400">
                                    {{ $adjustment->paid_date->translatedFormat('D, M j') }} · @money2($adjustment->amount)
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $adjustment->note }}</p>
                            </div>

                            <form method="POST" action="{{ route('admin.payouts.adjustments.destroy', $adjustment) }}"
                                  onsubmit="return confirm(@js(__('Remove this manual class?')))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost btn-sm text-danger-400">{{ __('Remove') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
@endsection
