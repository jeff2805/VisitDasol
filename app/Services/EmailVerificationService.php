<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\SignInCode;
use App\Notifications\VerifyEmailWithCode;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the one-time codes that confirm an email address.
 *
 *   $service->send($user);                 // e-mails a fresh code
 *   $service->verify($user, '123456');     // Result::Verified on success
 *
 * Rules enforced here, not in the controller:
 * - only one code is outstanding per account; sending replaces the old one;
 * - the plain code exists only long enough to be e-mailed, never stored;
 * - a code expires, and dies after a handful of wrong guesses, so a six-digit
 *   code cannot be guessed even by a script.
 */
class EmailVerificationService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Replaces any outstanding code with a new one and e-mails it.
     * Returns the plain code so local development can display it; callers
     * on a real server should ignore it.
     */
    public function send(User $user, OtpPurpose $purpose = OtpPurpose::Registration): string
    {
        $code = $this->generateCode();

        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id, 'purpose' => $purpose->value],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes((int) config('visitdasol.otp.ttl_minutes')),
                'last_sent_at' => now(),
            ],
        );

        $user->notify($purpose === OtpPurpose::Login
            ? new SignInCode($code)
            : new VerifyEmailWithCode($code));

        $this->flashCodeDuringLocalDevelopment($code);

        return $code;
    }

    /**
     * On a developer's machine there is no mail server: MAIL_MAILER=log writes
     * the message to storage/logs/laravel.log instead of sending it. Rather
     * than make every walk through the sign-up flow mean opening a log file,
     * the code is put in the session for the next page to show.
     *
     * This lives here, at the one place a code is ever sent, so no caller can
     * forget it. Both conditions are required, so it can never happen on a
     * real server: APP_ENV must be local AND mail must still go to the log.
     */
    private function flashCodeDuringLocalDevelopment(string $code): void
    {
        if (app()->environment('local') && config('mail.default') === 'log') {
            session()->flash('development_code', $code);
        }
    }

    /**
     * Checks a code the person typed and, when it matches, marks the address
     * confirmed and clears the code.
     */
    public function verify(User $user, string $code, OtpPurpose $purpose = OtpPurpose::Registration): VerificationResult
    {
        $record = $this->outstanding($user, $purpose);

        if ($record === null) {
            return VerificationResult::NoCode;
        }

        if ($record->hasExpired()) {
            $record->delete();

            return VerificationResult::Expired;
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            if ($record->refresh()->isExhausted()) {
                $record->delete();

                return VerificationResult::TooManyAttempts;
            }

            return VerificationResult::Incorrect;
        }

        $record->delete();

        /*
         * Only a registration code confirms the address for good. A sign-in
         * code proves the same thing in the moment, but marking the address
         * verified from here would quietly repair an account that had never
         * finished confirming — and the audit trail would then say the
         * address was confirmed on a day nobody confirmed anything.
         */
        if ($purpose->confirmsAddress()) {
            User::withoutTimestamps(fn () => $user->forceFill(['email_verified_at' => now()])->save());
        }

        $this->logger->log(
            $purpose->logAction(),
            $user,
            $purpose->logDescription(),
            [],
            $user,
        );

        return VerificationResult::Verified;
    }

    /** The outstanding code of this kind for this account, if there is one. */
    public function outstanding(User $user, OtpPurpose $purpose = OtpPurpose::Registration): ?EmailVerificationCode
    {
        return EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->first();
    }

    /**
     * Seconds the person must wait before another code may be sent.
     * Zero means a code may be sent right now.
     */
    public function secondsUntilResend(User $user, OtpPurpose $purpose = OtpPurpose::Registration): int
    {
        return $this->outstanding($user, $purpose)?->secondsUntilResend() ?? 0;
    }

    /**
     * A code of exactly the configured length, drawn from a source fit for
     * security. Leading zeros are kept, so "004321" is a valid code.
     */
    private function generateCode(): string
    {
        $length = (int) config('visitdasol.otp.length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
