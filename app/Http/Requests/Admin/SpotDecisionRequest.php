<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The office approving or sending back a destination.
 *
 * A reason is required when sending one back and optional when approving.
 * A barangay that gets a spot returned with no explanation cannot act on it,
 * and will send the same thing again.
 */
class SpotDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against DestinationPolicy.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('review_remarks'))) {
            $this->merge(['review_remarks' => Str::squish($this->input('review_remarks'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $sendingBack = $this->routeIs('admin.spot-submissions.reject');

        return [
            'review_remarks' => [
                $sendingBack ? 'required' : 'nullable',
                'string',
                $sendingBack ? 'min:10' : 'min:0',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'review_remarks.required' => 'Say what needs changing, so the barangay can correct it.',
            'review_remarks.min' => 'A few words more: this is the only thing the barangay will see.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['review_remarks' => 'note to the barangay'];
    }
}
