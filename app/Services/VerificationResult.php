<?php

namespace App\Services;

/**
 * The outcome of checking a one-time code.
 *
 * Each case carries the sentence shown to the person, so the controller does
 * not have to decide what a failure means.
 */
enum VerificationResult
{
    case Verified;
    case Incorrect;
    case Expired;
    case TooManyAttempts;
    case NoCode;

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Why the code was refused. A fresh code is needed for every case except
     * Incorrect, where the person simply mistyped.
     */
    public function message(): string
    {
        return match ($this) {
            self::Verified => 'Your email address is confirmed.',
            self::Incorrect => 'That code is not correct. Please check the email and try again.',
            self::Expired => 'That code has expired. Ask for a new one below.',
            self::TooManyAttempts => 'Too many incorrect codes were entered, so that code no longer works. Ask for a new one below.',
            self::NoCode => 'There is no code waiting. Ask for a new one below.',
        };
    }

    /** True when the person must request a new code before trying again. */
    public function needsNewCode(): bool
    {
        return $this !== self::Verified && $this !== self::Incorrect;
    }
}
