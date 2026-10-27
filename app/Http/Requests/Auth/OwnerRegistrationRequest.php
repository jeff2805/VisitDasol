<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesUserInput;
use App\Models\OwnerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Resort owner application. Only the fields listed in rules() are used;
 * role, status, and review fields are set by the controller.
 */
class OwnerRegistrationRequest extends FormRequest
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
        $uploads = config('visitdasol.uploads');

        return [
            ...$this->nameRules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', ...$this->phoneRule()],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'business_name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', Rule::in(OwnerProfile::POSITIONS)],
            'business_permit_no' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'permit_document' => [
                'nullable',
                'file',
                'mimes:'.implode(',', $uploads['document_mimes']),
                'mimetypes:image/jpeg,image/png,application/pdf',
                'max:'.$uploads['document_max_kb'],
            ],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'business_name' => 'resort or business name',
            'business_permit_no' => 'business permit number',
            'permit_document' => 'permit document',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->userInputMessages(),
            'business_permit_no.regex' => 'Use letters, numbers, spaces, dashes, or slashes only.',
            'permit_document.mimes' => 'Upload a JPG, PNG, or PDF file.',
            'permit_document.mimetypes' => 'Upload a JPG, PNG, or PDF file.',
            'permit_document.max' => 'The file must be 5 MB or smaller.',
            'terms.accepted' => 'Please confirm that you are authorized to register this business.',
        ];
    }
}
