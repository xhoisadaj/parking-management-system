<?php

use App\Http\Controllers\Operator\OperatorAuthController;
use App\Http\Controllers\StatisticsExportController;
use App\Http\Controllers\PrintController;
use App\Livewire\Operator\CheckoutScreen;
use App\Livewire\Operator\EntryScreen;
use App\Livewire\Operator\LostTicketScreen;
use App\Livewire\Operator\MyTicketsScreen;
use App\Livewire\Operator\ShiftScreen;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/operator');

// Operator sign-in. The named "login" route is where unauthenticated requests are sent.
Route::middleware('guest')->group(function () {
    Route::get('/operator/login', [OperatorAuthController::class, 'create'])->name('login');
    Route::post('/operator/login', [OperatorAuthController::class, 'store']);
});

Route::middleware(['auth', 'active'])->prefix('operator')->name('operator.')->group(function () {
    Route::post('/logout', [OperatorAuthController::class, 'destroy'])->name('logout');

    Route::view('/', 'operator.home')->name('home');

    Route::get('/entry', EntryScreen::class)->middleware(['can:'.Permissions::ISSUE_TICKET, 'shift.open'])->name('entry');
    Route::get('/checkout', CheckoutScreen::class)->middleware(['can:'.Permissions::CHECKOUT, 'shift.open'])->name('checkout');
    Route::get('/lost', LostTicketScreen::class)->middleware(['can:'.Permissions::CHECKOUT, 'shift.open'])->name('lost');
    Route::get('/shift', ShiftScreen::class)->name('shift');
    Route::get('/my-tickets', MyTicketsScreen::class)->middleware('can:'.Permissions::MANAGE_OWN_TICKETS)->name('my-tickets');
});

// Ticket print pages. Opened in the operator's hidden print frame.
Route::middleware(['auth', 'active'])->prefix('print')->group(function () {
    Route::get('/entry/{session}', [PrintController::class, 'entry'])->name('print.entry');
    Route::get('/receipt/{session}', [PrintController::class, 'receipt'])->name('print.receipt');
});

// Statistics export, for the period chosen on the dashboard. Permission is checked in the controller.
Route::middleware(['auth', 'active'])->get('/statistics/export', StatisticsExportController::class)->name('statistics.export');
