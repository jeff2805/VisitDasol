<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

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
