<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Destination;
use App\Models\Resort;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * MTO overview.
 *
 * Everything on this page is counted by the database with aggregate queries,
 * never by loading rows into PHP, so the dashboard keeps the same number of
 * queries however much data the office accumulates (see QueryBudgetTest).
 *
 * The two charts read one calendar year, chosen with ?year=. Both use the
 * check-in date rather than the date a reservation was made, so "2026" means
 * the stays that happen in 2026, which is what the office plans around.
 */
class DashboardController extends Controller
{
    /** Reservations that stand for real stays. */
    private const REAL = [BookingStatus::Confirmed, BookingStatus::Completed];

    /** Slice colours for the status doughnut and its legend. */
    private const STATUS_COLORS = [
        'confirmed' => '#2E9E6B',
        'pending' => '#F2B134',
        'cancelled' => '#D9534F',
        'completed' => '#3B82C4',
        'rejected' => '#9B2C1F',
    ];

    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $years = $this->years();
        $year = $this->chosenYear($request, $years);

        return view('admin.dashboard', [
            'pendingOwnerCount' => User::pendingOwners()->count(),
            'pendingResortCount' => Resort::pending()->count(),
            'metrics' => $this->metrics(),
            'year' => $year,
            'years' => $years,
            'arrivals' => $this->arrivals($year),
            'bookingMix' => $this->bookingMix($year),
            'recentBookings' => $this->recentBookings(),
            'topResorts' => $this->topResorts(),
        ]);
    }

    /**
     * The four headline cards, in the order they are shown.
     *
     * @return list<array<string, mixed>>
     */
    private function metrics(): array
    {
        $cards = [
            [
                'label' => 'Total resorts',
                'icon' => 'building',
                'tone' => 'lagoon',
                'href' => route('admin.resorts.index'),
                'query' => fn (): Builder => Resort::query(),
            ],
            [
                'label' => 'Total users',
                'icon' => 'people-fill',
                'tone' => 'sky',
                'href' => route('admin.users.index'),
                'query' => fn (): Builder => User::query(),
            ],
            [
                'label' => 'Total bookings',
                'icon' => 'journal-check',
                'tone' => 'mango',
                'href' => route('admin.bookings.index'),
                'query' => fn (): Builder => Booking::query(),
            ],
            [
                'label' => 'Destinations',
                'icon' => 'geo-alt-fill',
                'tone' => 'mangrove',
                'href' => route('admin.destinations.index'),
                'query' => fn (): Builder => Destination::query(),
            ],
        ];

        return array_map(function (array $card): array {
            $counts = $this->countAndChange($card['query']);
            unset($card['query']);

            return $card + $counts;
        }, $cards);
    }

    /**
     * A running total, plus how this month compares with last month.
     *
     * A month with no history behind it has no percentage to report, so
     * "change" is null and the card falls back to the plain number of new
     * records. Without that, a first month would always claim "+100%".
     *
     * @param  Closure(): Builder  $query
     * @return array{total: int, added: int, change: int|null}
     */
    private function countAndChange(Closure $query): array
    {
        $thisMonth = CarbonImmutable::today()->startOfMonth();
        $lastMonth = $thisMonth->subMonth();

        $addedIn = fn (CarbonImmutable $month): int => $query()
            ->whereBetween('created_at', [$month, $month->endOfMonth()])
            ->count();

        $current = $addedIn($thisMonth);
        $previous = $addedIn($lastMonth);

        return [
            'total' => $query()->count(),
            'added' => $current,
            'change' => $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null,
        ];
    }

    /**
     * Guests arriving in each month of the year, from reservations that stand.
     *
     * @return array{labels: list<string>, data: list<int>, total: int}
     */
    private function arrivals(int $year): array
    {
        $guests = Booking::query()
            ->whereIn('status', array_column(self::REAL, 'value'))
            ->whereYear('check_in_date', $year)
            ->selectRaw('MONTH(check_in_date) as month, SUM(guests) as guests')
            ->groupBy('month')
            ->pluck('guests', 'month');

        $labels = [];
        $data = [];

        for ($month = 1; $month <= 12; $month++) {
            $labels[] = CarbonImmutable::create($year, $month, 1)->format('M');
            $data[] = (int) ($guests[$month] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data, 'total' => array_sum($data)];
    }

    /**
     * How the year's reservations are split across the statuses.
     *
     * Every status is listed, including the ones nobody used, so the legend
     * does not change shape from one year to the next.
     *
     * @return array{total: int, rows: list<array{value: string, label: string, count: int, share: float, color: string}>}
     */
    private function bookingMix(int $year): array
    {
        $counts = Booking::query()
            ->whereYear('check_in_date', $year)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();

        $rows = array_map(function (BookingStatus $status) use ($counts, $total): array {
            $count = (int) ($counts[$status->value] ?? 0);

            return [
                'value' => $status->value,
                // "Awaiting confirmation" is too long for a legend beside a chart.
                'label' => $status === BookingStatus::Pending ? 'Pending' : $status->label(),
                'count' => $count,
                'share' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'color' => self::STATUS_COLORS[$status->value],
            ];
        }, BookingStatus::cases());

        return ['total' => $total, 'rows' => $rows];
    }

    /** The five most recent reservations, whatever their status. */
    private function recentBookings(): EloquentCollection
    {
        return Booking::query()
            ->with(['tourist', 'resort'])
            ->latest()
            ->limit(5)
            ->get();
    }

    /** The five busiest resorts by reservations that stand. */
    private function topResorts(): EloquentCollection
    {
        return Resort::query()
            ->withCount(['bookings as bookings_count' => fn (Builder $query) => $query
                ->whereIn('status', array_column(self::REAL, 'value'))])
            ->orderByDesc('bookings_count')
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->reject(fn (Resort $resort): bool => $resort->bookings_count === 0)
            ->values();
    }

    /**
     * Years the office can choose between: every year that has a stay in it,
     * and this year, so the picker is never empty on a fresh install.
     *
     * @return Collection<int, int>
     */
    private function years(): Collection
    {
        return Booking::query()
            ->selectRaw('YEAR(check_in_date) as year')
            ->groupBy('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->push(CarbonImmutable::today()->year)
            ->unique()
            ->sortDesc()
            ->values();
    }

    /**
     * The year to chart. An unknown or missing ?year= falls back to this year,
     * or to the most recent year with stays in it when this year has none.
     *
     * @param  Collection<int, int>  $years
     */
    private function chosenYear(Request $request, Collection $years): int
    {
        $asked = $request->integer('year');

        if ($years->contains($asked)) {
            return $asked;
        }

        $thisYear = CarbonImmutable::today()->year;

        return $years->contains($thisYear) ? $thisYear : (int) $years->first();
    }
}
