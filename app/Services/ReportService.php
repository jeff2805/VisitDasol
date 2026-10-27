<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ResortStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VisitorOrigin;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures for the tourism office reports and the owner's statistics page.
 *
 * Everything is counted by the database with aggregate queries, never by
 * loading rows into PHP, so the pages stay fast as the data grows.
 *
 * Two words used throughout:
 *   "stay dates"  - the nights a guest is booked in (check-in to check-out)
 *   "request date"- when the reservation was made
 * Reports can be read either way, because they answer different questions.
 *
 * "Completed" and "confirmed" reservations are the ones that count as real
 * business; cancelled and declined ones are counted separately so the
 * office can see how often bookings fall through.
 */
class ReportService
{
    /** Reservations that represent actual stays. */
    private const REAL = [BookingStatus::Confirmed, BookingStatus::Completed];

    /**
     * Base query for one reporting period.
     */
    public function bookingsIn(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay'): Builder
    {
        return Booking::query()
            ->when($resortId, fn (Builder $query) => $query->where('resort_id', $resortId))
            ->when($basis === 'requested',
                // Qualified with the table name: topResorts() and
                // visitorOrigins() join resorts and users, which both have a
                // created_at of their own, and an unqualified one makes the
                // whole query ambiguous and the report page fail.
                fn (Builder $query) => $query->whereBetween('bookings.created_at', [$from->startOfDay(), $to->endOfDay()]),
                // Stays that touch the period at all.
                fn (Builder $query) => $query
                    ->where('check_in_date', '<=', $to->toDateString())
                    ->where('check_out_date', '>', $from->toDateString()),
            );
    }

    /**
     * Headline numbers for the period.
     *
     * @return array<string, int|string>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay'): array
    {
        $base = fn () => $this->bookingsIn($from, $to, $resortId, $basis);

        $real = (clone $base())->whereIn('status', array_column(self::REAL, 'value'));

        $decided = (clone $base())->whereIn('status', [
            BookingStatus::Confirmed->value, BookingStatus::Completed->value,
            BookingStatus::Cancelled->value, BookingStatus::Rejected->value,
        ])->count();

        $cancelled = (clone $base())->whereIn('status', [
            BookingStatus::Cancelled->value, BookingStatus::Rejected->value,
        ])->count();

        return [
            'reservations' => (clone $base())->count(),
            'confirmed_or_completed' => (clone $real)->count(),
            'pending' => (clone $base())->withStatus(BookingStatus::Pending)->count(),
            'cancelled_or_declined' => $cancelled,
            'guests' => (int) (clone $real)->sum('guests'),
            'room_nights' => $this->roomNights($from, $to, $resortId),
            'value' => (string) ((clone $real)->sum('total_amount') ?: '0'),
            'cancellation_rate' => $decided > 0 ? (int) round($cancelled / $decided * 100) : 0,
            'occupancy_rate' => $this->occupancyRate($from, $to, $resortId),
        ];
    }

    /**
     * Reservations, guests, and value per month.
     *
     * @return Collection<int, array{month: string, label: string, reservations: int, guests: int, value: float}>
     */
    public function monthlyTrend(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay'): Collection
    {
        // Qualified for the same reason as in bookingsIn(): this grouping is
        // safe today because nothing here joins, but an unqualified created_at
        // is a trap for whoever adds a join later.
        $column = $basis === 'requested' ? 'bookings.created_at' : 'bookings.check_in_date';

        $rows = $this->bookingsIn($from, $to, $resortId, $basis)
            ->selectRaw("DATE_FORMAT({$column}, '%Y-%m') as month")
            ->selectRaw('count(*) as reservations')
            ->groupBy('month')
            ->pluck('reservations', 'month');

        $guests = $this->bookingsIn($from, $to, $resortId, $basis)
            ->selectRaw("DATE_FORMAT({$column}, '%Y-%m') as month")
            ->selectRaw('sum(guests) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $value = $this->bookingsIn($from, $to, $resortId, $basis)
            ->whereIn('status', array_column(self::REAL, 'value'))
            ->selectRaw("DATE_FORMAT({$column}, '%Y-%m') as month")
            ->selectRaw('sum(total_amount) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        // Months with nothing in them still belong on the chart.
        $months = collect();
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');

            $months->push([
                'month' => $key,
                'label' => $month->format('M Y'),
                'reservations' => (int) ($rows[$key] ?? 0),
                'guests' => (int) ($guests[$key] ?? 0),
                'value' => (float) ($value[$key] ?? 0),
            ]);
        }

        return $months;
    }

    /**
     * How many reservations are in each status.
     *
     * @return array<string, int>
     */
    public function statusMix(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay'): array
    {
        $counts = $this->bookingsIn($from, $to, $resortId, $basis)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $mix = [];

        foreach (BookingStatus::cases() as $case) {
            $mix[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $mix;
    }

    /**
     * Busiest resorts by real stays.
     *
     * @return Collection<int, array{name: string, reservations: int, guests: int, value: float}>
     */
    public function topResorts(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10, string $basis = 'stay'): Collection
    {
        return $this->bookingsIn($from, $to, null, $basis)
            ->whereIn('bookings.status', array_column(self::REAL, 'value'))
            ->join('resorts', 'resorts.id', '=', 'bookings.resort_id')
            ->selectRaw('resorts.name as name')
            ->selectRaw('count(*) as reservations')
            ->selectRaw('sum(bookings.guests) as guests')
            ->selectRaw('sum(bookings.total_amount) as value')
            ->groupBy('resorts.id', 'resorts.name')
            ->orderByDesc('reservations')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'reservations' => (int) $row->reservations,
                'guests' => (int) $row->guests,
                'value' => (float) $row->value,
            ]);
    }

    /**
     * Where visitors travel from, from their account details.
     *
     * @return Collection<int, array{place: string, guests: int}>
     */
    public function visitorOrigins(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, int $limit = 8): Collection
    {
        return $this->bookingsIn($from, $to, $resortId)
            ->whereIn('bookings.status', array_column(self::REAL, 'value'))
            ->join('users', 'users.id', '=', 'bookings.user_id')
            ->whereNotNull('users.home_city')
            ->selectRaw('users.home_city as place')
            ->selectRaw('sum(bookings.guests) as guests')
            ->groupBy('users.home_city')
            ->orderByDesc('guests')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['place' => $row->place, 'guests' => (int) $row->guests]);
    }

    /**
     * Accounts and listings as they stand today.
     *
     * @return array<string, int>
     */
    public function systemTotals(): array
    {
        return [
            'tourists' => User::query()->tourists()->count(),
            'active_tourists' => User::query()->tourists()->active()->count(),
            'owners' => User::query()->resortOwners()->count(),
            'active_owners' => User::query()->resortOwners()->active()->count(),
            'pending_owners' => User::query()->pendingOwners()->count(),
            'resorts' => Resort::query()->count(),
            'active_resorts' => Resort::query()->withStatus(ResortStatus::Active)->count(),
            'pending_resorts' => Resort::query()->pending()->count(),
            'public_resorts' => Resort::query()->publiclyVisible()->count(),
            'rooms' => Room::query()->count(),
            'bookable_rooms' => Room::query()->bookable()->count(),
            'units' => (int) Room::query()->active()->sum('total_rooms'),
        ];
    }

    /**
     * New accounts per month, so the office can see the platform growing.
     *
     * @return Collection<int, array{label: string, tourists: int, owners: int}>
     */
    public function registrations(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $byRole = fn (UserRole $role) => User::query()
            ->where('role', $role->value)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month")
            ->selectRaw('count(*) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $tourists = $byRole(UserRole::Tourist);
        $owners = $byRole(UserRole::ResortOwner);

        $months = collect();
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');

            $months->push([
                'label' => $month->format('M Y'),
                'tourists' => (int) ($tourists[$key] ?? 0),
                'owners' => (int) ($owners[$key] ?? 0),
            ]);
        }

        return $months;
    }

    /**
     * Room-nights actually booked inside the period.
     *
     * Stays that start before or end after the period are clipped to it,
     * so a January report never counts December's nights.
     */
    public function roomNights(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null): int
    {
        $row = Booking::query()
            ->when($resortId, fn (Builder $query) => $query->where('resort_id', $resortId))
            ->whereIn('status', array_column(self::REAL, 'value'))
            ->where('check_in_date', '<=', $to->toDateString())
            ->where('check_out_date', '>', $from->toDateString())
            ->selectRaw(
                'COALESCE(SUM(DATEDIFF(LEAST(check_out_date, ?), GREATEST(check_in_date, ?)) * rooms_booked), 0) as nights',
                [$to->addDay()->toDateString(), $from->toDateString()]
            )
            ->first();

        return (int) ($row->nights ?? 0);
    }

    /**
     * Booked room-nights as a share of what was on offer.
     *
     * Note: this uses each room type's CURRENT number of units, because the
     * system does not keep a history of inventory changes. It is a good
     * guide, not an audited figure.
     */
    public function occupancyRate(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null): int
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        $units = Room::query()
            ->active()
            ->when($resortId,
                fn (Builder $query) => $query->where('resort_id', $resortId),
                fn (Builder $query) => $query->whereHas('resort', fn (Builder $resort) => $resort->publiclyVisible()),
            )
            ->sum('total_rooms');

        $offered = (int) $units * $days;

        if ($offered <= 0) {
            return 0;
        }

        return (int) min(100, round($this->roomNights($from, $to, $resortId) / $offered * 100));
    }

    /**
     * The individual reservations behind the figures above.
     *
     * The totals answer "how many"; this answers "which ones". Without it the
     * office can see that five reservations were made and not who made them,
     * which is the first thing anyone asks when a number looks wrong.
     *
     * Paginated and eager loaded, so the page costs the same number of
     * queries whether there are five reservations or five thousand.
     *
     * simplePaginate, not paginate: a full paginator runs a COUNT of its own,
     * and summary()['reservations'] is already that same count over the same
     * query. The page shows that figure instead of paying for it twice.
     *
     * @return \Illuminate\Contracts\Pagination\Paginator<int, Booking>
     */
    public function reservations(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay', int $perPage = 25)
    {
        return $this->bookingsIn($from, $to, $resortId, $basis)
            ->with(['resort:id,name,barangay', 'room:id,name', 'tourist:id,first_name,last_name,email,home_city,home_country'])
            ->orderBy('check_in_date')
            ->orderBy('id')
            ->simplePaginate($perPage)
            ->withQueryString();
    }

    /**
     * The people who booked, one row each, however many times they booked.
     *
     * Deliberately carries NO money. How many visitors came, where they came
     * from, and how often they return is tourism information the office is
     * there to track. What each of them paid is the resort's own trade, and
     * putting it beside a named person turns a visitor count into a customer
     * ledger for somebody else's business.
     *
     * One grouped query, so the cost does not grow with the number of guests.
     *
     * @return Collection<int, array{name: string, email: ?string, phone: ?string, place: ?string, bookings: int, guests: int, nights: int, latest: string}>
     */
    public function guests(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay', int $limit = 50): Collection
    {
        return $this->bookingsIn($from, $to, $resortId, $basis)
            ->join('users', 'users.id', '=', 'bookings.user_id')
            ->selectRaw('users.id as user_id')
            ->selectRaw("CONCAT(users.first_name, ' ', users.last_name) as name")
            ->selectRaw('users.email as email')
            ->selectRaw('users.home_city as home_city')
            ->selectRaw('users.home_country as home_country')
            ->selectRaw('MAX(bookings.contact_phone) as phone')
            ->selectRaw('COUNT(*) as bookings_count')
            // Aliased away from the column name: "bookings.guests" and a
            // "guests" alias in the same statement is ambiguous.
            ->selectRaw('SUM(bookings.guests) as guests_total')
            ->selectRaw('SUM(bookings.nights) as nights_total')
            ->selectRaw('MAX(bookings.check_in_date) as latest_stay')
            ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.home_city', 'users.home_country')
            ->orderByDesc('bookings_count')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'name' => trim((string) $row->name),
                'email' => $row->email,
                'phone' => $row->phone,
                'place' => collect([$row->home_city, $row->home_country])->filter()->implode(', ') ?: null,
                'bookings' => (int) $row->bookings_count,
                'guests' => (int) $row->guests_total,
                'nights' => (int) $row->nights_total,
                'latest' => (string) $row->latest_stay,
            ]);
    }

    /**
     * The office's visitor register: one row for every stay.
     *
     * A guest books, and that booking becomes a line in the office's report,
     * under the name of the person who made it. This is the register the
     * Department of Tourism's monthly return is totalled up from, so the
     * columns are the form's own:
     *
     *   guests        people who checked in on this booking
     *   guest_nights  those people multiplied by the nights they stayed
     *   rooms_used    rooms taken, multiplied by those nights
     *   male / female the party split by sex, as the guest gave it
     *   origin        where they travelled from
     *
     * Listed by arrival, not by stays that merely touch the period: a register
     * for October holds who checked IN during October, which is what each
     * resort's own guest book records.
     *
     * Only reservations that stand appear. Somebody who cancelled did not
     * visit, and a register that counted them would overstate the
     * municipality's arrivals to the Department.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function accommodationRegister(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $resortId = null,
    ): Collection {
        /*
         * Joined rather than eager loaded, and read through the query builder
         * rather than hydrated into models.
         *
         * This is a flat sheet, not a graph of objects: every line needs one
         * resort name, one room name and one guest name and nothing else.
         * Eager loading the three relations costs three more queries on the
         * heaviest page in the system, which has a budget to keep (see
         * QueryBudgetTest). Every column is aliased, because bookings, resorts,
         * rooms and users all have an id, and resorts and rooms both have a
         * name.
         */
        return Booking::query()
            ->toBase()
            ->join('resorts', 'resorts.id', '=', 'bookings.resort_id')
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->leftJoin('users', 'users.id', '=', 'bookings.user_id')
            ->whereIn('bookings.status', array_column(self::REAL, 'value'))
            ->whereBetween('bookings.check_in_date', [$from->toDateString(), $to->toDateString()])
            ->when($resortId, fn ($query) => $query->where('bookings.resort_id', $resortId))
            ->selectRaw('bookings.booking_reference as reference')
            ->selectRaw('bookings.contact_name as contact_name')
            ->selectRaw("TRIM(CONCAT(COALESCE(users.first_name, ''), ' ', COALESCE(users.last_name, ''))) as account_name")
            ->selectRaw('resorts.name as resort_name')
            ->selectRaw('resorts.barangay as barangay')
            ->selectRaw('rooms.name as room_name')
            ->selectRaw('bookings.check_in_date as check_in_date')
            ->selectRaw('bookings.check_out_date as check_out_date')
            ->selectRaw('bookings.nights as nights')
            ->selectRaw('bookings.guests as guests')
            ->selectRaw('bookings.rooms_booked as rooms_booked')
            ->selectRaw('bookings.guests_male as guests_male')
            ->selectRaw('bookings.guests_female as guests_female')
            ->selectRaw('bookings.origin as origin')
            ->orderBy('bookings.check_in_date')
            ->orderBy('bookings.id')
            ->get()
            ->map(function (object $row): array {
                $guests = (int) $row->guests;
                $nights = (int) $row->nights;

                // The party's guests land in the one column they belong to, so
                // a line adds across to the number of people on it.
                $origin = [];

                foreach (VisitorOrigin::cases() as $case) {
                    $origin[$case->value] = $row->origin === $case->value ? $guests : 0;
                }

                $origin['not_stated'] = $row->origin === null ? $guests : 0;

                // Null means nobody was asked, which is not the same as none.
                $stated = $row->guests_male !== null || $row->guests_female !== null;

                return [
                    'reference' => $row->reference,
                    // Whose stay it was. The account holder's name when there
                    // is one, and otherwise the name the booking was made
                    // under, which is who the resort will actually meet.
                    'guest' => $row->account_name !== '' ? $row->account_name : $row->contact_name,
                    'resort' => $row->resort_name,
                    'barangay' => $row->barangay,
                    'room' => $row->room_name,
                    'check_in' => CarbonImmutable::parse($row->check_in_date),
                    'check_out' => CarbonImmutable::parse($row->check_out_date),
                    'nights' => $nights,
                    'guests' => $guests,
                    'guest_nights' => $guests * $nights,
                    'rooms_used' => (int) $row->rooms_booked * $nights,
                    'male' => (int) $row->guests_male,
                    'female' => (int) $row->guests_female,
                    'sex_not_stated' => $stated ? 0 : $guests,
                    'origin' => $origin,
                ];
            });
    }

    /**
     * Rows for the CSV download.
     *
     * @return \Illuminate\Support\LazyCollection<int, Booking>
     */
    public function exportRows(CarbonImmutable $from, CarbonImmutable $to, ?int $resortId = null, string $basis = 'stay')
    {
        return $this->bookingsIn($from, $to, $resortId, $basis)
            ->with(['resort:id,name,barangay', 'room:id,name', 'tourist:id,home_city,home_country'])
            ->orderBy('check_in_date')
            ->lazy();
    }
}
