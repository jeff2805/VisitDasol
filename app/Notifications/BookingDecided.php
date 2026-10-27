<?php

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Enums\PaymentPreference;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone what became of a reservation.
 *
 * One class for every outcome rather than four near-identical ones: the
 * wording changes, the facts do not. Whoever needs telling is decided by
 * BookingStatusManager, because for a cancellation that is whichever side
 * did not do the cancelling.
 *
 * Not queued; see BookingRequested for why.
 */
class BookingDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingStatus $outcome,
        /** True when this is going to the resort rather than the guest. */
        public readonly bool $toOwner = false,
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
        $resort = $booking->resort?->name ?? 'the resort';
        $stay = $booking->check_in_date->format('M j, Y').' to '.$booking->check_out_date->format('M j, Y');

        $mail = (new MailMessage)
            ->subject($this->subject($resort))
            ->greeting('Hello '.$notifiable->first_name.',');

        match ($this->outcome) {
            BookingStatus::Confirmed => $mail
                ->line("Good news: {$resort} has confirmed your reservation for {$stay}.")
                ->line('**Reference:** '.$booking->booking_reference)
                ->line('Please bring this reference with you.'),

            BookingStatus::Rejected => $mail
                ->line("{$resort} could not take your booking for {$stay}.")
                ->line($booking->owner_remarks
                    ? '**Reason given:** '.$booking->owner_remarks
                    : 'No reason was given.')
                ->line('Nothing has been charged. You are welcome to try other dates or another resort.'),

            BookingStatus::Cancelled => $mail
                ->line("The reservation {$booking->booking_reference} at {$resort} for {$stay} has been cancelled.")
                ->line($booking->cancellation_reason
                    ? '**Reason given:** '.$booking->cancellation_reason
                    : 'No reason was given.'),

            BookingStatus::Completed => $mail
                ->line("Thank you for staying at {$resort}.")
                ->line('We hope you enjoyed Dasol, and that we see you again.'),

            default => $mail->line('Your reservation '.$booking->booking_reference.' has been updated.'),
        };

        /*
         * They asked to pay by QRPh when they sent the request, and this mail
         * is the moment that stops being a promise. Sent only to the guest,
         * only on a confirmation, and only while the gateway is actually
         * switched on: with no keys set there is no link to send them to.
         */
        if ($this->outcome === BookingStatus::Confirmed
            && ! $this->toOwner
            && $booking->payment_preference === PaymentPreference::Online
            && PaymentPreference::Online->isAvailable()) {
            $mail->line('You chose to pay by QRPh, and you can do that now from the link below. '
                .'It stays optional: you may still settle at the resort when you arrive.');
        }

        return $mail
            ->action(
                $this->toOwner ? 'View the reservation' : 'View your reservation',
                $this->toOwner
                    ? route('owner.bookings.show', $booking)
                    : route('tourist.bookings.show', $booking),
            )
            ->salutation(config('visitdasol.contact.office').', '.config('visitdasol.municipality'));
    }

    private function subject(string $resort): string
    {
        $reference = $this->booking->booking_reference;

        return match ($this->outcome) {
            BookingStatus::Confirmed => "Confirmed: your stay at {$resort} ({$reference})",
            BookingStatus::Rejected => "About your booking request {$reference}",
            BookingStatus::Cancelled => "Cancelled: reservation {$reference}",
            BookingStatus::Completed => "Thank you for visiting {$resort}",
            default => "Reservation {$reference} updated",
        };
    }
}
