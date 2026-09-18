{{--
    The floating KakaoTalk actions, on every signed-in page.

    Both are reached mid-task — an instructor part-way through a roster, or one
    who needs the office now — so neither belongs in the header of whichever
    panel it happened to sit above.

    Renders nothing at all, not even the positioned wrapper, when neither action
    is available: an empty fixed div is invisible but still counts as chrome,
    and the payslip asserts exactly how much chrome a page carries.
--}}

@php($canSend = auth()->user()?->role === App\Enums\Role::Instructor
    && App\Services\Kakao\KakaoMessenger::isConfigured())

@if ($canSend || App\Support\KakaoChannel::isConfigured())
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

        <x-kakao-channel action="chat" class="shadow-lg" />
    </div>
@endif
