<?php

namespace App\Http\Requests\Tourist;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A guest cancelling their own reservation.
 *
 * The reason is optional: plans change, and nobody should have to explain
 * themselves to free a room. When one is given, the resort sees it.
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
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return $this->filled('cancellation_reason')
            ? (string) $this->validated('cancellation_reason')
            : 'Cancelled by the guest.';
    }
}
