<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The same figures as the office report, but only ever for the
 * signed-in owner's own resort.
 */
class StatisticsController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Booking::class);

        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first. Statistics appear once guests can book.');
        }

        $months = (int) $request->query('months');
        $months = in_array($months, [3, 6, 12, 24], true) ? $months : 12;

        $to = CarbonImmutable::today();
        $from = $to->startOfMonth()->subMonths($months - 1);

        return view('owner.statistics', [
            'resort' => $resort,
            'months' => $months,
            'from' => $from,
            'to' => $to,
            'summary' => $this->reports->summary($from, $to, $resort->id),
            'trend' => $this->reports->monthlyTrend($from, $to, $resort->id),
            'mix' => $this->reports->statusMix($from, $to, $resort->id),
            'origins' => $this->reports->visitorOrigins($from, $to, $resort->id),
            'rooms' => $resort->rooms()->active()->orderBy('name')->get(),
        ]);
    }
}
