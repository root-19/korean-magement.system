{{--
    A day's roster with inline attendance marking. Shared by the dashboard's
    "Today", "Tomorrow" and "Selected date" panels.

    Expects: $roster (collection from DashboardController::rosterFor) and $date.
    Optional: $markable — pass false to render the day as a read-only preview.

    The controls are real forms posting to instructor.classes.attendance, so
    marking works with JavaScript off; Alpine only reveals the absent-party
    prompt. Who was absent decides pay, so it is asked and never defaulted.
--}}

@php
    $day = \Carbon\Carbon::parse($date);
    $dateString = $day->toDateString();

    // A day that has not happened yet has nothing to mark: attendance is
    // recorded on the day, and teaching a later slot early goes through the
    // early-class flow on the class list, which credits the right date.
    $markable = $markable ?? ! $day->copy()->startOfDay()->isFuture();

    // A past day is closed as well, unless an admin reopened that exact class.
    $isPast = $day->copy()->startOfDay()->isPast() && ! $day->isToday();
@endphp

@if ($roster->isEmpty())
    <x-empty-state icon="calendar"
                   title="No classes on {{ \Carbon\Carbon::parse($date)->format('l, F j') }}"
                   message="This roster lists each student timetabled that day, plus any makeup class moved onto it.">
        <a href="{{ route('instructor.students.index') }}" class="btn-secondary btn-sm">View students</a>
    </x-empty-state>
@else
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th class="text-center">Left</th>
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
                        $classOpen = App\Models\AttendanceRequest::classIsOpen($dateString, $evaluation);

                        // Where a postponement would send this class, for the modal.
                        $makeup = App\Support\MakeupSchedule::for(
                            $student,
                            $dateString,
                            (int) ($profile?->sessions_remaining ?? 0),
                        );
                    @endphp

                    <tr>
                        <td>
                            <a href="{{ route('instructor.students.show', $student) }}"
                               class="focus-ring flex items-center gap-2.5 rounded">
                                <x-avatar :user="$student" class="h-8 w-8" />
                                <span class="min-w-0">
                                    {{-- Trial rides on the name itself, not in the Type
                                         column: it is who the student is, while the
                                         badges below are about this one day. --}}
                                    <span class="flex items-center gap-1.5">
                                        <span class="truncate font-medium text-white">{{ $student->name }}</span>
                                        @if ($profile && ! $profile->is_regular)
                                            <span class="badge-warning shrink-0">Trial</span>
                                        @endif
                                    </span>

                                    @if ($session?->isEarly())
                                        <span class="badge-brand mt-0.5">
                                            Taught early · covers {{ $session->scheduled_date->format('M j') }}
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

                        <td class="numeric whitespace-nowrap text-gray-400">
                            {{ $row['time'] ? \Carbon\Carbon::parse($row['time'])->format('g:i A') : '—' }}
                        </td>

                        <td class="whitespace-nowrap text-gray-400">
                            {{ $profile?->teaching_method?->label() ?? '—' }}
                            <span class="numeric text-xs text-gray-500">
                                {{ $profile?->learning_time ? $profile->learning_time.'m' : '' }}
                            </span>
                        </td>

                        <td class="numeric text-center">
                            <span @class([
                                'font-medium',
                                'text-danger-400' => ($profile?->sessions_remaining ?? 0) === 0,
                                'text-gray-200' => ($profile?->sessions_remaining ?? 0) > 0,
                            ])>{{ $profile?->sessions_remaining ?? 0 }}</span>
                        </td>

                        <td>
                            @unless ($markable)
                                {{-- Preview of a day still to come. A session row can
                                     already exist here — a postponement moved to this
                                     date, say — so show it when there is one. --}}
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
                                            <button type="submit" class="btn-ghost btn-sm !px-1.5"
                                                    aria-label="Clear attendance for {{ $student->name }}">
                                                <x-icon name="x" class="h-3.5 w-3.5" />
                                            </button>
                                        </form>
                                    @elseif (! $classOpen)
                                        {{-- Closed: this class is no longer today, so marking it
                                             is a payroll edit and needs an admin decision. --}}
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
                                                        'date' => $dateString,
                                                        'dateLabel' => $day->format('l, F j, Y'),
                                                        'rejectedNote' => $evaluation?->decision_note,
                                                    ]))">
                                                For evaluation
                                            </button>
                                        @endif
                                    @else
                                        <form method="POST" action="{{ route('instructor.classes.attendance') }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="student_id" value="{{ $student->id }}">
                                            <input type="hidden" name="date" value="{{ $dateString }}">
                                            <input type="hidden" name="status" value="present">
                                            <button type="submit" class="btn-primary btn-sm">Present</button>
                                        </form>

                                        <button type="button" x-on:click="asking = true" class="btn-secondary btn-sm">
                                            Absent
                                        </button>

                                        {{-- A real submit: with JavaScript off this posts and the
                                             server picks the makeup date. Alpine upgrades it to the
                                             modal, where the instructor says who postponed it, why,
                                             and when it comes back. --}}
                                        <form method="POST" action="{{ route('instructor.classes.attendance') }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="student_id" value="{{ $student->id }}">
                                            <input type="hidden" name="date" value="{{ $dateString }}">
                                            <input type="hidden" name="status" value="postponed">
                                            <button type="submit"
                                                    class="btn-ghost btn-sm"
                                                    x-on:click.prevent="$dispatch('open-postpone-modal', @js([
                                                        'studentId' => $student->id,
                                                        'studentName' => $student->name,
                                                        'date' => $dateString,
                                                        'dateLabel' => $day->format('l, F j, Y'),
                                                        'dateShort' => $day->format('D, M j'),
                                                        'makeup' => $makeup->toArray(),
                                                    ]))">
                                                Postpone
                                            </button>
                                        </form>
                                    @endif
                                </div>

                                <div x-show="asking" x-cloak class="space-y-1.5">
                                    <p class="text-xs font-medium text-gray-400">Who was absent?</p>

                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (['student' => 'Student', 'teacher' => 'Me'] as $party => $partyLabel)
                                            <form method="POST" action="{{ route('instructor.classes.attendance') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="student_id" value="{{ $student->id }}">
                                                <input type="hidden" name="date" value="{{ $dateString }}">
                                                <input type="hidden" name="status" value="absent">
                                                <input type="hidden" name="party" value="{{ $party }}">
                                                <button type="submit"
                                                        @class(['btn-sm', 'btn-secondary' => $party === 'student', 'btn-danger' => $party === 'teacher'])>
                                                    {{ $partyLabel }}
                                                </button>
                                            </form>
                                        @endforeach

                                        <button type="button" x-on:click="asking = false" class="btn-ghost btn-sm">
                                            Cancel
                                        </button>
                                    </div>

                                    <p class="text-[11px] text-gray-500">
                                        “Me” is recorded as a teacher absence and deducted from your payout.
                                    </p>
                                </div>
                            </div>
                            @endunless
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
                                    ]) }}" class="btn-secondary btn-sm">Report</a>
                            @else
                                <span class="text-xs text-gray-500">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
