<?php

namespace App\Notifications;

use App\Enums\DestinationStatus;
use App\Models\Destination;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a barangay official what the office decided about their spot.
 *
 * Not queued; see BookingRequested for why.
 */
class TouristSpotDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Destination $spot,
        public readonly DestinationStatus $outcome,
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
        $mail = (new MailMessage)
            ->subject($this->outcome === DestinationStatus::Active
                ? "Published: {$this->spot->name}"
                : "About your destination: {$this->spot->name}")
            ->greeting('Hello '.$notifiable->first_name.',');

        if ($this->outcome === DestinationStatus::Active) {
            $mail->line("{$this->spot->name} is now on Visit Dasol for everyone to see.")
                ->line('Thank you for writing it up. Barangay '.$this->spot->barangay
                    .' is on the map because somebody there took the trouble.')
                ->action('See it on the site', route('destinations.show', $this->spot));
        } else {
            $mail->line("The office has sent {$this->spot->name} back for correction.")
                ->line(filled($this->spot->review_remarks)
                    ? '**What needs changing:** '.$this->spot->review_remarks
                    : 'No reason was given.')
                ->line('Nothing is lost. Edit it and send it again.')
                ->action('Open the submission', route('barangay.spots.edit', $this->spot));
        }

        return $mail->salutation(config('visitdasol.contact.office').', '.config('visitdasol.municipality'));
    }
}
