<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesUserInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The office creating an account for a barangay official.
 *
 * Deliberately not a public registration form. Anyone can type "I am the
 * punong barangay of Malacapas"; only the office knows whether that is true,
 * and an account that can put claims about a real barangay in front of
 * visitors is not something to hand out on a self-service page.
 *
 * role, status and barangay are set by the controller.
 */
class BarangayOfficialRequest extends FormRequest
{
    use NormalizesUserInput;

    public function authorize(): bool
    {
        return true; // The controller authorizes against UserPolicy.
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
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'barangay' => ['required', Rule::in(config('visitdasol.barangays'))],
            'position' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'barangay.required' => 'Choose which barangay this official speaks for.',
            'barangay.in' => 'Choose one of the 18 barangays of Dasol.',
            'email.unique' => 'An account with that email address already exists.',
        ];
    }
}
