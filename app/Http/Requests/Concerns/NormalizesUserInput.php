<?php

namespace App\Http\Requests\Concerns;

use App\Rules\NotAMistypedEmailDomain;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cleans common account fields before validation:
 * lowercase email, trimmed names, phone numbers without spaces or dashes.
 */
trait NormalizesUserInput
{
    protected function normalizeUserInput(): void
    {
        $clean = [];

        if (is_string($this->input('email'))) {
            $clean['email'] = Str::lower(trim($this->input('email')));
        }

        foreach (['first_name', 'last_name', 'home_city', 'home_country', 'business_name', 'business_permit_no'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = Str::squish($this->input($field));
            }
        }

        if (is_string($this->input('phone'))) {
            $clean['phone'] = preg_replace('/[\s\-().]/', '', $this->input('phone'));
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function nameRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pM\s.\'-]+$/u'],
            'last_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pM\s.\'-]+$/u'],
        ];
    }

    /**
     * The email rules for creating an account.
     *
     * Lives here, shared, because the tourist form and the owner form had
     * a copy each and nothing kept them equal. An address good enough for
     * one and not the other is a bug waiting on whichever one gets edited
     * next.
     *
     * "strict" rejects the malformed shapes RFC 5322 merely tolerates;
     * NotAMistypedEmailDomain rejects the well-formed ones that go
     * nowhere, which is the larger problem. A sign-up is the one moment
     * the address can still be corrected: the person is at the keyboard
     * and knows what they meant. Every later chance to catch it — the
     * verification code that never arrives, the booking confirmation that
     * bounces — costs somebody a phone call to the office.
     *
     * Pass the current user's id when an existing account is editing its
     * own address, so the uniqueness check does not flag the address the
     * account already holds.
     *
     * @return array<string, list<mixed>>
     */
    protected function emailRules(?int $ignoreUserId = null): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email:rfc,strict',
                'max:255',
                new NotAMistypedEmailDomain,
                Rule::unique('users', 'email')->ignore($ignoreUserId),
            ],
        ];
    }

    /**
     * Philippine mobile or landline numbers, with an optional leading "+".
     *
     * @return list<string>
     */
    protected function phoneRule(): array
    {
        return ['string', 'regex:/^\+?[0-9]{7,15}$/'];
    }

    /**
     * @return array<string, string>
     */
    protected function userInputMessages(): array
    {
        return [
            'first_name.regex' => 'Use letters, spaces, periods, apostrophes, or hyphens only.',
            'last_name.regex' => 'Use letters, spaces, periods, apostrophes, or hyphens only.',
            'phone.regex' => 'Enter a valid phone number, for example 09171234567.',
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
            'terms.accepted' => 'Please confirm to continue.',
        ];
    }
}
