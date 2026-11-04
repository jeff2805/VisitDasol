<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\IdVerificationStatus;
use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\IdDocumentDecided;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The guest's valid ID, and the two moments the front desk checks it.
 *
 * WHERE THE FILE LIVES. On the 'local' disk, which is storage/app/private
 * and is configured with serve => false — no web server maps a URL onto it.
 * The only way to the bytes is a controller action that authorises first.
 * Nothing in this class ever writes to the public disk, and nothing returns
 * a URL.
 *
 * WHAT IS LOGGED. The reference, the status, and who acted. Never the path,
 * never the file name the guest's phone gave it, never anything read off the
 * document. A log line is read by more people than the document is.
 *
 * Authorisation is BookingPolicy's job and is done in the controllers; this
 * class is about what is true of a booking, not who is asking.
 */
class IdentityVerificationService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
    ) {}

    /** The private disk. Named once so no caller can pick a different one. */
    private function disk()
    {
        return Storage::disk('local');
    }

    /**
     * Store a guest's ID against their booking and put it in the queue.
     *
     * The previous file is deleted once the new row is committed. A guest
     * whose ID was rejected sends another, and keeping the rejected one
     * would leave copies of somebody's licence lying about for no reason.
     *
     * Re-uploading is refused once an ID is verified: the point of
     * acceptsIdDocument() is that nobody has to send the same document
     * twice, and a verified booking already has what it needs.
     */
    public function submit(Booking $booking, UploadedFile $file): Booking
    {
        $booking->refresh();

        if (! $booking->acceptsIdDocument()) {
            throw new BookingException(
                $booking->idIsVerified()
                    ? 'A valid ID for this booking has already been verified. There is nothing more to send.'
                    : 'This booking is no longer accepting a valid ID.'
            );
        }

        $previous = $booking->id_document_path;

        /*
         * Stored under the booking, with a name Laravel generates. The
         * guest's own file name is discarded: it is attacker-controlled,
         * and people name photographs of their passport after their
         * passport.
         */
        $path = $file->store(
            config('visitdasol.uploads.folders.booking_ids', 'booking-ids').'/'.$booking->getKey(),
            'local'
        );

        if ($path === false || blank($path)) {
            throw new BookingException(
                'That file could not be saved. Please try again.'
            );
        }

        DB::transaction(function () use ($booking, $path): void {
            $booking->forceFill([
                'id_document_path' => $path,
                'id_document_status' => IdVerificationStatus::Pending,
                'id_document_submitted_at' => now(),

                // A fresh submission is not yet reviewed by anybody, and the
                // last rejection's reason is no longer the current answer.
                'id_document_verified_at' => null,
                'id_document_verified_by' => null,
                'id_document_rejection_reason' => null,
            ])->save();
        });

        if (filled($previous) && $previous !== $path) {
            $this->disk()->delete($previous);
        }

        $this->logger->log(
            'booking.id_submitted',
            $booking,
            "Sent a valid ID for {$booking->booking_reference}",
            ['id_document_status' => IdVerificationStatus::Pending->value],
            $booking->tourist,
        );

        return $booking;
    }

    /** Approve the ID on a booking. */
    public function approve(Booking $booking, User $staff): void
    {
        $this->review($booking, $staff, IdVerificationStatus::Verified, null);
    }

    /** Reject it, with the reason the guest will be shown. */
    public function reject(Booking $booking, User $staff, string $reason): void
    {
        $this->review($booking, $staff, IdVerificationStatus::Rejected, $reason);
    }

    /**
     * A decision on a submitted ID.
     *
     * Only a pending one can be decided. Locked and re-read inside the
     * transaction so two staff members pressing approve and reject at the
     * same moment cannot both be recorded.
     */
    private function review(
        Booking $booking,
        User $staff,
        IdVerificationStatus $to,
        ?string $reason
    ): void {
        DB::transaction(function () use ($booking, $staff, $to, $reason): void {
            $locked = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new BookingException('This reservation no longer exists.');
            }

            if ($locked->id_document_status !== IdVerificationStatus::Pending) {
                throw new BookingException(
                    $locked->id_document_status === IdVerificationStatus::NotSubmitted
                        ? 'This guest has not submitted a valid ID yet.'
                        : 'This ID has already been reviewed.'
                );
            }

            $locked->forceFill([
                'id_document_status' => $to,
                'id_document_verified_at' => now(),
                'id_document_verified_by' => $staff->getKey(),
                'id_document_rejection_reason' => $reason,
            ])->save();

            $booking->forceFill($locked->only([
                'id_document_status',
                'id_document_verified_at',
                'id_document_verified_by',
                'id_document_rejection_reason',
            ]));
        });

        $this->logger->log(
            $to === IdVerificationStatus::Verified
                ? 'booking.id_verified'
                : 'booking.id_rejected',
            $booking,
            ($to === IdVerificationStatus::Verified ? 'Verified' : 'Rejected')
                ." the valid ID on {$booking->booking_reference}",
            // The reason is the guest's to read, and it is on the booking.
            // It is not repeated into the activity log.
            ['id_document_status' => $to->value],
            $staff,
        );

        $booking->loadMissing('tourist');

        $this->notifier->send(
            $booking->tourist,
            new IdDocumentDecided($booking, $to, $reason)
        );
    }

    /**
     * Check the party in.
     *
     * The order of the refusals is the order the desk would hit them: is
     * this stay one that can be checked into at all, has it already been
     * done, and only then the ID. A party turned away for an unverified ID
     * when the real problem is that the booking was never confirmed would
     * send them off to fix the wrong thing.
     */
    public function checkIn(Booking $booking, User $staff): void
    {
        DB::transaction(function () use ($booking, $staff): void {
            $locked = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new BookingException('This reservation no longer exists.');
            }

            if ($locked->status !== BookingStatus::Confirmed) {
                throw new BookingException(
                    "This reservation is {$locked->status->label()}, so nobody can be checked in against it."
                );
            }

            // Pressing the button twice must not rewrite who was on the desk
            // the first time, or when.
            if ($locked->isCheckedIn()) {
                throw new BookingException(
                    'This party was already checked in on '
                    .$locked->checked_in_at->format('M j, Y \a\t g:i A').'.'
                );
            }

            if ($this->idRequired() && ! $locked->idIsVerified()) {
                throw new BookingException(
                    $locked->id_document_status->blocksCheckInBecause()
                        ?? 'A verified valid ID is required before checking in.'
                );
            }

            $locked->forceFill([
                'checked_in_at' => now(),
                'checked_in_by' => $staff->getKey(),
            ])->save();

            $booking->forceFill($locked->only(['checked_in_at', 'checked_in_by']));
        });

        $this->logger->log(
            'booking.checked_in',
            $booking,
            "Checked in {$booking->booking_reference}",
            [],
            $staff,
        );
    }

    /**
     * Check the party out.
     *
     * NO SECOND COPY OF THE ID IS ASKED FOR. The document verified before
     * arrival is the one this stay has; requiring another at the gate would
     * be a second photograph of the same licence for no gain. What is
     * required is that the verification actually happened — the same
     * condition as check-in, read off the same column.
     */
    public function checkOut(Booking $booking, User $staff): void
    {
        DB::transaction(function () use ($booking, $staff): void {
            $locked = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new BookingException('This reservation no longer exists.');
            }

            if ($locked->isCheckedOut()) {
                throw new BookingException(
                    'This party was already checked out on '
                    .$locked->checked_out_at->format('M j, Y \a\t g:i A').'.'
                );
            }

            if ($this->checkInRequiredFirst() && ! $locked->isCheckedIn()) {
                throw new BookingException(
                    'This party has not been checked in, so there is no stay to close. '
                    .'Check them in first, or decline the reservation.'
                );
            }

            if ($this->idRequired() && ! $locked->idIsVerified()) {
                throw new BookingException(
                    'The valid ID for this stay has not been verified, '
                    .'so check-out cannot be completed. Review the submitted ID first.'
                );
            }

            $locked->forceFill([
                'checked_out_at' => now(),
                'checked_out_by' => $staff->getKey(),
            ])->save();

            $booking->forceFill($locked->only(['checked_out_at', 'checked_out_by']));
        });

        $this->logger->log(
            'booking.checked_out',
            $booking,
            "Checked out {$booking->booking_reference}",
            [],
            $staff,
        );
    }

    private function idRequired(): bool
    {
        return (bool) config('visitdasol.booking.require_id_for_check_in', true);
    }

    private function checkInRequiredFirst(): bool
    {
        return (bool) config('visitdasol.booking.require_check_in_before_check_out', true);
    }
}
