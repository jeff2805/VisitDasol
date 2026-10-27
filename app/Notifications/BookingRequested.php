<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells a resort owner that a guest is waiting for an answer.
 *
 * Before this existed, an owner had no way of knowing a request had arrived
 * except by opening the site and looking, so a guest could wait days for a
 * reply that nobody knew was due.
 *
 * Deliberately NOT queued, for the same reason as VerifyEmailWithCode: there
 * is no queue worker on the XAMPP setup, so a queued notification would sit
 * in the jobs table and never be sent.
 */
class BookingRequested extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

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
        $nights = $booking->nights;

        return (new MailMessage)
            ->subject('New booking request: '.$booking->booking_reference)
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('A guest has asked to book '.($booking->resort?->name ?? 'your resort').'.')
            ->line('**Guest:** '.$booking->contact_name)
            ->line('**Room:** '.$booking->rooms_booked.' x '.($booking->room?->name ?? 'room'))
            ->line('**Stay:** '.$booking->check_in_date->format('M j, Y').' to '
                .$booking->check_out_date->format('M j, Y').' ('.$nights.' '.Str::plural('night', $nights).')')
            ->line('**Guests:** '.$booking->guests)
            ->action('Review this request', route('owner.bookings.show', $booking))
            ->line('The rooms are held for this guest until you confirm or decline.');
    }
}
