<?php

namespace App\Services;

use App\Enums\BookingStatus;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Notifications\BookingDecided;
use App\Services\Payments\PaymentPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Central service for reservation status changes.
 *
 * All status changes pass through this class so that transition rules,
 * date validation, activity logging, and notifications stay consistent.
 *
 * Authorization is handled by BookingPolicy in the controller.
 */
class BookingStatusManager
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * Confirm a pending reservation before its arrival date.
     *
     * A REQUEST IS CHECKED AGAIN AT THE MOMENT IT IS ANSWERED.
     *
     * Days can pass between a guest asking and an owner replying, and in
     * that time the owner can have lowered the room's unit count or its
     * capacity, or another stay can have taken the nights. Confirming used
     * to check only the calendar date and the status transition, so a
     * request could be accepted into inventory that was no longer there and
     * the overbooking surfaced at the gate.
     *
     * The whole thing runs in one transaction with the room row locked, so
     * two owners confirming two requests for the last unit at the same
     * instant are serialized and the second is refused rather than both
     * being told yes.
     */
    public function confirm(Booking $booking): void
    {
        $booking->refresh();

        if (
            $booking->check_in_date === null
            || $booking->check_in_date->lt(
                CarbonImmutable::today(config('app.timezone'))
            )
        ) {
            throw new BookingException(
                'The arrival date has already passed, so this request can only be declined.'
            );
        }

        DB::transaction(function () use ($booking): void {
            $this->guardStillAvailable($booking);

            /*
             * A deadline counted from confirmation could not be worked out
             * when the request was sent, because this is the moment it counts
             * from. Filled in now, and only if it is still blank: a date the
             * guest has already been given is a date they are working to.
             */
            $changes = ['confirmed_at' => now()];

            $dueAt = app(PaymentPolicyService::class)
                ->deadlineOnConfirmation($booking);

            if ($dueAt !== null) {
                $changes['payment_due_at'] = $dueAt;
            }

            $this->apply(
                $booking,
                BookingStatus::Confirmed,
                $changes,
                'booking.confirmed',
                "Confirmed {$booking->booking_reference}",
                $dueAt !== null
                    ? ['payment_due_at' => $dueAt->toDateTimeString()]
                    : []
            );
        });
    }

    /**
     * Refuse the confirmation if the room can no longer take this party.
     *
     * Called inside confirm()'s transaction, and it is what takes the lock:
     * every later read in that transaction sees a room nobody else can
     * change until this one commits.
     *
     * availableUnits() is asked to EXCLUDE this booking. A pending request
     * already counts towards the units in use (see Booking::scopeBlocking),
     * so without that the request would be measured against itself and a
     * resort with one unit could never confirm anything.
     */
    private function guardStillAvailable(Booking $booking): void
    {
        $room = Room::query()
            ->whereKey($booking->room_id)
            ->lockForUpdate()
            ->first();

        if ($room === null) {
            throw new BookingException(
                'The room this request is for no longer exists, so it can only be declined.'
            );
        }

        // The party against the room as it stands NOW, not as it stood when
        // the guest asked: an owner may have changed the capacity since.
        if ($problem = $room->partyProblem((int) $booking->guests, (int) $booking->rooms_booked)) {
            throw new BookingException(
                'This request no longer fits the room. '.$problem
            );
        }

        $available = $this->availability->availableUnits(
            $room,
            $booking->check_in_date,
            $booking->check_out_date,
            $booking->getKey(),
        );

        if ($available < (int) $booking->rooms_booked) {
            throw BookingException::availabilityChanged(
                $available,
                (int) $booking->rooms_booked
            );
        }
    }

    /**
     * Reject a reservation and save the owner's remarks.
     */
    public function reject(Booking $booking, string $remarks): void
    {
        $this->apply(
            $booking,
            BookingStatus::Rejected,
            [
                'rejected_at' => now(),
                'owner_remarks' => $remarks,
            ],
            'booking.rejected',
            "Declined {$booking->booking_reference}",
            ['reason' => $remarks]
        );
    }

    /**
     * The moment a stay actually ends: its checkout date at its checkout
     * hour.
     *
     * Public, because the housekeeping job that closes finished stays has
     * to ask the same question this class answers. If the two disagreed,
     * the job would keep picking up stays that complete() then refuses.
     *
     * The hour is taken from the booking, then from the resort's standard
     * checkout, and only then from the end of the day. Bookings made
     * before guests chose their own times have no hour of their own, and
     * leaving those permanently uncloseable would be worse than reading
     * the resort's usual hour off the listing.
     *
     * Null only when there is no checkout date at all, which should not
     * happen and is refused rather than guessed.
     */
    public function checkoutAt(Booking $booking): ?CarbonImmutable
    {
        if ($booking->check_out_date === null) {
            return null;
        }

        $timezone = config('app.timezone', 'Asia/Manila');

        $time = $this->firstTime([
            $booking->check_out_time,
            $booking->resort?->check_out_time,
        ]) ?? '23:59';

        return CarbonImmutable::parse(
            $booking->check_out_date->format('Y-m-d').' '.$time,
            $timezone
        );
    }

    /** The first usable HH:MM in the list, or null if there is none. */
    private function firstTime(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (
                is_string($candidate)
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d/', trim($candidate))
            ) {
                return substr(trim($candidate), 0, 5);
            }
        }

        return null;
    }

    /**
     * Complete a confirmed reservation only after its checkout
     * date and checkout time have passed.
     */
    public function complete(Booking $booking): void
    {
        $booking->refresh();

        $checkoutAt = $this->checkoutAt($booking);

        if ($checkoutAt === null) {
            throw new BookingException(
                'This reservation has no checkout date, so it cannot be completed.'
            );
        }

        $now = CarbonImmutable::now(config('app.timezone', 'Asia/Manila'));

        if ($now->lte($checkoutAt)) {
            throw new BookingException(
                'This stay has not finished yet. It can only be completed after the checkout date and time.'
            );
        }

        $this->apply(
            $booking,
            BookingStatus::Completed,
            ['completed_at' => now()],
            'booking.completed',
            "Marked {$booking->booking_reference} completed"
        );
    }

    /**
     * Cancel a reservation before the stay starts.
     */
    public function cancel(
        Booking $booking,
        User $by,
        string $reason
    ): void {
        $booking->refresh();

        if (
            $booking->check_in_date === null
            || $booking->check_in_date->lte(
                CarbonImmutable::today(config('app.timezone'))
            )
        ) {
            throw new BookingException(
                'The stay has already started or is starting today, so it can no longer be cancelled here.'
            );
        }

        $this->apply(
            $booking,
            BookingStatus::Cancelled,
            [
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ],
            'booking.cancelled',
            "Cancelled {$booking->booking_reference}",
            ['reason' => $reason],
            $by
        );
    }

    /**
     * Apply a permitted status transition, persist it, log it,
     * and notify the appropriate party.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $properties
     */
    private function apply(
        Booking $booking,
        BookingStatus $to,
        array $fields,
        string $action,
        string $description,
        array $properties = [],
        ?User $actor = null
    ): void {
        // Always read the current status before applying a transition.
        $booking->refresh();

        $from = $booking->status;

        if (! $from->canTransitionTo($to)) {
            throw new BookingException(
                "This reservation is {$from->label()}, so that is no longer possible."
            );
        }

        $booking->forceFill(
            ['status' => $to] + $fields
        )->save();

        $this->logger->logStatusChange(
            $action,
            $booking,
            $description,
            $from->value,
            $to->value,
            $properties
        );

        $this->announce($booking, $to, $actor);
    }

    /**
     * Notify the other party about the status change.
     */
    private function announce(
        Booking $booking,
        BookingStatus $to,
        ?User $actor
    ): void {
        $booking->loadMissing([
            'tourist',
            'resort.owner',
        ]);

        $cancelledByGuest = $to === BookingStatus::Cancelled
            && $actor !== null
            && (int) $actor->id === (int) $booking->user_id;

        $recipient = $cancelledByGuest
            ? $booking->resort?->owner
            : $booking->tourist;

        if ($recipient === null) {
            return;
        }

        $this->notifier->send(
            $recipient,
            new BookingDecided(
                $booking,
                $to,
                $cancelledByGuest
            )
        );
    }
}