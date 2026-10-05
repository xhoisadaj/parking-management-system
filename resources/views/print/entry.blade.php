@php($width = $setting->ticket_paper_width_mm)
<x-print-layout :title="'Entry ticket '.$session->ticket_code" :width="$width" :autoprint="$autoprint">
    <div class="center">
        @if ($setting->ticket_header)
            <div class="pre">{{ $setting->ticket_header }}</div>
        @endif
        <div class="title">{{ $setting->parking_name }}</div>
        @if ($setting->address)
            <div class="muted">{{ $setting->address }}</div>
        @endif
    </div>

    <div class="rule"></div>

    <div class="center title">ENTRY TICKET</div>

    <div class="row"><span>Entered</span><span>{{ \App\Support\Format::dateTime($session->entered_at) }}</span></div>
    <div class="row"><span>Vehicle</span><span>{{ $session->vehicleType->name }}</span></div>
    @if ($session->plate)
        <div class="row"><span>Plate</span><span>{{ $session->plate }}</span></div>
    @endif

    <div class="rule"></div>

    <div class="center">
        <div class="muted">Ticket number</div>
        <div class="big">{{ $session->ticket_code }}</div>
        <div class="barcode">{!! $barcode !!}</div>
        <div class="code">{{ $session->ticket_code }}</div>
    </div>

    @if ($setting->ticket_footer)
        <div class="rule"></div>
        <div class="center pre">{{ $setting->ticket_footer }}</div>
    @endif
</x-print-layout>
