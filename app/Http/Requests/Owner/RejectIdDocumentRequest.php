<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refusing an ID needs a reason, for the same purpose as declining a
 * booking: the guest reads it and has to know what to send instead.
 *
 * "Rejected" on its own leaves somebody guessing between a blurred photo,
 * the wrong kind of document, and a name that does not match the booking.
 */
class RejectIdDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against BookingPolicy.
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tell the guest what was wrong, so they know what to send instead.',
            'reason.min' => 'Please write at least a short sentence.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'reason'];
    }
}
