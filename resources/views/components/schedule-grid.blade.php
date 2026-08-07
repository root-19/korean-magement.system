@props(['grid', 'days', 'label' => null])

{{--
    An instructor's week as a day × hour table, in the app's dark palette.

    The landing page renders the same data with its own glass styling; this is
    the signed-in version. Both read App\Support\WeeklyScheduleGrid.
--}}

@if ($grid === null || $grid->isEmpty())
    <x-empty-state icon="calendar"
                   title="No schedule published"
                   message="This instructor has no availability on record, and none of their students have a timetable yet." />
@else
    @if (! $grid->isDeclared)
        <p class="mb-3 rounded-lg bg-brand-500/10 px-3 py-2 text-xs text-brand-400">
            Derived from existing class hours — this instructor has not published
            availability of their own.
        </p>
    @endif

    <div class="table-wrap">
        <table class="table">
            @if ($label)
                <caption class="sr-only">{{ $label }}</caption>
            @endif

            <thead>
                <tr>
                    <th scope="col" class="sticky left-0 z-10 bg-gray-800">Time</th>
                    @foreach ($days as $dayName)
                        <th scope="col" class="text-center">
                            <abbr title="{{ $dayName }}" class="no-underline">{{ substr($dayName, 0, 3) }}</abbr>
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($grid->hours() as $hour)
                    <tr>
                        <th scope="row"
                            class="sticky left-0 z-10 whitespace-nowrap bg-gray-800 px-4 py-3 text-left font-bold text-gray-300">
                            {{ \Carbon\Carbon::createFromTime($hour)->format('g:i A') }}
                        </th>

                        @foreach (array_keys($days) as $isoDay)
                            @php $slot = $grid->slot($isoDay, $hour); @endphp

                            <td class="px-2 py-2 text-center">
                                @if ($slot === null)
                                    <span class="text-xs text-gray-600">—</span>
                                @elseif ($slot['status'] === App\Support\WeeklyScheduleGrid::AVAILABLE)
                                    <span class="badge-success">Free</span>
                                    <span class="numeric mt-1 block text-[11px] text-gray-500">
                                        {{ \Carbon\Carbon::parse($slot['start_time'])->format('g:i') }}–{{ \Carbon\Carbon::parse($slot['end_time'])->format('g:i A') }}
                                    </span>
                                @else
                                    <span class="badge-danger">Booked</span>
                                    <span class="numeric mt-1 block text-[11px] text-gray-500">
                                        {{ \Carbon\Carbon::parse($slot['start_time'])->format('g:i') }}–{{ \Carbon\Carbon::parse($slot['end_time'])->format('g:i A') }}
                                    </span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
