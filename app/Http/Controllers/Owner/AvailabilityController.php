<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A month at a glance: how many units of each room type are still free
 * on each night. The numbers are calculated, never stored.
 */
class AvailabilityController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability): View|RedirectResponse
    {
        $this->authorize('viewAny', Booking::class);

        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first, then add rooms to see availability.');
        }

        $month = $this->month($request->query('month'));

        return view('owner.availability', [
            'resort' => $resort,
            'month' => $month,
            'previousMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->format('Y-m'),
            'rooms' => $resort->rooms()->active()->orderBy('name')->get(),
            'calendar' => $availability->calendarForResort($resort, $month),
        ]);
    }

    private function month(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
            } catch (\Throwable) {
                // fall through to this month
            }
        }

        return CarbonImmutable::today()->startOfMonth();
    }
}
