<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A deactivated account is signed out on its next request, not only at its next login.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && ! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated.']);
        }

        return $next($request);
    }
}
