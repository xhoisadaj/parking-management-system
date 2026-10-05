<?php

namespace App\Services\Printing;

use App\Models\ParkingSession;

class BrowserTicketPrinter implements TicketPrinter
{
    public function printEntry(ParkingSession $session): PrintJob
    {
        return PrintJob::browser(route('print.entry', $session));
    }

    public function printReceipt(ParkingSession $session): PrintJob
    {
        return PrintJob::browser(route('print.receipt', $session));
    }
}
