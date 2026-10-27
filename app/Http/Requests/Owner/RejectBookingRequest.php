<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining a request needs a reason: the guest reads it, and it is kept
 * in the audit trail.
 */
class RejectBookingRequest extends FormRequest
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
            'owner_remarks' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_remarks.required' => 'Tell the guest why, so they can look for other dates.',
            'owner_remarks.min' => 'Please write at least a short sentence.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['owner_remarks' => 'reason'];
    }
}
