<?php

namespace App\Services;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a notification without ever letting it undo the thing that caused it.
 *
 * Confirming a reservation and telling the guest about it are two different
 * jobs. If the mail server is slow, full, or simply down, the reservation is
 * still confirmed: the failure is written to the log and the person is told
 * nothing went wrong, because as far as the booking goes, nothing did.
 *
 * Without this, one Gmail hiccup would throw out of BookingStatusManager and
 * the owner would see an error page for a reservation that was in fact
 * confirmed and saved.
 */
class Notifier
{
    /**
     * @param  object|null  $notifiable  a User, or null when there is nobody
     *                                   to tell (a deleted account, say)
     */
    public function send(?object $notifiable, Notification $notification): void
    {
        if ($notifiable === null) {
            return;
        }

        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            Log::warning('Could not send '.$notification::class, [
                'to' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
