@extends('layouts.app')

@section('title', 'Bookings')
@section('heading', 'Bookings')
@section('subheading', 'Trial requests from your public profile')

@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-1 rounded-lg border border-gray-700 bg-gray-800 p-1">
        <a href="{{ route('instructor.bookings.index') }}"
           @class([
               'focus-ring flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition',
               'bg-gray-700 text-brand-400' => $status === null,
               'text-gray-400 hover:text-white' => $status !== null,
           ])>
            All
            <span class="numeric rounded-full bg-gray-900/60 px-1.5 py-0.5 text-[10px]">{{ $counts['all'] }}</span>
        </a>

        @foreach (App\Enums\BookingStatus::cases() as $case)
            <a href="{{ route('instructor.bookings.index', ['status' => $case->value]) }}"
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

    @if ($bookings->isEmpty())
        <x-card>
            <x-empty-state icon="book-open"
                           title="No booking requests"
                           message="Trial requests submitted from your public profile page arrive here. Publish your teaching schedule so students can see when to book.">
                <a href="{{ route('instructor.schedule.index') }}" class="btn-primary btn-sm">Set your schedule</a>
            </x-empty-state>
        </x-card>
    @else
        <div class="space-y-4">
            @foreach ($bookings as $booking)
                <x-card>
                    <div class="flex flex-wrap items-start gap-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-500/20 text-sm font-bold text-brand-400">
                            {{ mb_strtoupper(mb_substr($booking->student_name, 0, 2)) }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-semibold text-white">{{ $booking->student_name }}</h2>
                                <span class="{{ $booking->status->badgeClass() }}">{{ $booking->status->label() }}</span>
                                @if ($booking->isConverted())
                                    <span class="badge-brand">Enrolled</span>
                                @endif
                            </div>

                            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ([
                                    'Requested' => $booking->session_date->format('D, M j, Y'),
                                    'Time' => \Carbon\Carbon::parse($booking->session_time)->format('g:i A'),
                                    'Type' => $booking->teaching_method?->label() ?? '—',
                                    'Duration' => $booking->learning_time ? $booking->learning_time.' min' : '—',
                                    'Sessions' => $booking->sessions,
                                    'KakaoTalk' => $booking->kakaotalk_id ?: '—',
                                    'Email' => $booking->email ?: '—',
                                    'Submitted' => $booking->created_at?->diffForHumans(),
                                ] as $label => $value)
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                                        <dd class="numeric truncate font-medium text-white">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if ($booking->notes)
                                <p class="mt-3 rounded-lg bg-gray-900/60 px-3 py-2 text-xs text-gray-300">
                                    {{ $booking->notes }}
                                </p>
                            @endif
                        </div>

                        {{-- Decision --}}
                        <div class="flex shrink-0 flex-col gap-2">
                            @if ($booking->status !== App\Enums\BookingStatus::Confirmed)
                                <form method="POST" action="{{ route('instructor.bookings.status', $booking) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="confirmed">
                                    <button type="submit" class="btn-primary w-full">
                                        <x-icon name="check" class="h-4 w-4" />
                                        Confirm
                                    </button>
                                </form>
                            @endif

                            @if ($booking->status !== App\Enums\BookingStatus::Cancelled)
                                <form method="POST" action="{{ route('instructor.bookings.status', $booking) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn-secondary w-full">Cancel</button>
                                </form>
                            @endif

                            @if ($booking->status === App\Enums\BookingStatus::Confirmed && ! $booking->isConverted())
                                {{-- Confirming is not enrolling: the prospect still has
                                     no account until someone creates one. --}}
                                <a href="{{ route('instructor.students.create') }}" class="btn-ghost btn-sm">
                                    Enrol as student
                                </a>
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        @if ($bookings->hasPages())
            <div class="mt-4">{{ $bookings->links() }}</div>
        @endif
    @endif
@endsection
