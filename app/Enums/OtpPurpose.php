<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What a one-time code was issued for. Stored in
 * email_verification_codes.purpose.
 *
 * TWO CODES THAT LOOK ALIKE AND MEAN DIFFERENT THINGS.
 *
 * A registration code proves the address on a brand-new account is real, and
 * accepting one marks the address confirmed for good. A sign-in code proves
 * whoever typed the password is also reading that mailbox, and accepting one
 * confirms nothing permanent — it opens a single session.
 *
 * Kept apart so neither can stand in for the other: a sign-in code must not
 * be able to confirm an address, and the audit trail must not record "the
 * email address was confirmed" every time somebody signs in.
 */
enum OtpPurpose: string
{
    use EnumHelpers;

    case Registration = 'registration';
    case Login = 'login';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Email confirmation',
            self::Login => 'Sign-in code',
        };
    }

    /** Whether accepting this code confirms the address for good. */
    public function confirmsAddress(): bool
    {
        return $this === self::Registration;
    }

    /** What the audit trail records when a code of this kind is accepted. */
    public function logAction(): string
    {
        return match ($this) {
            self::Registration => 'account.email_verified',
            self::Login => 'account.login_otp_verified',
        };
    }

    public function logDescription(): string
    {
        return match ($this) {
            self::Registration => 'Confirmed the email address with a one-time code',
            self::Login => 'Signed in with a one-time code sent by email',
        };
    }
}
