<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The tourism office sending a walk-in record back to the resort.
 *
 * The remarks are the whole value of the action. A record returned with no
 * reason is one the resort cannot act on, and the office ends up explaining
 * it by telephone — which is the work this module exists to save.
 */
class ReturnWalkInRequest extends FormRequest
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
            'return_remarks' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'return_remarks.required' => 'Say what needs correcting, so the resort knows what to change.',
            'return_remarks.min' => 'Please write at least a short sentence.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['return_remarks' => 'remarks'];
    }
}
