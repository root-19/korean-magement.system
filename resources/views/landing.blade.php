<!DOCTYPE html>
{{--
    Public landing page.

    Design carried over from the legacy public/index.php (hero, typewriter,
    reveal-on-click login) and public/instructor_profile.php (glass panels,
    gradient headings, weekly schedule table).

    Always dark, no theme toggle — the original had none. The `dark` class is off
    so the app's theme-aware components cannot repaint it.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name') }} · 저스트텐미닛 전화, 화상영어</title>
    <meta name="description" content="Just 10 Min — phone and video English lessons. See each teacher's weekly schedule and book a trial class.">

    {{-- Self-hosted through Vite rather than the legacy CDN Tailwind build and
         Google Fonts link: no third-party request, and the same compiled CSS the
         rest of the app uses. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="landing h-full">

{{-- ─────────────────────────────────────────────────────────── Hero ───── --}}
<section x-data="{ showLogin: {{ $errors->any() ? 'true' : 'false' }} }"
         class="relative flex min-h-screen items-center justify-center px-6">

    {{-- Welcome copy. Hidden once the login card is revealed, as in the original. --}}
    <div x-show="!showLogin" class="text-center">
        <h1 class="landing-orange mb-4 text-4xl font-bold tracking-tight sm:text-5xl">
            {{ config('app.name') }}
        </h1>

        <p class="landing-yellow mb-6 text-lg font-bold">저스트텐미닛 전화, 화상영어</p>

        {{-- Typewriter paragraph. The text is present in the markup so it is
             readable without JavaScript and to crawlers; Alpine retypes it. --}}
        <p x-data="typewriter('Experience a modern, secure, and efficient system with Just 10 Min. Streamline your workflow and boost productivity.')"
           x-text="shown"
           :class="{ 'landing-caret': typing }"
           class="mx-auto mb-10 max-w-2xl text-lg leading-relaxed text-white">
            Experience a modern, secure, and efficient system with Just 10 Min.
            Streamline your workflow and boost productivity.
        </p>

        <div class="flex flex-wrap items-center justify-center gap-3">
            <button type="button"
                    x-on:click="showLogin = true"
                    class="inline-block transform rounded-lg bg-orange-500 px-8 py-3 text-lg font-medium text-white shadow-lg transition duration-300 hover:scale-105 hover:bg-orange-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-2 focus-visible:ring-offset-[#181818]">
                Get Started →
            </button>

            @if ($instructors->isNotEmpty())
                <a href="#schedule"
                   class="inline-block rounded-lg border border-white/20 px-8 py-3 text-lg font-medium text-white transition duration-300 hover:border-white/40 hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-2 focus-visible:ring-offset-[#181818]">
                    View schedules
                </a>
            @endif
        </div>
    </div>

    {{-- Login card. Revealed by the button; also shown straight away when a
         failed sign-in bounces back, so the errors are not on a hidden panel. --}}
    <div x-show="showLogin"
         x-cloak
         x-transition
         class="w-full max-w-md rounded-xl border border-gray-800 bg-gray-900/95 p-8 shadow-2xl backdrop-blur-sm">

        <h2 class="landing-orange mb-6 text-center text-2xl font-bold">
            Welcome Back to {{ config('app.name') }}
        </h2>

        @if (session('status'))
            <p class="mb-4 text-center text-sm text-yellow-400">{{ session('status') }}</p>
        @endif

        @error('login')
            <p class="mb-4 text-center text-sm text-yellow-400">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="login" class="mb-2 block text-sm font-medium text-white">
                    Email or username
                </label>
                <input id="login"
                       name="login"
                       type="text"
                       value="{{ old('login') }}"
                       required
                       autocomplete="username"
                       class="w-full rounded-lg border border-gray-700 bg-gray-800 p-3 text-white transition placeholder:text-gray-500 focus:border-transparent focus:ring-2 focus:ring-orange-500">
                {{-- Most students have no email on file, so the name is accepted too. --}}
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-white">Password</label>
                <input id="password"
                       name="password"
                       type="password"
                       required
                       autocomplete="current-password"
                       class="w-full rounded-lg border border-gray-700 bg-gray-800 p-3 text-white transition focus:border-transparent focus:ring-2 focus:ring-orange-500">

                @error('password')
                    <p class="mt-1.5 text-sm text-yellow-400">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox"
                       name="remember"
                       value="1"
                       class="rounded border-gray-600 bg-gray-800 text-orange-500 focus:ring-orange-500">
                Keep me signed in
            </label>

            <button type="submit"
                    class="w-full rounded-lg bg-orange-500 py-3 font-medium text-white transition duration-300 hover:bg-orange-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-900">
                Sign In
            </button>
        </form>

        <button type="button"
                x-on:click="showLogin = false"
                class="mt-6 w-full text-center text-sm text-gray-400 transition hover:text-white">
                ← Back
        </button>
    </div>
</section>

{{-- ──────────────────────────────────────────────── Teacher schedules ───── --}}
@if ($instructors->isNotEmpty())
    <section id="schedule" class="mx-auto max-w-7xl px-4 pb-16">

        <div class="mb-8 text-center">
            <h2 class="landing-gradient-text text-3xl font-bold">Teacher Schedules</h2>
            <p class="mt-2 text-gray-400">
                Pick a teacher to see their week, then book a trial class.
            </p>
        </div>

        {{-- Teacher picker. Plain links with a query string, so it works without
             JavaScript and each teacher's week is a shareable URL. --}}
        <div class="landing-glass landing-fade-in mb-6 rounded-3xl p-4 shadow-2xl sm:p-6">
            <div class="flex gap-3 overflow-x-auto pb-1">
                @foreach ($instructors as $instructor)
                    @php $isSelected = $selected && $instructor->id === $selected->id; @endphp

                    <a href="{{ route('home', ['instructor' => $instructor->id]) }}#schedule"
                       @class([
                           'flex shrink-0 items-center gap-3 rounded-2xl border px-4 py-3 transition',
                           'border-yellow-400/60 bg-yellow-400/10' => $isSelected,
                           'border-white/10 hover:border-white/25 hover:bg-white/5' => ! $isSelected,
                       ])
                       @if ($isSelected) aria-current="true" @endif>

                        @if ($instructor->avatar_path)
                            <img src="{{ asset('storage/'.$instructor->avatar_path) }}"
                                 alt=""
                                 loading="lazy"
                                 class="h-11 w-11 rounded-xl object-cover ring-2 ring-yellow-400/50">
                        @else
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-yellow-400/20 text-sm font-bold text-yellow-400 ring-2 ring-yellow-400/40">
                                {{ $instructor->initials() }}
                            </span>
                        @endif

                        <span class="text-left">
                            <span class="block whitespace-nowrap text-sm font-semibold text-white">
                                {{ $instructor->name }}
                            </span>
                            <span class="block whitespace-nowrap text-xs text-gray-400">
                                {{ $instructor->active_student_count }}
                                {{ Str::plural('student', $instructor->active_student_count) }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- The week itself. --}}
        <div class="landing-glass landing-fade-in rounded-3xl p-6 shadow-2xl sm:p-8">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="landing-gradient-text text-2xl font-bold sm:text-3xl">
                        Weekly Schedule
                    </h3>
                    @if ($selected)
                        <p class="mt-1 text-sm text-gray-400">
                            {{ $selected->name }}
                            @if ($grid && ! $grid->isDeclared)
                                {{-- Say where the times come from rather than
                                     implying the teacher published them. --}}
                                · times shown are existing class hours
                            @elseif ($grid)
                                · {{ $grid->availableHours() }} {{ Str::plural('open hour', $grid->availableHours()) }} this week
                            @endif
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="landing-pill bg-green-500/20 text-green-400">
                        <span class="h-2 w-2 rounded-full bg-green-400"></span>Available
                    </span>
                    <span class="landing-pill bg-red-500/20 text-red-400">
                        <span class="h-2 w-2 rounded-full bg-red-400"></span>Not Available
                    </span>
                    <span class="landing-pill bg-gray-600/20 text-gray-400">
                        <span class="h-2 w-2 rounded-full bg-gray-400"></span>No Schedule
                    </span>
                </div>
            </div>

            @if (! $grid || $grid->isEmpty())
                <div class="py-12 text-center">
                    <p class="text-lg text-gray-400">No schedule available</p>
                    <p class="mt-1 text-sm text-gray-500">
                        This teacher has not published any hours yet.
                    </p>
                </div>
            @else
                {{-- Scrolls itself on narrow screens; the page never scrolls sideways. --}}
                <div class="-mx-2 overflow-x-auto sm:mx-0">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">
                            {{ $selected->name }}'s weekly availability, by hour and day
                        </caption>

                        <thead>
                            <tr class="border-b border-gray-700">
                                <th scope="col"
                                    class="sticky left-0 z-10 rounded-tl-lg bg-gray-800 px-4 py-3 text-left font-semibold text-yellow-400">
                                    Time
                                </th>
                                @foreach ($days as $isoDay => $dayName)
                                    <th scope="col"
                                        class="bg-gray-800/50 px-4 py-3 text-center font-semibold text-yellow-400">
                                        <abbr title="{{ $dayName }}" class="no-underline">
                                            {{ substr($dayName, 0, 3) }}
                                        </abbr>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($grid->hours() as $hour)
                                <tr class="border-b border-gray-700/50 transition-colors hover:bg-gray-800/30">
                                    <th scope="row"
                                        class="sticky left-0 z-10 whitespace-nowrap bg-gray-800 px-4 py-3 text-left font-bold text-gray-300">
                                        {{ \Carbon\Carbon::createFromTime($hour)->format('g:i A') }}
                                    </th>

                                    @foreach ($days as $isoDay => $dayName)
                                        @php $slot = $grid->slot($isoDay, $hour); @endphp

                                        <td class="px-2 py-3 text-center">
                                            @if ($slot === null)
                                                <span class="landing-pill bg-gray-700/30 !px-3 !py-2 text-gray-500">
                                                    No Schedule
                                                </span>
                                            @elseif ($slot['status'] === App\Support\WeeklyScheduleGrid::AVAILABLE)
                                                <span class="landing-pill bg-green-500/20 !px-3 !py-2 text-green-400">
                                                    Available
                                                </span>
                                                <span class="numeric mt-1 block text-xs text-gray-400">
                                                    {{ \Carbon\Carbon::parse($slot['start_time'])->format('g:i A') }}
                                                    –
                                                    {{ \Carbon\Carbon::parse($slot['end_time'])->format('g:i A') }}
                                                </span>
                                            @else
                                                <span class="landing-pill bg-red-500/20 !px-3 !py-2 text-red-400">
                                                    Not Available
                                                </span>
                                                <span class="numeric mt-1 block text-xs text-gray-400">
                                                    {{ \Carbon\Carbon::parse($slot['start_time'])->format('g:i A') }}
                                                    –
                                                    {{ \Carbon\Carbon::parse($slot['end_time'])->format('g:i A') }}
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-4 text-xs text-gray-500">
                    Times are shown in Korea Standard Time.
                </p>
            @endif
        </div>
    </section>
@endif

<footer class="border-t border-white/5 py-8 text-center text-sm text-gray-500">
    &copy; {{ date('Y') }} {{ config('app.name') }} · 저스트텐미닛
</footer>

<script>
    /**
     * Retypes text that is already in the DOM, so the paragraph is readable
     * with JavaScript off and the animation is pure decoration.
     */
    document.addEventListener('alpine:init', () => {
        Alpine.data('typewriter', (text) => ({
            shown: '',
            typing: true,

            init() {
                // Honour a reduced-motion preference by skipping the animation.
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    this.shown = text;
                    this.typing = false;

                    return;
                }

                let i = 0;
                const step = () => {
                    if (i < text.length) {
                        this.shown += text.charAt(i++);
                        setTimeout(step, 45);
                    } else {
                        this.typing = false;
                    }
                };

                step();
            },
        }));
    });
</script>

</body>
</html>
