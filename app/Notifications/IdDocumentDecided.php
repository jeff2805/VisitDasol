<?php

namespace App\Notifications;

use App\Enums\IdVerificationStatus;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest what the resort decided about their valid ID.
 *
 * Worth a mail of its own rather than waiting for the next thing: a
 * rejected ID stops the party being checked in, and a guest who only finds
 * that out at the gate has driven to Dasol to be turned away.
 *
 * NOTHING FROM THE DOCUMENT IS IN HERE. Not the ID number, not the name on
 * it, not the file name. The mail says which reservation, what was decided,
 * and — when it was refused — the reason the resort gave, which is the
 * resort's own words about the picture, never a reading of it. Mail is not
 * a private channel and this class behaves as though it is not.
 *
 * Not queued, for the reason BookingRequested gives.
 */
class IdDocumentDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly IdVerificationStatus $outcome,
        public readonly ?string $reason = null,
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
        $reference = $booking->booking_reference;

        $mail = (new MailMessage)
            ->subject($this->outcome === IdVerificationStatus::Verified
                ? "Your ID is verified for {$reference}"
                : "About the ID you sent for {$reference}")
            ->greeting('Hello '.$notifiable->first_name.',');

        if ($this->outcome === IdVerificationStatus::Verified) {
            $mail->line("{$resort} has verified the valid ID for your reservation {$reference}.")
                ->line('Nothing else is needed before you arrive. Bring the same ID with you, '
                    .'as the resort will ask to see it at the desk.');
        } else {
            $mail->line("{$resort} could not accept the ID you sent for reservation {$reference}.")
                ->line(filled($this->reason)
                    ? '**Reason given:** '.$this->reason
                    : 'No reason was given.')
                ->line('Please send another government-issued ID from your reservation page. '
                    .'Your booking is unaffected, but the resort cannot check you in until an ID is verified.');
        }

        return $mail
            ->action('Open your reservation', route('tourist.bookings.show', $booking))
            ->salutation(config('visitdasol.contact.office').', '.config('visitdasol.municipality'));
    }
}
