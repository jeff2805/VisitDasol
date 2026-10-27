<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Calling off a stay the resort already confirmed. The guest planned
 * around it, so a reason is required.
 */
class CancelBookingRequest extends FormRequest
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
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Tell the guest why the stay is being called off.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['cancellation_reason' => 'reason'];
    }
}
