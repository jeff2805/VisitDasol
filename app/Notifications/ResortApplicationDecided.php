<?php

namespace App\Notifications;

use App\Models\Resort;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an owner what the tourism office decided about their listing.
 *
 * This is the message that answers the commonest question owners ask: "I
 * submitted my resort, why can visitors not see it?" A pending listing is
 * invisible on purpose, and until now nothing ever said so.
 *
 * Not queued; see BookingRequested for why.
 */
class ResortApplicationDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Resort $resort,
        public readonly bool $approved,
        public readonly ?string $remarks = null,
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
        $office = config('visitdasol.contact.office');
        $name = $this->resort->name;

        $mail = (new MailMessage)
            ->subject($this->approved
                ? "{$name} is now listed on ".config('visitdasol.name')
                : "Changes needed before {$name} can be listed")
            ->greeting('Hello '.$notifiable->first_name.',');

        if ($this->approved) {
            $mail->line("The {$office} has approved {$name}. It is now visible to visitors and can take bookings.")
                ->line('Add your room types and rates if you have not already, because a listing with no rooms cannot be booked.')
                ->action('Open your resort', route('owner.resort.show'));
        } else {
            $mail->line("The {$office} reviewed {$name} and it cannot be listed as it stands.")
                ->line(filled($this->remarks) ? '**What needs changing:** '.$this->remarks : 'No details were given.')
                ->line('You can edit the listing and send it back for another review.')
                ->action('Edit your resort', route('owner.resort.edit'));
        }

        return $mail->salutation($office.', '.config('visitdasol.municipality'));
    }
}
