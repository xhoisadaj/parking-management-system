<?php

namespace App\Providers;

use App\Services\Printing\BrowserTicketPrinter;
use App\Services\Printing\TicketPrinter;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TicketPrinter::class, function () {
            return match (config('parking.printer')) {
                'browser' => new BrowserTicketPrinter,
                default => throw new InvalidArgumentException('Drejtues i printimit i panjohur: '.config('parking.printer')),
            };
        });
    }

    public function boot(): void
    {
        // Month and day names in Albanian (e.g. "5 Tetor 2026").
        Carbon::setLocale(config('app.locale'));
    }
}
