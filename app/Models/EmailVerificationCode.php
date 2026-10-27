<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one-time code a new account must enter before it can use the site.
 *
 * Nothing here is mass assignable and the plain code is never stored:
 * App\Services\EmailVerificationService owns every write.
 *
 * @property-read bool $has_expired
 */
class EmailVerificationCode extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** True once the wrong code has been entered too many times. */
    public function isExhausted(): bool
    {
        return $this->attempts >= (int) config('visitdasol.otp.max_attempts');
    }

    /** Wrong guesses still allowed before this code is thrown away. */
    public function attemptsLeft(): int
    {
        return max(0, (int) config('visitdasol.otp.max_attempts') - $this->attempts);
    }

    /** Seconds left before this code stops working (0 once it has). */
    public function secondsUntilExpiry(): int
    {
        return max(0, (int) ceil(now()->diffInSeconds($this->expires_at, false)));
    }

    /**
     * Seconds left before another code may be sent (0 when it may be sent now).
     */
    public function secondsUntilResend(): int
    {
        $ready = $this->last_sent_at->addSeconds((int) config('visitdasol.otp.resend_cooldown_seconds'));

        // Carbon returns a float here. Round up, so a fraction of a second
        // still left never reads as "0 seconds" and re-enables the button early.
        return max(0, (int) ceil(now()->diffInSeconds($ready, false)));
    }
}
