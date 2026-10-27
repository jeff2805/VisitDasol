<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The office counting what a collector handed in.
 *
 * Notice what is NOT accepted here either: the status. Whether a
 * handover agrees is derived in the service from the counted figure
 * against the receipts. An officer who could post the status directly
 * could mark a short envelope "agreed" from a menu, and the whole
 * reconciliation would be decoration.
 */
class EnvironmentalFeeRemittanceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against
        // EnvironmentalFeeRemittancePolicy::review().
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('counted_amount'))) {
            $this->merge([
                'counted_amount' => str_replace([',', ' '], '', trim($this->input('counted_amount'))),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],

            /*
             * Only required when the figures disagree, and that is
             * settled on the server — the service refuses a disputed
             * count with no note. The rule here is the courteous half:
             * it catches it on the form instead of as an error page.
             */
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counted_amount.required' => 'Enter how much you actually counted.',
            'counted_amount.numeric' => 'Enter the amount in pesos, for example 1250.00.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'counted_amount' => 'amount counted',
            'review_note' => 'note',
        ];
    }
}
