<?php

namespace App\Services;

use App\Enums\ExtraStatus;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\ResortExtra;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a guest asked for besides the room, and what the resort said.
 *
 * TWO RULES RUN THROUGH ALL OF THIS.
 *
 * The price is never taken from the form. It is read from the resort's
 * own listing and snapshotted onto the request, so a guest cannot ask
 * for a ₱1 banana boat and an owner cannot quietly reprice one that was
 * already agreed.
 *
 * Nothing here touches the booking total. Extras are settled at the
 * resort, so asking for one changes no subtotal, no discount, no
 * deposit and no payment deadline. The money the portal says is owed
 * stays the money for the room.
 */
class BookingExtraService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Attach what a guest asked for to a new booking.
     *
     * @param  array<int, int>  $wanted  extra id => quantity
     */
    public function request(Booking $booking, array $wanted): void
    {
        /*
         * NOTHING MEANS NOTHING.
         *
         * The form sends a box for every extra the resort offers, and
         * the ones the guest does not want arrive as 0 or as an empty
         * string. Those are an answer — "no kayak" — not a request for
         * one, and the clamp below would read 0 as 1 and book a guest
         * a boat they declined. Dropped here, before anything is
         * looked up.
         */
        $wanted = array_filter(
            $wanted,
            fn ($quantity) => is_numeric($quantity) && (int) $quantity > 0
        );

        if ($wanted === []) {
            return;
        }

        /*
         * Only this resort's own live listings. An id posted from
         * another resort's form, or one the owner has switched off, is
         * dropped rather than refused: the booking itself is sound and
         * a guest should not lose a room over a kayak.
         */
        $offered = ResortExtra::query()
            ->where('resort_id', $booking->resort_id)
            ->active()
            ->whereIn('id', array_keys($wanted))
            ->get()
            ->keyBy('id');

        if ($offered->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($booking, $wanted, $offered): void {
            foreach ($offered as $id => $extra) {
                $quantity = max(1, min(
                    (int) ($wanted[$id] ?? 1),
                    (int) $extra->max_quantity
                ));

                BookingExtra::query()->create([
                    'booking_id' => $booking->id,
                    'resort_extra_id' => $extra->id,

                    // Snapshotted. Never read back from the listing.
                    'name' => $extra->name,
                    'unit_price' => $extra->price,
                    'price_unit' => $extra->price_unit,
                    'quantity' => $quantity,
                    'amount' => $extra->quote($quantity),

                    'status' => ExtraStatus::Requested,
                ]);
            }
        });
    }

    /**
     * The resort answers one request.
     *
     * @throws \RuntimeException when it has already been answered
     */
    public function answer(
        BookingExtra $extra,
        User $owner,
        ExtraStatus $status,
        ?string $note = null,
    ): BookingExtra {
        if ($status === ExtraStatus::Requested) {
            throw new \RuntimeException('An answer must be a yes or a no.');
        }

        return DB::transaction(function () use ($extra, $owner, $status, $note): BookingExtra {
            $locked = BookingExtra::query()
                ->whereKey($extra->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            $locked->forceFill([
                'status' => $status,
                'owner_note' => $note,
                'answered_by' => $owner->id,
                'answered_at' => CarbonImmutable::now(),
            ])->save();

            $this->logger->logStatusChange(
                'booking.extra_answered',
                $locked->booking,
                ($status === ExtraStatus::Confirmed ? 'Confirmed ' : 'Declined ')
                    .$locked->name.' on '.$locked->booking->booking_reference,
                $from->value,
                $status->value,
                [
                    'extra' => $locked->name,
                    'quantity' => $locked->quantity,
                    'amount' => $locked->amount,
                ],
                $owner,
            );

            return $locked->refresh();
        });
    }

    /**
     * What the confirmed extras come to, as a string of pesos.
     *
     * SHOWN BESIDE THE BOOKING TOTAL, NEVER ADDED TO IT. This is what
     * the guest should expect to settle at the resort; the booking
     * total remains what the room costs.
     *
     * Only confirmed lines are counted. A request nobody has answered
     * is not money anybody owes, and a declined one never was.
     */
    public function confirmedTotal(Booking $booking): string
    {
        $centavos = $booking->extras
            ->where('status', ExtraStatus::Confirmed)
            ->reduce(
                fn (int $carry, BookingExtra $extra) => $carry
                    + (int) round((float) $extra->amount * 100),
                0
            );

        return number_format($centavos / 100, 2, '.', '');
    }

    /** The same, for everything still waiting on the resort. */
    public function requestedTotal(Booking $booking): string
    {
        $centavos = $booking->extras
            ->where('status', ExtraStatus::Requested)
            ->reduce(
                fn (int $carry, BookingExtra $extra) => $carry
                    + (int) round((float) $extra->amount * 100),
                0
            );

        return number_format($centavos / 100, 2, '.', '');
    }
}
