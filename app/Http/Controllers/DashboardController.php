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
            UserRole::BarangayOfficial => 'barangay.dashboard',

            /*
             * Fee staff land on the screen they were hired for rather
             * than a dashboard of their own: a collector opens the
             * gate's collection screen, a cashier opens the handovers
             * waiting to be counted. Neither has anything else to do
             * here, and a near-empty dashboard in between is a click
             * they make every shift for nothing.
             */
            UserRole::FeeCollector => 'environmental-fee.index',
            UserRole::Cashier => 'admin.environmental-fee.remittances.index',

            UserRole::SuperAdmin => 'admin.dashboard',
        });
    }
}
