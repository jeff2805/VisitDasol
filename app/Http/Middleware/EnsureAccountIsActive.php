<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: "active"
 *
 * Signs out any user whose account is no longer active (for example, an
 * owner the MTO deactivated a minute ago) on their very next request.
 * Always place it after "auth":  ->middleware(['auth', 'active'])
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            $message = $user->status->loginMessage();

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, $message);
            }

            return redirect()->route('login')->with('warning', $message);
        }

        return $next($request);
    }
}
