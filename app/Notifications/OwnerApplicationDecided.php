<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant what the tourism office decided about their owner
 * account.
 *
 * This one matters more than most: a rejected applicant cannot sign in at
 * all, so without an email they have no way of learning why, or that a
 * decision was even made.
 *
 * Not queued; see BookingRequested for why.
 */
class OwnerApplicationDecided extends Notification
{
    use Queueable;

    public function __construct(
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

        $mail = (new MailMessage)
            ->subject($this->approved
                ? 'Your resort owner account has been approved'
                : 'About your resort owner application')
            ->greeting('Hello '.$notifiable->first_name.',');

        if ($this->approved) {
            $mail->line("The {$office} has approved your resort owner account.")
                ->line('You can now sign in and submit your resort for listing. The office reviews the listing itself before it appears to visitors.')
                ->action('Sign in', route('login'));

            if (filled($this->remarks)) {
                $mail->line('**Note from the office:** '.$this->remarks);
            }
        } else {
            $mail->line("The {$office} was not able to approve your resort owner application.")
                ->line(filled($this->remarks) ? '**Reason given:** '.$this->remarks : 'No reason was given.')
                ->line('If you believe this was a mistake, or you can supply what is missing, please contact the office directly.');
        }

        return $mail->salutation($office.', '.config('visitdasol.municipality'));
    }
}
