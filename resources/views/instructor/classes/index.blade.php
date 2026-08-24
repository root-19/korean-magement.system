@extends('layouts.app')

@section('title', 'Classes')
@section('heading', 'Classes')
@section('subheading', $date->format('l, F j, Y'))

@section('actions')
    <form method="GET" class="flex items-center gap-1.5">
        <a href="{{ route('instructor.classes.index', ['date' => $date->subDay()->toDateString()]) }}"
           class="btn-ghost !p-2" aria-label="Previous day">
            <x-icon name="chevron-left" class="h-4 w-4" />
        </a>

        <input type="date"
               name="date"
               value="{{ $date->toDateString() }}"
               onchange="this.form.submit()"
               class="form-input numeric !w-auto !py-1.5 text-xs"
               aria-label="Class date">

        <a href="{{ route('instructor.classes.index', ['date' => $date->addDay()->toDateString()]) }}"
           class="btn-ghost !p-2" aria-label="Next day">
            <x-icon name="chevron-right" class="h-4 w-4" />
        </a>
    </form>
@endsection

@section('content')
    @php
        // A day that has not happened yet has nothing to mark: attendance is
        // recorded on the day, and teaching a later slot early goes through the
        // early-class flow, which credits the right date. Same rule as _roster,
        // which the dashboard renders its day panels through — without it every
        // row on a future date offered "For evaluation", the control for
        // reopening a class that has already passed.
        $isUpcoming = $date->startOfDay()->isFuture();

        // Rows are classes, and one student can hold two of them when a makeup
        // lands beside their regular class, so the heading counts the people.
        $studentCount = $roster->unique(fn (array $row) => $row['student']->id)->count();
    @endphp

    @if (! $date->isToday())
        <div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-brand-500/30 bg-brand-500/10 px-4 py-2.5 text-sm text-brand-400">
            <span>Showing {{ $date->isFuture() ? 'a future' : 'a past' }} date.</span>
            <a href="{{ route('instructor.classes.index') }}" class="font-medium underline underline-offset-2">
                Back to today
            </a>
        </div>
    @endif

    <x-card flush>
        <x-slot:title>
            {{ $studentCount }} {{ Str::plural('student', $studentCount) }} scheduled
        </x-slot:title>

        <x-slot:subtitle>
            {{ $roster->whereNotNull('session.status')->count() }} marked ·
            {{ $roster->filter(fn ($r) => $r['session']?->status === null)->count() }} pending
        </x-slot:subtitle>

        <x-slot:actions>
            <button type="button"
                    class="btn-secondary btn-sm"
                    x-data
                    x-on:click="$dispatch('open-early-modal')">
                <x-icon name="forward" class="h-4 w-4" />
                Early class
            </button>
        </x-slot:actions>

        @if ($roster->isEmpty())
            <x-empty-state icon="calendar"
                           title="No classes on {{ $date->format('l, F j') }}"
                           message="This roster lists each student timetabled that day, plus any makeup class moved onto it." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Sessions left</th>
                            <th>Attendance</th>
                            <th>Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roster as $row)
                            @php
                                $student = $row['student'];
                                $profile = $row['profile'];
                                $session = $row['session'];

                                $evaluation = $row['request'] ?? null;
                                $classOpen = App\Models\AttendanceRequest::classIsOpen($date->toDateString(), $evaluation);

                                // Where a postponement would send this class, for the modal.
                                $makeup = App\Support\MakeupSchedule::for(
                                    $student,
                                    $date->toDateString(),
                                    (int) ($profile?->sessions_remaining ?? 0),
                                );
                            @endphp

                            <tr>
                                <td>
                                    <a href="{{ route('instructor.students.show', $student) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$student" class="h-8 w-8" />
                                        <span class="min-w-0">
                                            {{-- Trial rides on the name itself, not in the
                                                 Type column: it is who the student is, while
                                                 the badges below are about this one day. --}}
                                            <span class="flex items-center gap-1.5">
                                                <span class="truncate font-medium text-white">
                                                    {{ $student->name }}
                                                </span>
                                                @if ($profile && ! $profile->is_regular)
                                                    <span class="badge-warning shrink-0">Trial</span>
                                                @endif
                                            </span>
                                            @if ($session?->isEarly())
                                                <span class="badge-brand mt-0.5">
                                                    Taught early {{ $session->held_date->format('M j') }}
                                                </span>
                                            @elseif ($row['makeup_for'])
                                                <span class="badge-warning mt-0.5">
                                                    Makeup for {{ $row['makeup_for']->format('M j') }}
                                                </span>
                                            @elseif ($row['is_extra'])
                                                {{-- Not their usual weekday: say why they are listed. --}}
                                                <span class="badge-neutral mt-0.5">Off timetable</span>
                                            @endif
                                        </span>
                                    </a>
                                </td>

                                <td class="numeric whitespace-nowrap text-gray-500">
                                    {{ $row['time'] ? \Carbon\Carbon::parse($row['time'])->format('g:i A') : '—' }}
                                </td>

                                <td class="whitespace-nowrap text-gray-500">
                                    {{ $profile?->teaching_method?->label() ?? '—' }}
                                    <span class="numeric text-xs text-gray-400">
                                        {{ $profile?->learning_time ? $profile->learning_time.'m' : '' }}
                                    </span>
                                </td>

                                <td class="numeric">
                                    <span @class([
                                        'font-medium',
                                        'text-danger-400' => ($profile?->sessions_remaining ?? 0) === 0,
                                        'text-gray-300' => ($profile?->sessions_remaining ?? 0) > 0,
                                    ])>
                                        {{ $profile?->sessions_remaining ?? 0 }}
                                    </span>
                                </td>

                                {{-- Attendance controls. Posts a normal form, so it works
                                     without JavaScript; Alpine only handles the
                                     absent-party prompt. --}}
                                <td>
                                    @if ($isUpcoming)
                                        {{-- A row can already carry a session here — a makeup
                                             moved onto this date — so show what it says. --}}
                                        @if ($session?->status)
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                @include('instructor._session-status', ['session' => $session])
                                            </span>
                                        @else
                                            <span class="badge-neutral">Scheduled</span>
                                        @endif
                                    @else
                                    <div x-data="{ asking: false }" class="min-w-[13rem]">
                                        <div x-show="!asking" class="flex flex-wrap items-center gap-1.5">
                                            @if ($session?->status)
                                                @include('instructor._session-status', ['session' => $session])

                                                <form method="POST"
                                                      action="{{ route('instructor.classes.destroy', $session) }}"
                                                      class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="btn-ghost btn-sm !px-1.5"
                                                            aria-label="Clear attendance for {{ $student->name }}">
                                                        <x-icon name="x" class="h-3.5 w-3.5" />
                                                    </button>
                                                </form>
                                            @elseif (! $classOpen)
                                                {{-- Closed: no longer today, so marking it would
                                                     change a past payout. Needs an admin. --}}
                                                @if ($evaluation?->isPending())
                                                    <span class="badge-warning">Awaiting evaluation</span>
                                                @else
                                                    @if ($evaluation?->isRejected())
                                                        <span class="badge-danger">Rejected</span>
                                                    @endif

                                                    <button type="button"
                                                            class="btn-secondary btn-sm"
                                                            x-on:click="$dispatch('open-evaluation-modal', @js([
                                                                'studentId' => $student->id,
                                                                'studentName' => $student->name,
                                                                'date' => $date->toDateString(),
                                                                'dateLabel' => $date->format('l, F j, Y'),
                                                                'rejectedNote' => $evaluation?->decision_note,
                                                            ]))">
                                                        For evaluation
                                                    </button>
                                                @endif
                                            @else
                                                <form method="POST"
                                                      action="{{ route('instructor.classes.attendance') }}"
                                                      class="inline">
                                                    @csrf
                                                    <input type="hidden" name="student_id" value="{{ $student->id }}">
                                                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                                    <input type="hidden" name="status" value="present">
                                                    <button type="submit" class="btn-primary btn-sm">Present</button>
                                                </form>

                                                <button type="button"
                                                        x-on:click="asking = true"
                                                        class="btn-secondary btn-sm">
                                                    Absent
                                                </button>

                                                {{-- A real submit, so postponing still works with
                                                     JavaScript off; Alpine upgrades it to the modal
                                                     that asks who postponed it and when it returns. --}}
                                                <form method="POST"
                                                      action="{{ route('instructor.classes.attendance') }}"
                                                      class="inline">
                                                    @csrf
                                                    <input type="hidden" name="student_id" value="{{ $student->id }}">
                                                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                                    <input type="hidden" name="status" value="postponed">
                                                    <button type="submit"
                                                            class="btn-ghost btn-sm"
                                                            x-on:click.prevent="$dispatch('open-postpone-modal', @js([
                                                                'studentId' => $student->id,
                                                                'studentName' => $student->name,
                                                                'date' => $date->toDateString(),
                                                                'dateLabel' => $date->format('l, F j, Y'),
                                                                'dateShort' => $date->format('D, M j'),
                                                                'makeup' => $makeup->toArray(),
                                                            ]))">
                                                        Postpone
                                                    </button>
                                                </form>
                                            @endif
                                        </div>

                                        {{-- Who was absent decides pay: student-absent still
                                             pays the instructor, teacher-absent is deducted.
                                             So it is asked, never defaulted. --}}
                                        <div x-show="asking" x-cloak class="space-y-1.5">
                                            <p class="text-xs font-medium text-gray-500">
                                                Who was absent?
                                            </p>
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach (['student' => 'Student', 'teacher' => 'Me'] as $party => $partyLabel)
                                                    <form method="POST"
                                                          action="{{ route('instructor.classes.attendance') }}"
                                                          class="inline">
                                                        @csrf
                                                        <input type="hidden" name="student_id" value="{{ $student->id }}">
                                                        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                                        <input type="hidden" name="status" value="absent">
                                                        <input type="hidden" name="party" value="{{ $party }}">
                                                        <button type="submit"
                                                                @class([
                                                                    'btn-sm',
                                                                    'btn-secondary' => $party === 'student',
                                                                    'btn-danger' => $party === 'teacher',
                                                                ])>
                                                            {{ $partyLabel }}
                                                        </button>
                                                    </form>
                                                @endforeach

                                                <button type="button" x-on:click="asking = false" class="btn-ghost btn-sm">
                                                    Cancel
                                                </button>
                                            </div>
                                            <p class="text-[11px] text-gray-400">
                                                “Me” is recorded as a teacher absence and deducted from your payout.
                                            </p>
                                        </div>
                                    </div>
                                    @endif
                                </td>

                                <td>
                                    @if ($row['has_report'])
                                        <span class="badge-success">
                                            <x-icon name="check" class="h-3 w-3" />
                                            Filed
                                        </span>
                                    @elseif ($session?->isPayable())
                                        <a href="{{ route('instructor.reports.create', [
                                                'student_id' => $student->id,
                                                'date' => $session->paid_date->toDateString(),
                                            ]) }}"
                                           class="btn-secondary btn-sm">
                                            File report
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    @include('instructor.classes._early-modal', ['roster' => $roster, 'date' => $date])
    @include('instructor._postpone-modal')
    @include('instructor._evaluation-modal')
@endsection
