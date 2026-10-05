<?php

namespace App\Http\Controllers;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Services\PriceCalculator;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGeneratorSVG;

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
            'barcode' => $this->barcode($session->ticket_code),
            'autoprint' => request()->boolean('print'),
        ]);
    }

    public function receipt(ParkingSession $session, PriceCalculator $calculator): View|Response
    {
        Gate::authorize(Permissions::CHECKOUT);

        if (! in_array($session->status, [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST], true)) {
            abort(409, 'This ticket has not been closed yet.');
        }

        $result = $calculator->calculate($session->tariff_snapshot, $session->entered_at, $session->exited_at);

        return view('print.receipt', [
            'session' => $session->load(['vehicleType', 'exitUser']),
            'setting' => Setting::current(),
            'result' => $result,
            'autoprint' => request()->boolean('print'),
        ]);
    }

    private function barcode(string $code): string
    {
        return (new BarcodeGeneratorSVG)->getBarcode($code, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 60);
    }
}
