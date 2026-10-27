<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out how many units of a room type are in use.
 *
 * Availability is never stored. It is always calculated from the room's
 * total_rooms and the bookings that hold inventory (see Booking::blocking).
 *
 * The key point: overlapping bookings do not necessarily overlap EACH OTHER,
 * so the units in use are counted night by night and the busiest night wins.
 *
 *   total_rooms = 2
 *   A: Oct 10-12 (1 unit)   B: Oct 12-14 (1 unit)
 *   Naive total for Oct 10-14 = 2 units  ->  "nothing free"   (wrong)
 *   Per night: 1, 1, 1, 1 -> peak 1      ->  1 unit free      (right)
 *
 * Phase 11 builds the booking engine on top of this class.
 */
class AvailabilityService
{
    /**
     * The largest number of units committed on any single night of the range.
     */
    public function peakUnitsInUse(
        Room $room,
        CarbonInterface|string $checkIn,
        CarbonInterface|string $checkOut,
        ?int $excludeBookingId = null,
    ): int {
        $from = CarbonImmutable::parse($checkIn)->startOfDay();
        $until = CarbonImmutable::parse($checkOut)->startOfDay();

        if ($until->lte($from)) {
            return 0;
        }

        $bookings = $room->bookings()
            ->blocking()
            ->overlapping($from, $until)
            ->when($excludeBookingId, fn ($query) => $query->whereKeyNot($excludeBookingId))
            ->get(['id', 'check_in_date', 'check_out_date', 'rooms_booked']);

        return $this->peak($bookings, $from, $until);
    }

    /**
     * Units of this room type still free for the whole of [checkIn, checkOut).
     */
    public function availableUnits(
        Room $room,
        CarbonInterface|string $checkIn,
        CarbonInterface|string $checkOut,
        ?int $excludeBookingId = null,
    ): int {
        return max(0, $room->total_rooms - $this->peakUnitsInUse($room, $checkIn, $checkOut, $excludeBookingId));
    }

    /**
     * Availability of every bookable room type of a resort, for one date
     * range. Used by the public resort page.
     *
     * @return \Illuminate\Support\Collection<int, array{room: Room, available: int, fits: bool}>
     */
    public function forResort(
        Resort $resort,
        CarbonInterface|string $checkIn,
        CarbonInterface|string $checkOut,
        int $guests = 1,
    ) {
        return $resort->rooms()
            ->active()
            ->with('images')
            ->orderBy('price_per_night')
            ->get()
            ->map(fn (Room $room) => [
                'room' => $room,
                'available' => $this->availableUnits($room, $checkIn, $checkOut),
                'fits' => $room->capacity >= $guests,
            ]);
    }

    /**
     * A month of nightly availability for every bookable room type of a
     * resort, for the owner's calendar.
     *
     * @return array<int, array<string, int>>  room id => [Y-m-d => units free]
     */
    public function calendarForResort(Resort $resort, CarbonInterface|string $month): array
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();

        $rooms = $resort->rooms()->active()->get();

        // One query for every room type's reservations in the month.
        $bookingsByRoom = Booking::query()
            ->whereIn('room_id', $rooms->modelKeys())
            ->blocking()
            ->overlapping($start, $end->addDay())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'rooms_booked'])
            ->groupBy('room_id');

        $calendar = [];

        foreach ($rooms as $room) {
            $bookings = $bookingsByRoom->get($room->id, collect());
            $nights = [];

            for ($night = $start; $night->lte($end); $night = $night->addDay()) {
                $inUse = $bookings
                    ->filter(fn (Booking $booking) => $booking->check_in_date->lte($night)
                        && $booking->check_out_date->gt($night))
                    ->sum('rooms_booked');

                $nights[$night->toDateString()] = max(0, $room->total_rooms - (int) $inUse);
            }

            $calendar[$room->id] = $nights;
        }

        return $calendar;
    }

    /**
     * peakUnitsCommittedAhead() for several room types at once, with a single
     * query, for the owner's room list.
     *
     * @param  iterable<Room>  $rooms
     * @return array<int, int>  room id => peak units held on any night from today
     */
    public function peakCommittedAheadForRooms(iterable $rooms): array
    {
        $rooms = collect($rooms);

        if ($rooms->isEmpty()) {
            return [];
        }

        $today = CarbonImmutable::today();

        $bookingsByRoom = Booking::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->blocking()
            ->where('check_out_date', '>', $today->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'rooms_booked'])
            ->groupBy('room_id');

        $peaks = [];

        foreach ($rooms as $room) {
            $bookings = $bookingsByRoom->get($room->id, collect());

            $peaks[$room->id] = $bookings->isEmpty()
                ? 0
                : $this->peak($bookings, $today, CarbonImmutable::parse($bookings->max('check_out_date')));
        }

        return $peaks;
    }

    /**
     * How many units of each room type are free for one date range, for many
     * room types at once.
     *
     * availableUnits() asks per room, which is right when there is one room on
     * the page and wrong when there are twenty: the public room listing would
     * run a query per card and grow slower with every resort that registers.
     * This asks once, the way calendarForResort() does.
     *
     * @param  iterable<Room>  $rooms
     * @return array<int, int>  room id => units free for the whole range
     */
    public function availableUnitsForRooms(
        iterable $rooms,
        CarbonInterface|string $checkIn,
        CarbonInterface|string $checkOut,
    ): array {
        $rooms = collect($rooms);

        if ($rooms->isEmpty()) {
            return [];
        }

        $from = CarbonImmutable::parse($checkIn)->startOfDay();
        $until = CarbonImmutable::parse($checkOut)->startOfDay();

        if ($until->lte($from)) {
            return [];
        }

        $bookingsByRoom = Booking::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->blocking()
            ->overlapping($from, $until)
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'rooms_booked'])
            ->groupBy('room_id');

        $free = [];

        foreach ($rooms as $room) {
            $bookings = $bookingsByRoom->get($room->id, collect());

            $free[$room->id] = max(0, $room->total_rooms - $this->peak($bookings, $from, $until));
        }

        return $free;
    }

    /**
     * How full each resort is over one date range, for the public map.
     *
     * Aggregate figures only: how many guests a resort can take, how many
     * are held over those nights, and how many room units are left. Who
     * those guests are never leaves this method.
     *
     * Everything is counted the way the booking engine counts it — the
     * same blocking() statuses, the same overlapping() dates, the same
     * night-by-night peak — because a map that disagrees with the booking
     * form about whether a resort is full is worse than no map.
     *
     * Two queries whatever the number of resorts, so the map does not grow
     * slower with every listing that registers.
     *
     * @param  iterable<Resort>  $resorts
     * @return array<int, array{
     *     capacity: int,
     *     guests: int,
     *     remaining: int,
     *     percent: int,
     *     rooms_total: int,
     *     rooms_free: int,
     *     status: string
     * }>  resort id => its figures
     */
    public function occupancyForResorts(
        iterable $resorts,
        CarbonInterface|string $checkIn,
        CarbonInterface|string $checkOut,
    ): array {
        $resorts = collect($resorts);

        if ($resorts->isEmpty()) {
            return [];
        }

        $from = CarbonImmutable::parse($checkIn)->startOfDay();
        $until = CarbonImmutable::parse($checkOut)->startOfDay();

        // A range that is not a range cannot be reported on honestly.
        if ($until->lte($from)) {
            return [];
        }

        $resortIds = $resorts->pluck('id');

        /*
         * Asked for here rather than taken from the models, so a caller
         * that forgot to eager load cannot silently get a resort counted
         * as having no rooms at all.
         */
        $rooms = Room::query()
            ->whereIn('resort_id', $resortIds)
            ->active()
            ->get(['id', 'resort_id', 'capacity', 'total_rooms']);

        $bookings = $rooms->isEmpty()
            ? collect()
            : Booking::query()
                ->whereIn('room_id', $rooms->modelKeys())
                ->blocking()
                ->overlapping($from, $until)
                ->get([
                    'id', 'resort_id', 'room_id',
                    'check_in_date', 'check_out_date',
                    'rooms_booked', 'guests',
                ]);

        $roomsByResort = $rooms->groupBy('resort_id');
        $bookingsByResort = $bookings->groupBy('resort_id');
        $bookingsByRoom = $bookings->groupBy('room_id');

        $figures = [];

        foreach ($resorts as $resort) {
            $resortRooms = $roomsByResort->get($resort->id, collect());

            // Every bed the resort could sell on one night.
            $capacity = (int) $resortRooms->sum(
                fn (Room $room) => $room->capacity * $room->total_rooms
            );

            $roomsTotal = (int) $resortRooms->sum('total_rooms');

            /*
             * Free units are counted per room TYPE and then added up. A
             * resort is not "three rooms free" if all three are cottages
             * and the family rooms are gone.
             */
            $roomsFree = 0;

            foreach ($resortRooms as $room) {
                $roomsFree += max(0, $room->total_rooms - $this->peak(
                    $bookingsByRoom->get($room->id, collect()),
                    $from,
                    $until
                ));
            }

            // Heads, on the busiest night of the range.
            $guests = $this->peak(
                $bookingsByResort->get($resort->id, collect()),
                $from,
                $until,
                fn (Booking $booking): int => (int) $booking->guests
            );

            /*
             * A resort cannot hold more guests than it has beds, but the
             * data can still say so — an owner who cut a room's capacity
             * after a party had already booked it leaves exactly that.
             * Clamped, so the map never reports a negative vacancy.
             */
            $guests = $capacity > 0 ? min($guests, $capacity) : $guests;
            $remaining = max(0, $capacity - $guests);

            $percent = $capacity > 0
                ? (int) min(100, round($guests / $capacity * 100))
                : 0;

            $figures[$resort->id] = [
                'capacity' => $capacity,
                'guests' => $guests,
                'remaining' => $remaining,
                'percent' => $percent,
                'rooms_total' => $roomsTotal,
                'rooms_free' => $roomsFree,
                'status' => $this->occupancyStatus($capacity, $percent, $roomsFree),
            ];
        }

        return $figures;
    }

    /**
     * The word, and the marker colour, for one resort's figures.
     *
     * "unknown" is deliberately not "available": a resort with no rooms
     * published has not told us it is empty, only that it has not said.
     * Showing that as a green pin would send a visitor to a place that
     * cannot take them.
     */
    private function occupancyStatus(int $capacity, int $percent, int $roomsFree): string
    {
        if ($capacity < 1 || $roomsFree < 0) {
            return 'unknown';
        }

        if ($roomsFree < 1 || $percent >= 100) {
            return 'full';
        }

        return $percent >= 70 ? 'busy' : 'available';
    }

    /**
     * Units committed on any night from today onwards. Used when an owner
     * lowers the number of units, so inventory never drops below what has
     * already been promised to guests.
     */
    public function peakUnitsCommittedAhead(Room $room, ?int $excludeBookingId = null): int
    {
        $lastNight = $room->bookings()->blocking()->max('check_out_date');

        if ($lastNight === null) {
            return 0;
        }

        return $this->peakUnitsInUse($room, CarbonImmutable::today(), $lastNight, $excludeBookingId);
    }

    /**
     * The most units in use on any single night of [from, until).
     *
     * A "sweep": each stay becomes two events, +units on its first night and
     * -units on its check-out day. Walking the events in date order while
     * keeping a running total gives the busiest night directly. This costs
     * one sort of the reservations instead of checking every reservation on
     * every night, which Phase 20 found took over half a second for a busy
     * resort.
     *
     * On the same date, check-outs are counted before check-ins, so a guest
     * arriving on the day another leaves does not count as an overlap.
     *
     * What is being counted is the caller's to decide. Room units by
     * default; the map counts heads instead, and the busiest night has to
     * be found the same way for both — a resort whose guests arrive in
     * relays is not as full as adding up its bookings would suggest.
     *
     * @param  iterable<Booking>  $bookings
     * @param  (\Closure(Booking): int)|null  $units
     */
    private function peak(
        iterable $bookings,
        CarbonImmutable $from,
        CarbonImmutable $until,
        ?\Closure $units = null
    ): int {
        $units ??= fn (Booking $booking): int => (int) $booking->rooms_booked;

        $first = $from->toDateString();
        $last = $until->toDateString();
        $events = [];

        foreach ($bookings as $booking) {
            // Only the part of the stay inside the range counts. Y-m-d strings
            // compare correctly as plain text.
            $start = max($booking->check_in_date->toDateString(), $first);
            $end = min($booking->check_out_date->toDateString(), $last);

            if ($start >= $end) {
                continue;
            }

            $count = max(0, $units($booking));
            $events[] = [$start, 1, $count];   // 1: check-in
            $events[] = [$end, 0, -$count];    // 0: check-out, sorts first on the same day
        }

        usort($events, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $inUse = 0;
        $peak = 0;

        foreach ($events as [, , $change]) {
            $inUse += $change;
            $peak = max($peak, $inUse);
        }

        return $peak;
    }
}
