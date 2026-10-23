@php($width = $setting->ticket_paper_width_mm)
@php($data = $result->toArray())
<x-print-layout :title="'Kupon '.$session->ticket_code" :width="$width" :autoprint="$autoprint">
    <div class="center">
        <div class="title">{{ $setting->parking_name }}</div>
        @if ($setting->address)
            <div class="muted">{{ $setting->address }}</div>
        @endif
    </div>

    <div class="rule"></div>

    <div class="center title">KUPON DALJEJE</div>

    <div class="row"><span>Bileta</span><span>{{ $session->ticket_code }}</span></div>
    <div class="row"><span>Mjeti</span><span>{{ $session->vehicleType->name }}</span></div>
    @if ($session->plate)
        <div class="row"><span>Targa</span><span>{{ $session->plate }}</span></div>
    @endif
    <div class="row"><span>Hyrja</span><span>{{ \App\Support\Format::dateTime($session->entered_at) }}</span></div>
    <div class="row"><span>Dalja</span><span>{{ \App\Support\Format::dateTime($session->exited_at) }}</span></div>
    <div class="row"><span>Kohëzgjatja</span><span>{{ \App\Support\Format::duration($session->duration_minutes) }}</span></div>

    <div class="rule"></div>

    @php($adjusted = $session->adjusted_by !== null && abs((float) $session->final_price - (float) $session->calculated_price) > 0.004)

    @if ($adjusted)
        {{-- The price was changed at checkout. The line below is the only thing the customer
             sees of it, and it is written to match the total exactly — one line, one price,
             nothing that looks inconsistent. The original calculated price and the reason for
             the change are still recorded (audit log, statistics), just not printed here. --}}
        <div class="row"><span>Parkimi 1×{{ \App\Support\Format::amount($session->final_price, 2) }}</span><span>{{ \App\Support\Format::amount($session->final_price, 2) }}</span></div>
    @elseif ($result->graceApplied)
        <div class="row"><span>Brenda periudhës falas</span><span>{{ \App\Support\Format::money(0, '') }}</span></div>
    @else
        @foreach ($data['days'] as $day)
            @if ($day['long_stay_applied'])
                <div class="row">
                    <span>{{ \Carbon\Carbon::parse($day['date'])->locale('sq')->translatedFormat('d M') }} Tarifa ditore</span>
                    <span>{{ \App\Support\Format::amount($day['charged'], 2) }}</span>
                </div>
            @else
                @foreach ($day['lines'] as $line)
                    <div class="row">
                        <span>{{ \Carbon\Carbon::parse($day['date'])->locale('sq')->translatedFormat('d M') }} {{ $line['label'] }} {{ $line['units'] }}×{{ \App\Support\Format::amount($line['unit_price'], 2) }}</span>
                        <span>{{ \App\Support\Format::amount($line['amount'], 2) }}</span>
                    </div>
                @endforeach
                @if ($day['daily_cap_applied'])
                    <div class="row"><span>Maksimumi ditor</span><span>−{{ \App\Support\Format::amount($day['gross'] - $day['charged'], 2) }}</span></div>
                @endif
            @endif
        @endforeach
    @endif

    {{-- A lost ticket pays the stay above plus the fee on top. Once its price has been corrected
         ($adjusted), the single line above already shows the whole amount. --}}
    @if ($session->status === \App\Models\ParkingSession::STATUS_LOST && ! $adjusted)
        <div class="row"><span>Tarifa e biletës së humbur</span><span>+{{ \App\Support\Format::amount((float) $session->final_price - (float) $session->calculated_price, 2) }}</span></div>
    @endif

    <div class="rule"></div>

    <div class="row title"><span>TOTALI</span><span>{{ \App\Support\Format::money($session->final_price, $setting->currency) }}</span></div>

    @if ($session->amount_received !== null)
        <div class="row"><span>Marrë</span><span>{{ \App\Support\Format::amount((float) $session->amount_received, 2) }}</span></div>
        <div class="row big"><span>Kusuri</span><span>{{ \App\Support\Format::amount((float) $session->change_given, 2) }}</span></div>
    @endif

    <div class="rule"></div>
    <div class="row"><span>Operatori</span><span>{{ $session->exitUser?->name ?? '—' }}</span></div>

    @if ($setting->ticket_footer)
        <div class="rule"></div>
        <div class="center pre">{{ $setting->ticket_footer }}</div>
    @endif
</x-print-layout>
