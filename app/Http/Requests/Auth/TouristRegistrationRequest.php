<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesUserInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Tourist sign-up. Only the fields listed in rules() ever reach the database;
 * role and status are set by the controller.
 */
class TouristRegistrationRequest extends FormRequest
{
    use NormalizesUserInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUserInput();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->nameRules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', ...$this->phoneRule()],
            'home_city' => ['required', 'string', 'max:120'],
            'home_country' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->userInputMessages(),
            'phone.required' => 'Enter a mobile number so resorts can reach you about a reservation.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'home_city' => 'city or municipality',
            'home_country' => 'country',
        ];
    }
}
