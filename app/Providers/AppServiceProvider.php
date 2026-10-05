<?php

namespace App\Providers;

use App\Services\Printing\BrowserTicketPrinter;
use App\Services\Printing\TicketPrinter;
use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TicketPrinter::class, function () {
            return match (config('parking.printer')) {
                'browser' => new BrowserTicketPrinter,
                default => throw new InvalidArgumentException('Unknown printer driver: '.config('parking.printer')),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
