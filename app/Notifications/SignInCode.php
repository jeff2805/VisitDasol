<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the one-time code that finishes signing in.
 *
 * A DIFFERENT MAIL FROM THE CONFIRMATION ONE, and not merely different
 * wording. This one arrives when somebody has typed the right password, so
 * an unexpected copy of it means a password is known to a stranger — the mail
 * has to say so, and say what to do about it. The confirmation mail arrives
 * before any password exists and carries no such warning.
 *
 * Not queued, for the same reason as the rest of the mail in this system:
 * there is no queue worker on the XAMPP setup, and a code that waits in the
 * jobs table is a code nobody receives.
 */
class SignInCode extends Notification
{
    use Queueable;

    /** Public so tests can read the code that was sent. */
    public function __construct(public readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('visitdasol.otp.ttl_minutes');

        return (new MailMessage)
            ->subject('Your '.config('visitdasol.name').' sign-in code: '.$this->code)
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('Somebody has just signed in to your account with your password. Enter this code to finish:')
            ->line('**'.$this->code.'**')
            // "in 1 minutes" is what a template writes and nobody says.
            ->line('The code stops working in '.$minutes.' '.\Illuminate\Support\Str::plural('minute', $minutes).'.')
            /*
             * The whole reason this mail exists. Somebody reading it who did
             * not just sign in has learned that their password is known to
             * another person, and the only useful next step is to change it.
             */
            ->line('**If this was not you, somebody knows your password.** '
                .'Nobody can reach your account without this code, but please '
                .'change your password as soon as you can.')
            ->salutation(config('visitdasol.contact.office').', '.config('visitdasol.municipality'));
    }
}
