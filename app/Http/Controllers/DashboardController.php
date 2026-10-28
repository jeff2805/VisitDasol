<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /dashboard sends each user to the dashboard for their role.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route(match ($request->user()->role) {
            UserRole::Tourist => 'tourist.dashboard',
            UserRole::ResortOwner => 'owner.dashboard',
            UserRole::SuperAdmin => 'admin.dashboard',
        });
    }
}
