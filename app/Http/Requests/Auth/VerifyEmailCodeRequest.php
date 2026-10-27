<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one-time code typed on the confirmation screen.
 *
 * Spaces and dashes are stripped first, so a code copied out of an email as
 * "123 456" is accepted. Whether the code is right is decided by
 * App\Services\EmailVerificationService, not here.
 */
class VerifyEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => preg_replace('/[\s-]+/', '', (string) $this->input('code')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $length = (int) config('visitdasol.otp.length');

        return [
            'code' => ['required', 'string', 'digits:'.$length],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $length = (int) config('visitdasol.otp.length');

        return [
            'code.required' => 'Enter the code from your email.',
            'code.digits' => "The code is {$length} digits.",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'confirmation code'];
    }
}
