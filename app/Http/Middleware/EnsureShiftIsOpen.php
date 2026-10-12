<?php

namespace App\Http\Middleware;

use App\Services\ShiftService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The operator must check in (open a shift) before using Entry, Checkout or Lost ticket.
 * No shift opens on its own; see ShiftScreen::startShift().
 */
class EnsureShiftIsOpen
{
    public function handle(Request $request, Closure $next)
    {
        if (app(ShiftService::class)->currentOpen(Auth::user()) === null) {
            return redirect()->route('operator.shift')->with('shift_required', true);
        }

        return $next($request);
    }
}
