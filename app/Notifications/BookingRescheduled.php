<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a resort owner that a waiting request has moved to new dates.
 *
 * SENT BECAUSE THE QUESTION CHANGED, not as a courtesy. The owner is looking
 * at this request in their list deciding whether to accept it, and the dates
 * they were asked about are not the dates any more. An owner who confirmed
 * from a stale screen would be agreeing to a stay they never read.
 *
 * Only pending requests can move, so this never arrives about a stay the
 * resort has already accepted.
 *
 * Not queued, matching the rest of the booking mail in this system.
 */
class BookingRescheduled extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        /** The dates it used to be, as "Y-m-d to Y-m-d". */
        public readonly string $previousDates,
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
        $guest = $booking->contact_name ?: 'A guest';

        $now = $booking->check_in_date->format('M j, Y')
            .' to '.$booking->check_out_date->format('M j, Y');

        return (new MailMessage)
            ->subject('New dates on reservation request '.$booking->booking_reference)
            ->greeting('Hello')
            ->line("{$guest} has changed the dates on reservation request {$booking->booking_reference}, "
                .'which is still waiting for your answer.')
            ->line("**Was:** {$this->previousDates}")
            ->line("**Now:** {$now}")
            ->line('Please read the new dates before you accept or decline.')
            ->action('View the request', route('owner.bookings.show', $booking))
            ->line('Nothing has been charged, and the rooms are held for the new dates.');
    }
}
