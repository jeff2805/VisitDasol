<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a resort owner the tourism office has sent a walk-in record back.
 *
 * Goes to the OWNER, never to a guest: a walk-in has no account behind it,
 * and the paperwork is between the resort and the office. The guest's stay is
 * unaffected and is not mentioned, because nothing about it has changed —
 * the room is still held and they are still expected.
 *
 * The remarks are the whole point of the mail. An owner who has to open the
 * site to find out what was wrong has been told nothing useful.
 *
 * Not queued, matching the other booking mail in this system.
 */
class WalkInReturned extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $remarks,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;
        $guest = $booking->contact_name ?: 'a walk-in guest';
        $stay = $booking->check_in_date->format('M j, Y')
            .' to '.$booking->check_out_date->format('M j, Y');

        return (new MailMessage)
            ->subject('A walk-in record needs correcting: '.$booking->booking_reference)
            ->greeting('Hello')
            ->line(
                "The Municipal Tourism Office has returned walk-in record {$booking->booking_reference} "
                ."for {$guest} ({$stay})."
            )
            ->line('What they asked you to correct:')
            ->line($this->remarks)
            ->line(
                'The room stays booked and the guest is unaffected. '
                .'Only the record the office receives needs changing.'
            )
            ->action('Correct the record', route('owner.walk-ins.edit', $booking))
            ->line('Send it back to the office once it is right.');
    }
}
