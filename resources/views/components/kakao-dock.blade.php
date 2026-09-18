{{--
    The floating KakaoTalk actions, on every signed-in page.

    Both are reached mid-task — an instructor part-way through a roster, or one
    who needs the office now — so neither belongs in the header of whichever
    panel it happened to sit above.

    With no channel configured the dock still draws, as a disabled "coming soon"
    placeholder: staff have been told KakaoTalk support is on its way and an
    empty corner reads as a bug rather than as a feature not yet switched on.
    The placeholder is a <span>, not a disabled <button> — there is nothing to
    press, so there is nothing to put in the tab order either.

    Signed-in pages only. The landing and login pages stay bare until a real
    channel exists, because a visitor deciding whether to book has no use for a
    support channel they cannot open. The dock is therefore always chrome now,
    and always carries no-print — see EarningsPrintTest.
--}}

@php($canSend = auth()->user()?->role === App\Enums\Role::Instructor
    && App\Services\Kakao\KakaoMessenger::isConfigured())

<div class="no-print fixed bottom-5 right-5 z-30 flex flex-col items-end gap-2">
    {{-- Instructors only, and only once a Kakao REST key is set: the send
         needs a business app with talk_message approved, and a button that
         can only fail is worse than no button. Admins share the instructor
         routes but have no roster of their own to send. --}}
    @if ($canSend)
        <form method="POST" action="{{ route('instructor.kakao.roster') }}">
            @csrf
            {{-- No date field: a button floating over every page means
                 today, and the controller defaults to it. --}}
            <button type="submit" class="btn-secondary btn-sm shadow-lg">
                <x-icon name="chat" class="shrink-0" />
                Send my roster
            </button>
        </form>
    @endif

    @if (App\Support\KakaoChannel::isConfigured())
        <x-kakao-channel action="chat" class="shadow-lg" />
    @else
        {{-- Kakao's yellow on the icon alone: enough to say which channel is
             coming, without dressing a dead control as a live one. --}}
        <span class="btn-secondary btn-sm cursor-not-allowed opacity-80 shadow-lg"
              aria-disabled="true"
              title="KakaoTalk chat is not set up yet">
            <x-icon name="chat" class="shrink-0 text-[#FEE500]" />
            KakaoTalk — coming soon
        </span>
    @endif
</div>
