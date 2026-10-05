<!doctype html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'Operator' }} · {{ \App\Models\Setting::current()->parking_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full text-slate-900 antialiased">
    <header class="sticky top-0 z-20 bg-slate-900 text-white shadow">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
            <div class="min-w-0">
                <p class="truncate text-base font-semibold">{{ \App\Models\Setting::current()->parking_name }}</p>
                <p class="truncate text-xs text-slate-300">{{ auth()->user()->name }}</p>
            </div>
            <form method="POST" action="{{ route('operator.logout') }}">
                @csrf
                <button type="submit" class="min-h-[44px] min-w-[44px] rounded-lg px-3 text-sm font-medium text-slate-200 hover:bg-slate-800">
                    Sign out
                </button>
            </form>
        </div>

        <nav class="border-t border-slate-800" aria-label="Operator sections">
            <ul class="mx-auto grid max-w-5xl grid-cols-2 gap-1 px-2 py-1 sm:flex sm:overflow-x-auto">
                @can(\App\Support\Permissions::ISSUE_TICKET)
                    <li><a href="{{ route('operator.entry') }}" class="inline-flex w-full min-h-[44px] items-center justify-center whitespace-nowrap rounded-lg px-4 text-sm font-semibold {{ request()->routeIs('operator.entry') ? 'bg-white text-slate-900' : 'text-slate-200 hover:bg-slate-800' }}">Entry</a></li>
                @endcan
                @can(\App\Support\Permissions::CHECKOUT)
                    <li><a href="{{ route('operator.checkout') }}" class="inline-flex w-full min-h-[44px] items-center justify-center whitespace-nowrap rounded-lg px-4 text-sm font-semibold {{ request()->routeIs('operator.checkout') ? 'bg-white text-slate-900' : 'text-slate-200 hover:bg-slate-800' }}">Checkout</a></li>
                    <li><a href="{{ route('operator.lost') }}" class="inline-flex w-full min-h-[44px] items-center justify-center whitespace-nowrap rounded-lg px-4 text-sm font-semibold {{ request()->routeIs('operator.lost') ? 'bg-white text-slate-900' : 'text-slate-200 hover:bg-slate-800' }}">Lost ticket</a></li>
                @endcan
                <li><a href="{{ route('operator.shift') }}" class="inline-flex w-full min-h-[44px] items-center justify-center whitespace-nowrap rounded-lg px-4 text-sm font-semibold {{ request()->routeIs('operator.shift') ? 'bg-white text-slate-900' : 'text-slate-200 hover:bg-slate-800' }}">My shift</a></li>
            </ul>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 py-4 pb-10">
        {{ $slot }}
    </main>

    {{-- Hidden frame used to print tickets without leaving the screen or being blocked as a popup. --}}
    <iframe id="print-frame" title="Ticket print" class="absolute h-0 w-0 border-0" aria-hidden="true" tabindex="-1"></iframe>

    <script>
        window.addEventListener('print-ticket', (event) => {
            const url = event.detail.url;
            if (!url) return;
            const frame = document.getElementById('print-frame');
            frame.src = url + (url.includes('?') ? '&' : '?') + 'print=1';
        });

        // Keep the scanner target focused so a USB scanner's keystrokes land in the ticket field.
        document.addEventListener('livewire:init', () => {
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    const target = document.querySelector('[data-autofocus]');
                    const active = document.activeElement;
                    const typing = active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName);
                    if (target && !typing) target.focus();
                });
            });
        });
    </script>

    @livewireScripts
</body>
</html>
