<!doctype html>
<html lang="sq" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'Operatori' }} · {{ \App\Models\Setting::current()->parking_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full text-slate-900 antialiased">
    <header class="sticky top-0 z-20 border-b-2 border-amber-500 bg-slate-900 text-white shadow">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
            <div class="min-w-0">
                <p class="truncate text-base font-semibold">{{ \App\Models\Setting::current()->parking_name }}</p>
                <p class="truncate text-xs text-slate-300">{{ auth()->user()->name }}</p>
            </div>
            <form method="POST" action="{{ route('operator.logout') }}">
                @csrf
                <button type="submit" class="min-h-[44px] min-w-[44px] rounded-lg px-3 text-sm font-medium text-slate-200 hover:bg-slate-800">
                    Dil
                </button>
            </form>
        </div>

        @php
            // Entry, Checkout and Lost ticket are greyed out until the operator has checked in (see
            // EnsureShiftIsOpen). The links still work if clicked — the middleware sends them to
            // Turni im with an explanation — this is just so the nav doesn't look clickable first.
            $shiftOpen = app(\App\Services\ShiftService::class)->currentOpen(auth()->user()) !== null;
            $navLinkBase = 'inline-flex w-full min-h-[44px] items-center justify-center whitespace-nowrap rounded-lg px-4 text-sm font-semibold';
            // Menaxhimi (the Manager-facing admin panel) only shows up for someone who actually
            // holds at least one page's permission in it — same check as User::canAccessPanel()
            // for that panel, so the link never leads somewhere they'd just get refused from.
            $canOpenManagement = collect(\App\Support\Permissions::adminAreaPermissions())
                ->contains(fn (string $permission) => auth()->user()->can($permission));
        @endphp
        <nav class="border-t border-slate-800" aria-label="Seksionet e operatorit">
            <ul class="mx-auto grid max-w-5xl grid-cols-2 gap-1 px-2 py-1 sm:flex sm:overflow-x-auto">
                @can(\App\Support\Permissions::ISSUE_TICKET)
                    <li>
                        <a href="{{ route('operator.entry') }}"
                           @unless ($shiftOpen) aria-disabled="true" title="Filloni turnin për ta përdorur" @endunless
                           class="{{ $navLinkBase }} {{ request()->routeIs('operator.entry') ? 'bg-amber-500 text-slate-900' : ($shiftOpen ? 'text-slate-200 hover:bg-slate-800' : 'text-slate-500') }}">
                            Hyrja
                        </a>
                    </li>
                @endcan
                @can(\App\Support\Permissions::CHECKOUT)
                    <li>
                        <a href="{{ route('operator.checkout') }}"
                           @unless ($shiftOpen) aria-disabled="true" title="Filloni turnin për ta përdorur" @endunless
                           class="{{ $navLinkBase }} {{ request()->routeIs('operator.checkout') ? 'bg-amber-500 text-slate-900' : ($shiftOpen ? 'text-slate-200 hover:bg-slate-800' : 'text-slate-500') }}">
                            Arkëtimi
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('operator.lost') }}"
                           @unless ($shiftOpen) aria-disabled="true" title="Filloni turnin për ta përdorur" @endunless
                           class="{{ $navLinkBase }} {{ request()->routeIs('operator.lost') ? 'bg-amber-500 text-slate-900' : ($shiftOpen ? 'text-slate-200 hover:bg-slate-800' : 'text-slate-500') }}">
                            Biletë e humbur
                        </a>
                    </li>
                @endcan
                <li>
                    <a href="{{ route('operator.shift') }}"
                       class="{{ $navLinkBase }} {{ request()->routeIs('operator.shift') ? 'bg-amber-500 text-slate-900' : 'text-slate-200 hover:bg-slate-800' }} {{ $shiftOpen ? '' : 'ring-1 ring-amber-400' }}">
                        Turni im
                    </a>
                </li>
                @can(\App\Support\Permissions::MANAGE_OWN_TICKETS)
                    <li>
                        <a href="{{ route('operator.my-tickets') }}"
                           class="{{ $navLinkBase }} {{ request()->routeIs('operator.my-tickets') ? 'bg-amber-500 text-slate-900' : 'text-slate-200 hover:bg-slate-800' }}">
                            Biletat e mia
                        </a>
                    </li>
                @endcan
                @if ($canOpenManagement)
                    <li>
                        {{-- Amber text (not the plain slate of the other tabs) on purpose: this is
                             the one link that leaves this app for the Menaxhimi panel, and it
                             shares that panel's own amber accent so the jump doesn't feel random. --}}
                        <a href="{{ url('/menaxhimi') }}"
                           class="{{ $navLinkBase }} text-amber-400 hover:bg-slate-800 hover:text-amber-300">
                            Menaxhimi
                        </a>
                    </li>
                @endif
            </ul>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 py-4 pb-10">
        {{ $slot }}
    </main>

    {{-- Fshehur: përdoret për printimin e biletave pa e lënë ekranin. --}}
    <iframe id="print-frame" title="Printimi i biletës" class="absolute h-0 w-0 border-0" aria-hidden="true" tabindex="-1"></iframe>

    <script>
        window.addEventListener('print-ticket', (event) => {
            const url = event.detail.url;
            if (!url) return;
            const frame = document.getElementById('print-frame');
            frame.src = url + (url.includes('?') ? '&' : '?') + 'print=1';
        });

        // Fushat me çmime/shuma pranojnë vetëm shifra dhe një ndarës dhjetor — asnjë shkronjë.
        // Dëgjuesi është në fazën e kapjes (argumenti i tretë "true"), kështu që pastron vlerën
        // PARA se Livewire ta lexojë atë për wire:model, jo pas.
        document.addEventListener('input', (event) => {
            const field = event.target;
            if (!field.matches || !field.matches('[data-numeric-input]')) return;

            const original = field.value;
            let seenSeparator = false;
            const cleaned = Array.from(original).filter((char) => {
                if (char >= '0' && char <= '9') return true;
                if ((char === '.' || char === ',') && !seenSeparator) {
                    seenSeparator = true;
                    return true;
                }
                return false;
            }).join('');

            if (cleaned !== original) {
                const position = field.selectionStart - (original.length - cleaned.length);
                field.value = cleaned;
                field.setSelectionRange(position, position);
            }
        }, true);

        // Kliko mbi një kartë [data-copy-code] për të kopjuar kodin e biletës. Një klikim mbi një
        // buton/link brenda kartës (si "Ndrysho") nuk kopjon — vetëm vepron normalisht.
        // navigator.clipboard kërkon https; ky sajt shpesh është http, kështu që ka gjithmonë një
        // rrugë rezervë me një <textarea> të fshehur.
        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }

            return new Promise((resolve, reject) => {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                try {
                    document.execCommand('copy');
                    resolve();
                } catch (error) {
                    reject(error);
                } finally {
                    document.body.removeChild(textarea);
                }
            });
        }

        function handleCopyCodeClick(event) {
            const card = event.target.closest('[data-copy-code]');
            if (!card || event.target.closest('button, a')) return;

            copyText(card.dataset.copyCode).then(() => {
                const label = card.querySelector('[data-copy-label]');
                if (!label) return;
                const original = label.textContent;
                label.textContent = 'U kopjua!';
                card.classList.add('bg-emerald-50');
                setTimeout(() => {
                    label.textContent = original;
                    card.classList.remove('bg-emerald-50');
                }, 1200);
            }).catch(() => {});
        }

        document.addEventListener('click', handleCopyCodeClick);
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            if (!event.target.closest || !event.target.matches('[data-copy-code]')) return;
            event.preventDefault();
            handleCopyCodeClick(event);
        });

        // Fokusi mbetet te fusha e biletës, që skaneri USB të shkruajë aty.
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
