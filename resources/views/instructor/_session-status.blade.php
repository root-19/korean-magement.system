{{--
    A marked session's status plus the detail that decides money or scheduling.

    Expects: $session (a marked ClassSession).

    Two things are never left implicit here, because both were questions
    instructors had to ask someone to answer:

      * Who was absent. Student-absent still pays — the instructor showed up and
        waited. Teacher-absent is a deduction, so it is labelled as one.
      * Where a postponed class went. "Postponed" alone does not say when the
        student comes back.
--}}

{{-- One expression, so the badge reads "Absent (Teacher)" without the line break
     Blade would otherwise leave between the two halves. --}}
<span class="{{ $session->status->badgeClass() }}">{{ $session->status->label() }}{{ $session->absent_by ? ' ('.$session->absent_by->label().')' : '' }}</span>

@if ($session->absent_by === App\Enums\Party::Teacher)
    {{-- Not pay withheld: an actual subtraction from the payout. --}}
    <span class="badge-danger">Deducted from payout</span>
@endif

@if ($session->status === App\Enums\SessionStatus::Postponed)
    <span class="badge-neutral">
        by {{ $session->postponed_by?->label() ?? 'Other' }}

        @if ($session->rescheduled_date)
            · back {{ $session->rescheduled_date->format('D, M j') }}@if ($session->rescheduled_time),
                {{ \Carbon\Carbon::parse($session->rescheduled_time)->format('g:i A') }}@endif
        @endif
    </span>

    @unless ($session->rescheduled_date)
        {{-- Nothing schedules this class now; it will not appear on any roster. --}}
        <span class="badge-warning">No return date</span>
    @endunless
@endif
