<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\NormalizesUserInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit your own name, contact details, and (for tourists) home location.
 * Changing the email address requires the current password.
 * Role and status can never be changed here.
 */
class UpdateProfileRequest extends FormRequest
{
    use NormalizesUserInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
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
        $user = $this->user();

        return [
            ...$this->nameRules(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => [Rule::requiredIf($user->isResortOwner()), 'nullable', ...$this->phoneRule()],
            'home_city' => ['nullable', 'string', 'max:120'],
            'home_country' => ['nullable', 'string', 'max:80'],
            'current_password' => [
                Rule::requiredIf(fn () => $this->emailIsChanging()),
                'nullable',
                'string',
                'current_password:web',
            ],
        ];
    }

    public function emailIsChanging(): bool
    {
        return is_string($this->input('email')) && $this->input('email') !== $this->user()->email;
    }

    /**
     * Only the fields this user is allowed to change.
     *
     * @return array<string, mixed>
     */
    public function profileData(): array
    {
        $fields = ['first_name', 'last_name', 'email', 'phone'];

        if ($this->user()->isTourist()) {
            array_push($fields, 'home_city', 'home_country');
        }

        return $this->safe()->only($fields);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->userInputMessages(),
            'current_password.required' => 'Enter your current password to change your email address.',
            'current_password.current_password' => 'Your current password is incorrect.',
            'phone.required' => 'Resort owners must keep a contact number on file.',
        ];
    }
}
