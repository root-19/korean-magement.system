@extends('layouts.app')

@section('title', 'Bookings')
@section('heading', 'Bookings')
@section('subheading', 'Trial requests across every instructor')

@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap items-center gap-1 rounded-lg border border-gray-700 bg-gray-800 p-1">
            <a href="{{ route('admin.bookings.index') }}"
               @class([
                   'focus-ring flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                   'bg-gray-700 text-brand-400' => $status === null,
                   'text-gray-400 hover:text-white' => $status !== null,
               ])>
                All
                <span class="numeric rounded-full bg-gray-900/60 px-1.5 py-0.5 text-[10px]">{{ $counts['all'] }}</span>
            </a>

            @foreach (App\Enums\BookingStatus::cases() as $case)
                <a href="{{ route('admin.bookings.index', ['status' => $case->value]) }}"
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

        <form method="GET" class="flex items-center gap-2">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status->value }}">
            @endif
            <select name="instructor" onchange="this.form.submit()" class="form-select !w-auto text-xs"
                    aria-label="Filter by instructor">
                <option value="">All instructors</option>
                @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" @selected((int) $selectedInstructor === $instructor->id)>
                        {{ $instructor->name }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    <x-card flush>
        @if ($bookings->isEmpty())
            <x-empty-state icon="book-open"
                           title="No booking requests"
                           message="Trial requests submitted from instructor profile pages arrive here." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Requested for</th>
                            <th>Prospect</th>
                            <th>Instructor</th>
                            <th>Type</th>
                            <th class="text-center">Sessions</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr>
                                <td class="numeric whitespace-nowrap">
                                    {{ $booking->session_date->format('D, M j, Y') }}
                                    <span class="block text-xs text-gray-500">
                                        {{ \Carbon\Carbon::parse($booking->session_time)->format('g:i A') }}
                                    </span>
                                </td>

                                <td>
                                    <span class="block truncate font-medium text-white">{{ $booking->student_name }}</span>
                                    <span class="block truncate text-xs text-gray-500">
                                        {{ $booking->kakaotalk_id ?: ($booking->email ?: '—') }}
                                    </span>
                                </td>

                                <td>
                                    <a href="{{ route('admin.instructors.show', $booking->instructor_id) }}"
                                       class="focus-ring flex items-center gap-2 rounded">
                                        <x-avatar :user="$booking->instructor" class="h-7 w-7" />
                                        <span class="truncate text-gray-200">{{ $booking->instructor?->name }}</span>
                                    </a>
                                </td>

                                <td class="whitespace-nowrap text-gray-400">
                                    {{ $booking->teaching_method?->label() ?? '—' }}
                                    <span class="numeric text-xs text-gray-500">
                                        {{ $booking->learning_time ? $booking->learning_time.'m' : '' }}
                                    </span>
                                </td>

                                <td class="numeric text-center text-gray-300">{{ $booking->sessions }}</td>

                                <td>
                                    <span class="{{ $booking->status->badgeClass() }}">{{ $booking->status->label() }}</span>
                                    @if ($booking->isConverted())
                                        <span class="badge-brand mt-1 block w-fit">Enrolled</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($bookings->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">{{ $bookings->links() }}</div>
            @endif
        @endif
    </x-card>

    <p class="mt-4 text-xs text-gray-500">
        Instructors confirm or cancel their own bookings from their Bookings page. This
        view is read-only.
    </p>
@endsection
