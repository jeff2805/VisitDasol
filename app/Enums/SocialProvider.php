<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * The accounts a visitor may sign in with instead of a password.
 *
 * Tourists only. A resort owner's account is approved by the office before it
 * can be used, and the office's own accounts are created by hand, so neither
 * is something to be handed out by pressing a button on Google.
 */
enum SocialProvider: string
{
    use EnumHelpers;

    case Google = 'google';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Google => 'google',
            self::Facebook => 'facebook',
        };
    }

    /** True when this provider's keys are actually set. */
    public function isConfigured(): bool
    {
        $config = config('services.'.$this->value);

        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
    }

    /**
     * The providers ready to use right now.
     *
     * With no keys set the login page shows no buttons at all, rather than
     * buttons that lead to an error page.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p): bool => $p->isConfigured()));
    }
}
