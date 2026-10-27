<?php

namespace App\Services;

use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\SocialLoginException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Turning "continue with Google" into a Visit Dasol account.
 *
 * The whole of the risk in this feature is one question: when may a provider
 * be trusted to say who somebody is? Get it wrong and anybody who can make a
 * Facebook account with your email address can walk into your reservations.
 *
 * So there are three ways in, in this order, and no others:
 *
 *   1. This exact provider account has signed in before. Trust it; it is the
 *      provider's own id that is matched, never the email, because an email
 *      can change hands at the provider and the account must not follow it.
 *
 *   2. Nobody here has that email yet. Make a tourist account.
 *
 *   3. Somebody here does have that email. Link the two ONLY if the provider
 *      says it verified the address. Otherwise refuse and say why: this is
 *      the account takeover, and the refusal is the whole defence.
 *
 * New accounts are always tourists. Resort owners are approved by the office
 * before their account works, and the office's own accounts are made by hand;
 * neither is something to be handed out by pressing a button on Google.
 */
class SocialLogin
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @throws SocialLoginException
     */
    public function resolve(SocialProvider $provider, SocialUser $account): User
    {
        $providerId = (string) $account->getId();

        if ($providerId === '') {
            throw SocialLoginException::unusable($provider);
        }

        $existing = SocialAccount::query()
            ->where('provider', $provider->value)
            ->where('provider_id', $providerId)
            ->first();

        // 1. Seen this exact provider account before.
        if ($existing !== null) {
            $user = $existing->user;

            if ($user === null) {
                throw SocialLoginException::unusable($provider);
            }

            $this->guardStatus($user);
            $this->touch($existing, $account);

            return $user;
        }

        $email = Str::lower(trim((string) $account->getEmail()));

        if ($email === '') {
            throw SocialLoginException::noEmail($provider);
        }

        $user = User::query()->where('email', $email)->first();

        // 2. Nobody here has that email. A new tourist.
        if ($user === null) {
            return DB::transaction(function () use ($provider, $account, $email, $providerId): User {
                $user = $this->register($email, $account, $this->emailIsVerified($account));

                $this->link($user, $provider, $providerId, $account);

                $this->logger->log(
                    'user.registered_with_'.$provider->value,
                    $user,
                    "{$user->full_name} created an account with {$provider->label()}",
                    [],
                    $user,
                );

                return $user;
            });
        }

        /*
         * 3. The email is already somebody's. Linking is only safe when the
         *    provider says it checked the address; otherwise anyone could
         *    register that address at the provider and arrive here as them.
         */
        if (! $this->emailIsVerified($account)) {
            throw SocialLoginException::emailNotVerified($provider, $email);
        }

        $this->guardStatus($user);

        $this->link($user, $provider, $providerId, $account);

        $this->logger->log(
            'user.linked_'.$provider->value,
            $user,
            "{$user->full_name} linked their {$provider->label()} account",
            [],
            $user,
        );

        return $user;
    }

    /**
     * The same refusals the password form gives, for the same reasons. A
     * deactivated account must not become usable just because it arrived by
     * a different door.
     *
     * @throws SocialLoginException
     */
    private function guardStatus(User $user): void
    {
        if (! $user->isActive()) {
            throw SocialLoginException::accountNotUsable($user->status->loginMessage());
        }
    }

    private function register(string $email, SocialUser $account, bool $verified): User
    {
        [$first, $last] = $this->splitName((string) $account->getName(), $email);

        $user = new User([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            // A password is still set, to a value nobody knows: the column is
            // not nullable, and leaving a guessable one would be worse than
            // leaving none. They can set a real one through "forgot password".
            'password' => Str::random(64),
        ]);

        $user->forceFill([
            'role' => UserRole::Tourist,
            'status' => UserStatus::Active,
            // Only when the provider actually checked. Otherwise they go
            // through the same emailed code as everybody else.
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        return $user;
    }

    private function link(User $user, SocialProvider $provider, string $providerId, SocialUser $account): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider->value,
            'provider_id' => $providerId,
            'provider_email' => $account->getEmail(),
            'provider_name' => $account->getName(),
            'last_used_at' => now(),
        ]);
    }

    private function touch(SocialAccount $link, SocialUser $account): void
    {
        $link->forceFill([
            'provider_email' => $account->getEmail(),
            'provider_name' => $account->getName(),
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * Whether the provider says it checked the address.
     *
     * Google returns email_verified. Facebook does not return anything of the
     * kind, so a Facebook address is treated as unchecked — which is why
     * Facebook can create a new account but cannot be used to walk into one
     * that already exists.
     */
    private function emailIsVerified(SocialUser $account): bool
    {
        $raw = $account->getRaw();

        return ($raw['email_verified'] ?? false) === true
            || ($raw['verified_email'] ?? false) === true;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name, string $email): array
    {
        $name = Str::squish($name);

        if ($name === '') {
            // Better than a blank: the part of the address before the @, which
            // is at least something they will recognise as theirs.
            $name = Str::before($email, '@');
        }

        $parts = explode(' ', $name);
        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [
            Str::limit($first, 75, ''),
            Str::limit($last !== '' ? $last : $first, 75, ''),
        ];
    }
}
