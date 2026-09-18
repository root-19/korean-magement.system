@props([
    'action' => 'chat',
    'label' => null,
])

{{--
    A KakaoTalk channel button: chat with the academy, or add the channel so the
    academy can reach the student. See App\Support\KakaoChannel.

    It is a real link first and an SDK call second. The pf.kakao.com href works
    with JavaScript off, with the Kakao CDN blocked, and before anyone fills in a
    JavaScript key; the delegated handler below only upgrades it to the in-page
    flow when the SDK is actually there. The rest of the app is built the same
    way — see the attendance forms in instructor/_roster.blade.php.

    Renders nothing at all when no channel is configured, so an install without
    keys shows no button rather than a broken one.
--}}

@php($url = App\Support\KakaoChannel::url($action))

@if ($url !== null)
    @once
        @if (App\Support\KakaoChannel::isEnhanced())
            {{-- Deferred: chrome must never block the page it decorates. Inline
                 scripts are not deferred, so the init waits for DOMContentLoaded,
                 which fires after deferred scripts have run. --}}
            <script src="https://t1.kakaocdn.net/kakao_js_sdk/2.8.1/kakao.min.js"
                    integrity="sha384-OL+ylM/iuPLtW5U3XcvLSGhE8JzReKDank5InqlHGWPhb4140/yrBw0bg0y7+C9J"
                    crossorigin="anonymous"
                    defer></script>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // Blocked CDN, failed integrity check, offline: the links
                    // are already correct, so there is nothing to do.
                    if (!window.Kakao) {
                        return;
                    }

                    if (!Kakao.isInitialized()) {
                        Kakao.init(@json(App\Support\KakaoChannel::javascriptKey()));
                    }

                    const channelPublicId = @json(App\Support\KakaoChannel::publicId());

                    // One delegated listener rather than one per button, so any
                    // number of them on a page costs the same.
                    document.addEventListener('click', function (event) {
                        const button = event.target.closest('[data-kakao-channel]');

                        if (!button) {
                            return;
                        }

                        const action = button.dataset.kakaoChannel;

                        if (action === 'chat') {
                            event.preventDefault();
                            Kakao.Channel.chat({ channelPublicId });

                            return;
                        }

                        if (action === 'add') {
                            event.preventDefault();
                            Kakao.Channel.addChannel({ channelPublicId });

                            return;
                        }

                        // followChannel is an API call, and it needs the visitor
                        // signed in with Kakao Login — which this app does not
                        // do. So it is tried, and the plain link is opened when
                        // it is refused instead of showing the visitor a raw
                        // error the way Kakao's own sample does.
                        if (action === 'follow') {
                            event.preventDefault();

                            Kakao.Channel.followChannel({ channelPublicId })
                                .catch(function () {
                                    window.open(button.href, '_blank', 'noopener');
                                });
                        }
                    });
                });
            </script>
        @endif
    @endonce

    <a href="{{ $url }}"
       target="_blank"
       rel="noopener noreferrer"
       data-kakao-channel="{{ $action }}"
       {{ $attributes->merge([
           'class' => 'focus-ring inline-flex items-center gap-2 rounded-lg bg-[#FEE500] px-4 py-2'
               .' text-sm font-semibold text-[#191600] transition hover:bg-[#f5dc00]',
       ]) }}>
        {{-- No size override: x-icon merges its own h-5 w-5 and a smaller
             class would lose to it on CSS order, not attribute order. --}}
        <x-icon name="chat" class="shrink-0" />
        {{ $label ?? App\Support\KakaoChannel::label($action) }}
    </a>
@endif
