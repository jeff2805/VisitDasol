<?php

namespace App\Services;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\WalkInSubmissionStatus;
use App\Exceptions\BookingException;
use App\Exceptions\WalkInException;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in records: guests the resort met at its own gate.
 *
 * A SEPARATE DOOR INTO THE SAME ROOM. Everything a walk-in produces is an
 * ordinary booking row — it holds inventory, it is priced by BookingPricer,
 * and AvailabilityService counts it without ever asking where it came from.
 * What differs is who is allowed to create one and which of the guest-facing
 * rules apply, and that is the whole reason this is not a flag passed into
 * BookingService::create().
 *
 * THREE RULES OF THE ONLINE FORM DO NOT APPLY HERE, each for its own reason:
 *
 *   1. NO FUTURE-DATE REQUIREMENT. The online form refuses a check-in before
 *      today, because nobody books a stay that has started. A walk-in is the
 *      opposite case: the resort is writing down a guest who is standing in
 *      front of them, or who arrived yesterday and whom somebody is only now
 *      getting round to recording. Refusing the past would make the feature
 *      useless on the day it is most needed.
 *
 *   2. NO ONE-ACTIVE-BOOKING RULE. That rule stops one tourist holding rooms
 *      at two resorts at once. A resort takes many walk-ins a day, and they
 *      are not one person's.
 *
 *   3. NO ACCOUNT. bookings.user_id stays null. Nothing may be invented for
 *      a walk-in guest: a placeholder account would appear in the user list,
 *      in registration counts, and in anything that joins users.
 *
 * WHAT IS NOT RELAXED is availability. The room is locked and swept exactly
 * as the online form does it, because the whole point of recording a walk-in
 * is that the room is really occupied — a walk-in that double-booked a
 * cottage already sold online would be worse than no record at all.
 *
 * The record is born Confirmed and Draft at the same time, which reads oddly
 * until you see that they answer different questions: Confirmed is what the
 * ROOM does, Draft is what the TOURISM OFFICE has seen. See
 * WalkInSubmissionStatus.
 */
class WalkInBookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingPricer $pricer,
        private readonly BookingReferenceGenerator $references,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Record a new walk-in against one of the resort's rooms.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BookingException|ValidationException
     */
    public function create(Resort $resort, int $roomId, array $data): Booking
    {
        return DB::transaction(function () use ($resort, $roomId, $data): Booking {
            $room = $this->lockedRoom($resort, $roomId);

            $shape = $this->shape($room, $data);

            $this->guardAvailability(
                $room,
                $shape['check_in'],
                $shape['check_out'],
                $shape['rooms_booked'],
            );

            $booking = new Booking();

            $booking->forceFill([
                'booking_reference' => $this->references->generate($shape['check_in']),

                // The point of the whole module: no account behind this guest.
                'user_id' => null,

                'booking_source' => BookingSource::WalkIn,

                /*
                 * Confirmed from the first save, so the room is held from the
                 * moment the resort writes the guest down. Nothing about the
                 * tourism office's review touches this.
                 */
                'status' => BookingStatus::Confirmed,
                'confirmed_at' => now(),

                // Not sent to anybody yet.
                'submission_status' => WalkInSubmissionStatus::Draft,

                'resort_id' => $resort->id,
                'room_id' => $room->id,
            ] + $this->columns($room, $shape));

            $booking->save();

            $this->logger->log(
                'walk_in.recorded',
                $booking,
                "Recorded walk-in {$booking->booking_reference} at {$resort->name}",
                ['guests' => $shape['guests'], 'nights' => $shape['nights']],
            );

            return $booking;
        });
    }

    /**
     * Correct a walk-in the resort still owns.
     *
     * The caller has already checked through the policy that this record may
     * be edited; it is asked again here under the row lock, because a page
     * left open while the tourism office accepted the record must not be able
     * to rewrite a figure that has been filed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BookingException|ValidationException
     */
    public function update(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data): Booking {
            $locked = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw BookingException::notBookable();
            }

            if (! $locked->isWalkIn()) {
                throw WalkInException::notAWalkIn();
            }

            if (! $locked->isEditableByOwner()) {
                throw WalkInException::notEditable();
            }

            $room = $this->lockedRoom($locked->resort, (int) ($data['room'] ?? $locked->room_id));

            $shape = $this->shape($room, $data);

            /*
             * The record's own units are not counted against it, or editing
             * the guest's phone number on a booking that filled the last
             * cottage would refuse itself.
             */
            $this->guardAvailability(
                $room,
                $shape['check_in'],
                $shape['check_out'],
                $shape['rooms_booked'],
                $locked->id,
            );

            $locked->forceFill(['room_id' => $room->id] + $this->columns($room, $shape))->save();

            $this->logger->log(
                'walk_in.corrected',
                $locked,
                "Corrected walk-in {$locked->booking_reference}",
            );

            return $locked;
        });
    }

    /* ------------------------------------------------------------------ */

    /**
     * The room, locked, and proved to belong to this resort.
     *
     * The ownership check is what stops a resort recording a walk-in against
     * somebody else's cottage by posting another room's id.
     */
    private function lockedRoom(Resort $resort, int $roomId): Room
    {
        $room = Room::query()->whereKey($roomId)->lockForUpdate()->first();

        if ($room === null || (int) $room->resort_id !== (int) $resort->id) {
            throw BookingException::notBookable();
        }

        /*
         * Active, but NOT isBookable(): that also asks whether the resort is
         * publicly visible, which is a question about the website. A resort
         * temporarily hidden from the listings still has guests at its gate,
         * and still owes the tourism office a return for them.
         */
        if (! $room->isActive()) {
            throw BookingException::notBookable();
        }

        $room->setRelation('resort', $resort);

        return $room;
    }

    /**
     * Read the form into the shape a booking row needs, refusing anything
     * that does not add up before a single column is written.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function shape(Room $room, array $data): array
    {
        $type = BookingType::tryFrom((string) ($data['booking_type'] ?? '')) ?? BookingType::Overnight;

        if (! $room->offers($type)) {
            throw BookingException::typeNotOffered($type->label());
        }

        $checkIn = CarbonImmutable::parse($data['check_in_date'])->startOfDay();

        /*
         * A tour is one date to the resort and [D, D+1) to the engine, so it
         * holds its date exactly as a night does. Identical to the online
         * path on purpose: two ways of storing the same thing would be two
         * ways for the availability sweep to be wrong.
         */
        $checkOut = $type->isTour()
            ? $checkIn->addDay()
            : CarbonImmutable::parse($data['check_out_date'])->startOfDay();

        if ($checkOut->lte($checkIn)) {
            throw BookingException::badRange();
        }

        // No past-date refusal here. See the class comment: a walk-in is
        // often written down after the guest has already arrived.

        $maxNights = (int) config('visitdasol.booking.max_nights', 30);

        if (! $type->isTour() && $checkIn->diffInDays($checkOut) > $maxNights) {
            throw BookingException::tooLong($maxNights);
        }

        $adults = (int) ($data['adults'] ?? 1);
        $children = (int) ($data['children'] ?? 0);
        $pwdGuests = (int) ($data['pwd_guests'] ?? 0);
        $roomsBooked = max(1, (int) ($data['rooms_booked'] ?? 1));

        if ($adults < 1 || $children < 0 || $pwdGuests < 0) {
            throw ValidationException::withMessages([
                'adults' => 'Please give valid guest counts.',
            ]);
        }

        // PWD guests are a subset of the party, not extra people.
        $guests = $adults + $children;

        if ($pwdGuests > $guests) {
            throw ValidationException::withMessages([
                'pwd_guests' => 'PWD guests cannot be more than the whole party.',
            ]);
        }

        /*
         * THE SEX SPLIT HAS TO ADD UP TO THE PARTY.
         *
         * Not a nicety: these two columns are what the municipality reports
         * to the Department of Tourism, and a line that says three guests of
         * whom one was male and one female is a line somebody at the office
         * has to chase the resort about months later.
         */
        $male = $this->count($data['guests_male'] ?? null);
        $female = $this->count($data['guests_female'] ?? null);

        if ($male !== null && $female !== null && $male + $female !== $guests) {
            throw ValidationException::withMessages([
                'guests_male' => "The male and female counts must add up to {$guests} guests.",
            ]);
        }

        if ($problem = $room->partyProblem($guests, $roomsBooked)) {
            throw BookingException::partyDoesNotFit($problem);
        }

        $nights = $type->isTour() ? 0 : (int) $checkIn->diffInDays($checkOut);

        [$defaultIn, $defaultOut] = $type->defaultHours();

        return [
            'type' => $type,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'check_in_time' => $this->time($data['check_in_time'] ?? null)
                ?? ($type->isTour() ? null : $room->resort?->check_in_time)
                ?? $defaultIn,
            'check_out_time' => $this->time($data['check_out_time'] ?? null)
                ?? ($type->isTour() ? null : $room->resort?->check_out_time)
                ?? $defaultOut,
            'nights' => $nights,
            'guests' => $guests,
            'adults' => $adults,
            'children' => $children,
            'pwd_guests' => $pwdGuests,
            'rooms_booked' => $roomsBooked,
            'guests_male' => $male,
            'guests_female' => $female,
            'data' => $data,
        ];
    }

    /**
     * The columns a walk-in writes, priced by the same quote the online
     * form uses.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private function columns(Room $room, array $shape): array
    {
        $type = $shape['type'];
        $data = $shape['data'];

        $price = $this->pricer->quote(
            $room,
            $shape['nights'],
            $shape['rooms_booked'],
            $shape['adults'],
            $shape['children'],
            $shape['pwd_guests'],
            $type,
        );

        return [
            'check_in_date' => $shape['check_in'],
            'check_out_date' => $shape['check_out'],
            'check_in_time' => $shape['check_in_time'],
            'check_out_time' => $shape['check_out_time'],

            'booking_type' => $type,
            'nights' => $shape['nights'],
            'guests' => $shape['guests'],
            'adults' => $shape['adults'],
            'children' => $shape['children'],
            'pwd_guests' => $shape['pwd_guests'],
            'rooms_booked' => $shape['rooms_booked'],
            'beds' => (int) $room->bed_count * $shape['rooms_booked'],

            'unit_price' => $room->priceFor($type) ?? $room->price_per_night,
            'subtotal_amount' => $price->subtotal,
            'child_discount_amount' => $price->childDiscount,
            'pwd_discount_amount' => $price->pwdDiscount,
            'discount_total' => $price->discountTotal,
            'child_discount_percent' => $price->childPercent,
            'pwd_discount_percent' => $price->pwdPercent,
            'total_amount' => $price->total,

            // Who the resort actually met.
            'contact_name' => trim((string) ($data['contact_name'] ?? '')),
            'contact_phone' => trim((string) ($data['contact_phone'] ?? '')),
            'contact_email' => $data['contact_email'] ?? null,
            'guest_address' => $data['guest_address'] ?? null,
            'special_requests' => $data['special_requests'] ?? null,

            // The Department of Tourism's columns.
            'guests_male' => $shape['guests_male'],
            'guests_female' => $shape['guests_female'],
            'origin' => $data['origin'] ?? null,
        ];
    }

    /**
     * Refuse a walk-in that would oversell the room type.
     *
     * Asked under the same lock and through the same service as the online
     * booking engine, so the two cannot disagree about what is free.
     */
    private function guardAvailability(
        Room $room,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        int $roomsBooked,
        ?int $excludeBookingId = null,
    ): void {
        if ($roomsBooked > (int) $room->total_rooms) {
            throw BookingException::unavailable((int) $room->total_rooms, $roomsBooked);
        }

        $available = $this->availability->availableUnits(
            $room,
            $checkIn,
            $checkOut,
            $excludeBookingId,
        );

        if ($available < $roomsBooked) {
            throw BookingException::unavailable($available, $roomsBooked);
        }
    }

    /** A count the form may have left blank, which is not the same as zero. */
    private function count(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : max(0, (int) $value);
    }

    /** HH:MM, or null when the form said nothing. */
    private function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{2}:\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
