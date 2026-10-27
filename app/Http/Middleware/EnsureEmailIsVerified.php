<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: "verified"
 *
 * Holds an account at the code screen until it has confirmed its email
 * address. Place it after "auth":  ->middleware(['auth', 'active', 'verified'])
 *
 * The code screen itself, and signing out, must NOT use this middleware,
 * or the person would have no way to move forward or leave.
 *
 * Accounts that existed before this step was added keep their
 * email_verified_at, so nobody is asked to confirm an address twice.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->email_verified_at === null) {
            if ($request->expectsJson()) {
                abort(403, 'Confirm your email address first.');
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
