<?php

namespace App\Notifications;

use App\Models\Destination;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Municipal Tourism Office that a barangay has sent in a spot.
 *
 * Not queued; see BookingRequested for why.
 */
class TouristSpotSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Destination $spot,
        public readonly User $official,
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
        return (new MailMessage)
            ->subject("New destination from Barangay {$this->spot->barangay}: {$this->spot->name}")
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line("{$this->official->full_name}, barangay official for {$this->spot->barangay}, "
                ."has sent in a destination for review.")
            ->line('**Spot:** '.$this->spot->name)
            ->line('**Barangay:** '.$this->spot->barangay)
            ->line('It is not visible to visitors until you approve it.')
            ->action('Read the submission', route('admin.spot-submissions.show', $this->spot))
            ->salutation(config('visitdasol.contact.office').', '.config('visitdasol.municipality'));
    }
}
