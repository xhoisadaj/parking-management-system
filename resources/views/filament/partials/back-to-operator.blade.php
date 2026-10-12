{{-- The single "back" link wasn't enough — this puts the actual operator tabs (same ones as
     resources/views/components/layouts/operator.blade.php) directly in Menaxhimi's topbar, so
     Hyrja/Arkëtimi/Biletë e humbur are each one click away from anywhere in this panel, not a
     click back to Turni im followed by a second click. --}}
<div class="flex items-center gap-1 self-center">
    @php($linkClass = 'rounded-lg px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-white/5')
    @can(\App\Support\Permissions::ISSUE_TICKET)
        <a href="{{ route('operator.entry') }}" class="{{ $linkClass }}">Hyrja</a>
    @endcan
    @can(\App\Support\Permissions::CHECKOUT)
        <a href="{{ route('operator.checkout') }}" class="{{ $linkClass }}">Arkëtimi</a>
        <a href="{{ route('operator.lost') }}" class="{{ $linkClass }}">Biletë e humbur</a>
    @endcan
    <a href="{{ route('operator.shift') }}" class="{{ $linkClass }}">Turni im</a>
    @can(\App\Support\Permissions::MANAGE_OWN_TICKETS)
        <a href="{{ route('operator.my-tickets') }}" class="{{ $linkClass }}">Biletat e mia</a>
    @endcan
</div>
