<?php

namespace App\Http\Controllers;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Services\PriceCalculator;
use App\Support\Permissions;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Print-optimised ticket pages. The browser opens them in a hidden frame and prints them.
 */
class PrintController extends Controller
{
    public function entry(ParkingSession $session): View
    {
        Gate::authorize(Permissions::ISSUE_TICKET);

        return view('print.entry', [
            'session' => $session->load('vehicleType'),
            'setting' => Setting::current(),
            'qr' => $this->qrCode($session->ticket_code),
            'autoprint' => request()->boolean('print'),
        ]);
    }

    public function receipt(ParkingSession $session, PriceCalculator $calculator): View|Response
    {
        Gate::authorize(Permissions::CHECKOUT);

        if (! in_array($session->status, [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST], true)) {
            abort(409, 'Kjo biletë nuk është mbyllur ende.');
        }

        $result = $calculator->calculate($session->tariff_snapshot, $session->entered_at, $session->exited_at);

        return view('print.receipt', [
            'session' => $session->load(['vehicleType', 'exitUser']),
            'setting' => Setting::current(),
            'result' => $result,
            'autoprint' => request()->boolean('print'),
        ]);
    }

    /**
     * QR instead of a 1D barcode: a 6-character ticket code only needs the smallest QR version
     * (21x21 modules), so even printed small each module is several times wider than the
     * equivalent Code128 bar would be on the same paper — and QR's built-in error correction
     * tolerates print imperfections (faint spots, slight misalignment) a 1D barcode can't.
     */
    private function qrCode(string $code): string
    {
        $qrCode = new QrCode(
            data: $code,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            margin: 0, // the ticket's own CSS padding is the quiet zone, same as the barcode was
        );

        return (new SvgWriter())->write($qrCode)->getString();
    }
}
