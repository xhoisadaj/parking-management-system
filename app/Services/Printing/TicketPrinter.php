<?php

namespace App\Services\Printing;

use App\Models\ParkingSession;

/**
 * Printing boundary. The operator screens only talk to this interface.
 * BrowserTicketPrinter is the current implementation. An ESC/POS driver can be added
 * later and bound in AppServiceProvider without touching the screens.
 */
interface TicketPrinter
{
    public function printEntry(ParkingSession $session): PrintJob;

    public function printReceipt(ParkingSession $session): PrintJob;
}
