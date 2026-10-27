<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: "role"
 *
 * Usage: ->middleware(['auth', 'active', 'role:resort_owner'])
 *        ->middleware('role:tourist,super_admin')   (any of several roles)
 *
 * Responds 403 when the signed-in user has none of the listed roles.
 * A misspelled role name throws an error, so mistakes surface immediately.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);
        $user = $request->user();

        if ($user === null || ! $user->hasRole(...$allowed)) {
            abort(403);
        }

        return $next($request);
    }
}
