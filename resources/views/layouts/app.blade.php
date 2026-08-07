<!DOCTYPE html>
{{--
    Signed-in app shell.

    Design carried over from the legacy sidebars (app/views/instructor/layout/
    sidebar.php and app/views/admin/layout/sidebar.php): gray-900 page, gray-800
    fixed sidebar with the round logo, yellow-400 nav links hovering to
    orange-400, and a yellow toggle button.

    Dark only, as those pages were — there is no theme toggle.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('images/10-minute.png') }}" sizes="any">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">

{{-- `sidebarOpen` drives the drawer on mobile. On desktop the sidebar is always
     in place; `collapsed` hides it there, mirroring the legacy toggle that could
     reclaim the full width. Persisted so it survives navigation. --}}
<div x-data="{
         sidebarOpen: false,
         collapsed: localStorage.getItem('sidebarCollapsed') === '1',
         toggleCollapsed() {
             this.collapsed = !this.collapsed;
             localStorage.setItem('sidebarCollapsed', this.collapsed ? '1' : '0');
         },
     }"
     class="min-h-full">

    {{-- Mobile backdrop --}}
    <div x-show="sidebarOpen"
         x-transition.opacity
         x-on:click="sidebarOpen = false"
         class="no-print fixed inset-0 z-40 bg-black/60 lg:hidden"
         x-cloak
         aria-hidden="true"></div>

    {{-- ───────────────────────────────────────────────────── Sidebar ───── --}}
    <aside class="no-print fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-gray-800 shadow-md transition-transform duration-300"
           :class="{
               'translate-x-0': sidebarOpen,
               '-translate-x-full': !sidebarOpen,
               'lg:translate-x-0': !collapsed,
               'lg:-translate-x-full': collapsed,
           }"
           aria-label="Sidebar">

        <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-700 px-4 py-4">
            <a href="{{ App\Http\Middleware\RedirectToRoleHome::homeFor(auth()->user()) }}"
               class="focus-ring flex items-center gap-3 rounded">
                <img src="{{ asset('images/10-minute.png') }}"
                     alt=""
                     width="48"
                     height="48"
                     class="h-12 w-12 rounded-full object-cover">
                <span class="text-sm font-bold leading-tight text-brand-400">
                    {{ config('app.name') }}
                </span>
            </a>

            <button type="button"
                    x-on:click="sidebarOpen = false"
                    class="text-white transition-colors hover:text-brand-400 lg:hidden"
                    aria-label="Close navigation">
                <x-icon name="x" class="h-6 w-6" />
            </button>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-4 py-5" aria-label="Main">
            @foreach ($navigation as $group)
                <div>
                    @if ($group['label'])
                        <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ $group['label'] }}
                        </p>
                    @endif

                    <ul class="space-y-1">
                        @foreach ($group['items'] as $item)
                            <li>
                                <a href="{{ $item['url'] }}"
                                   @class(['nav-link', 'nav-link-active' => $item['active']])
                                   @if ($item['active']) aria-current="page" @endif>
                                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                    <span class="truncate">{{ $item['label'] }}</span>

                                    @if (($item['badge'] ?? 0) > 0)
                                        <span class="numeric ml-auto rounded-full bg-accent-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                            {{ $item['badge'] }}
                                        </span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="shrink-0 border-t border-gray-700 p-4">
            <div class="flex items-center gap-3">
                <x-avatar :user="auth()->user()" class="h-10 w-10" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-gray-400">{{ auth()->user()->role->label() }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit"
                        class="focus-ring flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-brand-400 transition hover:bg-accent-400 hover:text-white">
                    <x-icon name="logout" class="h-5 w-5 shrink-0" />
                    Logout
                </button>
            </form>
        </div>
    </aside>

    {{-- ──────────────────────────────────────────────── Main column ───── --}}
    <div class="transition-all duration-300" :class="collapsed ? 'lg:pl-0' : 'lg:pl-64'">

        <header class="no-print sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-gray-700 bg-gray-900/90 px-4 backdrop-blur sm:px-6">
            {{-- Mobile: open the drawer. Desktop: collapse/expand, as legacy did. --}}
            <button type="button"
                    x-on:click="sidebarOpen = true"
                    class="btn-primary !p-2 lg:hidden"
                    aria-label="Open navigation">
                <x-icon name="menu" class="h-5 w-5" />
            </button>

            <button type="button"
                    x-on:click="toggleCollapsed()"
                    class="btn-primary hidden !p-2 lg:inline-flex"
                    :aria-label="collapsed ? 'Show navigation' : 'Hide navigation'">
                <x-icon name="menu" class="h-5 w-5" />
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="heading truncate text-lg">
                    @yield('heading', View::getSection('title', 'Dashboard'))
                </h1>
                @hasSection('subheading')
                    <p class="truncate text-xs text-gray-400">@yield('subheading')</p>
                @endif
            </div>

            @yield('actions')
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <x-flash />
            @yield('content')
        </main>
    </div>

    <x-toasts />
</div>
</body>
</html>
