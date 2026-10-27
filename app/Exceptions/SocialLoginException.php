<?php

namespace App\Exceptions;

use App\Enums\SocialProvider;
use RuntimeException;

/**
 * A refusal to sign somebody in through Google or Facebook.
 *
 * Every message is written for the person who pressed the button, because the
 * controller shows it to them as-is. None of them says whether an account
 * exists, except where the visitor has already proved they hold the address.
 */
class SocialLoginException extends RuntimeException
{
    public static function unusable(SocialProvider $provider): self
    {
        return new self(
            $provider->label().' did not give us enough to sign you in. '
            .'Please try again, or use your email address and password.'
        );
    }

    public static function noEmail(SocialProvider $provider): self
    {
        return new self(
            'Your '.$provider->label().' account did not share an email address, '
            .'and Visit Dasol needs one to reach you about a booking. '
            .'Allow it on the '.$provider->label().' screen, or register with your email address instead.'
        );
    }

    /**
     * The account takeover, refused.
     *
     * It names the address on purpose: the visitor typed it into the provider
     * themselves, so this tells them nothing they did not already know, and
     * without it the refusal is impossible to act on.
     */
    public static function emailNotVerified(SocialProvider $provider, string $email): self
    {
        return new self(
            'There is already a Visit Dasol account for '.$email.', and '
            .$provider->label().' has not confirmed that the address is yours. '
            .'Please sign in with your email address and password instead.'
        );
    }

    public static function accountNotUsable(string $message): self
    {
        return new self($message);
    }
}
