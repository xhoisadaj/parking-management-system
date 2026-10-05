@php($width = $setting->ticket_paper_width_mm)
@php($data = $result->toArray())
<x-print-layout :title="'Receipt '.$session->ticket_code" :width="$width" :autoprint="$autoprint">
    <div class="center">
        <div class="title">{{ $setting->parking_name }}</div>
        @if ($setting->address)
            <div class="muted">{{ $setting->address }}</div>
        @endif
    </div>

    <div class="rule"></div>

    <div class="center title">EXIT RECEIPT</div>

    <div class="row"><span>Ticket</span><span>{{ $session->ticket_code }}</span></div>
    <div class="row"><span>Vehicle</span><span>{{ $session->vehicleType->name }}</span></div>
    @if ($session->plate)
        <div class="row"><span>Plate</span><span>{{ $session->plate }}</span></div>
    @endif
    <div class="row"><span>Entry</span><span>{{ \App\Support\Format::dateTime($session->entered_at) }}</span></div>
    <div class="row"><span>Exit</span><span>{{ \App\Support\Format::dateTime($session->exited_at) }}</span></div>
    <div class="row"><span>Duration</span><span>{{ \App\Support\Format::duration($session->duration_minutes) }}</span></div>

    <div class="rule"></div>

    @if ($session->status === \App\Models\ParkingSession::STATUS_LOST)
        <div class="row"><span>Lost ticket fee</span><span>{{ \App\Support\Format::money($session->final_price, '') }}</span></div>
    @elseif ($result->graceApplied)
        <div class="row"><span>Within grace period</span><span>{{ \App\Support\Format::money(0, '') }}</span></div>
    @else
        @foreach ($data['days'] as $day)
            @foreach ($day['lines'] as $line)
                <div class="row">
                    <span>{{ \Carbon\Carbon::parse($day['date'])->format('d M') }} {{ $line['label'] }} {{ $line['units'] }}×{{ number_format($line['unit_price'], 2) }}</span>
                    <span>{{ number_format($line['amount'], 2) }}</span>
                </div>
            @endforeach
            @if ($day['daily_cap_applied'])
                <div class="row"><span>Daily maximum</span><span>−{{ number_format($day['gross'] - $day['charged'], 2) }}</span></div>
            @endif
        @endforeach
    @endif

    <div class="rule"></div>

    <div class="row title"><span>TOTAL</span><span>{{ \App\Support\Format::money($session->final_price, $setting->currency) }}</span></div>

    @if ($session->adjusted_by && (float) $session->final_price !== (float) $session->calculated_price)
        <div class="row muted"><span>Calculated</span><span>{{ number_format((float) $session->calculated_price, 2) }}</span></div>
        <div class="pre muted">Adjusted: {{ $session->adjustment_reason }}</div>
    @endif

    <div class="rule"></div>
    <div class="row"><span>Operator</span><span>{{ $session->exitUser?->name ?? '—' }}</span></div>

    @if ($setting->ticket_footer)
        <div class="rule"></div>
        <div class="center pre">{{ $setting->ticket_footer }}</div>
    @endif
</x-print-layout>
