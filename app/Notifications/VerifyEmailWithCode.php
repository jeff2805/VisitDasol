<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the one-time code that confirms a new account's address.
 *
 * Deliberately NOT queued: MAIL_MAILER is "log" in development and there is
 * no queue worker running on the XAMPP setup, so a queued notification would
 * sit in the jobs table and the code would never arrive.
 *
 * With MAIL_MAILER=log the message is written to storage/logs/laravel.log
 * instead of being sent.
 */
class VerifyEmailWithCode extends Notification
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
            ->subject('Your '.config('visitdasol.name').' confirmation code: '.$this->code)
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('Enter this code to confirm your email address and finish setting up your account:')
            ->line('**'.$this->code.'**')
            ->line("The code stops working in {$minutes} minutes.")
            ->line('If you did not create an account, you can ignore this email and nothing will happen.')
            ->salutation(config('visitdasol.contact.office').', '.config('visitdasol.municipality'));
    }
}
