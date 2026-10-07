<x-layouts.operator title="Kryefaqja">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @can(\App\Support\Permissions::ISSUE_TICKET)
            <a href="{{ route('operator.entry') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
                <span class="text-lg font-semibold">Hyrja</span>
                <span class="text-sm text-slate-500">Lëshoni biletë për makinë, furgon ose motor</span>
            </a>
        @endcan
        @can(\App\Support\Permissions::CHECKOUT)
            <a href="{{ route('operator.checkout') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
                <span class="text-lg font-semibold">Arkëtimi</span>
                <span class="text-sm text-slate-500">Skanoni ose shkruani biletën dhe merrni pagesën</span>
            </a>
            <a href="{{ route('operator.lost') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
                <span class="text-lg font-semibold">Biletë e humbur</span>
                <span class="text-sm text-slate-500">Gjeni makinën dhe aplikoni tarifën e biletës së humbur</span>
            </a>
        @endcan
        <a href="{{ route('operator.shift') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
            <span class="text-lg font-semibold">Turni im</span>
            <span class="text-sm text-slate-500">Totalet tuaja dhe mbyllja e turnit</span>
        </a>
    </div>
</x-layouts.operator>
