<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A collector handing in a period's takings.
 *
 * Notice what is NOT accepted here: the expected amount. That figure is
 * summed from the collections by the service, every time. Letting the
 * form supply it would mean the person being reconciled also supplied
 * the number they are reconciled against.
 */
class EnvironmentalFeeRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against EnvironmentalFeeRemittancePolicy
        // and the checkpoint policy. Both run on the server.
        return true;
    }

    protected function prepareForValidation(): void
    {
        /*
         * A thousands separator typed out of habit — "1,250.00" — is a
         * perfectly reasonable thing for somebody counting cash to
         * write, and numeric would refuse it.
         */
        if (is_string($this->input('declared_amount'))) {
            $this->merge([
                'declared_amount' => str_replace([',', ' '], '', trim($this->input('declared_amount'))),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'checkpoint_id' => ['required', 'integer', 'exists:environmental_fee_checkpoints,id'],

            'period_start' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'period_end' => [
                'required', 'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:period_start',
            ],

            /*
             * Zero is allowed on purpose. A collector who took money and
             * is handing over nothing is exactly the case a
             * reconciliation exists to catch, and refusing the figure
             * would only mean it never gets written down.
             */
            'declared_amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],

            'collector_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'checkpoint_id.required' => 'Choose which checkpoint these takings are from.',
            'checkpoint_id.exists' => 'That checkpoint does not exist.',
            'period_start.required' => 'Enter the first day you are handing in.',
            'period_start.before_or_equal' => 'That first day has not happened yet.',
            'period_end.required' => 'Enter the last day you are handing in.',
            'period_end.after_or_equal' => 'The last day cannot be before the first day.',
            'period_end.before_or_equal' => 'That last day has not happened yet.',
            'declared_amount.required' => 'Enter how much you are handing over.',
            'declared_amount.numeric' => 'Enter the amount in pesos, for example 1250.00.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'checkpoint_id' => 'checkpoint',
            'period_start' => 'first day',
            'period_end' => 'last day',
            'declared_amount' => 'amount handed over',
            'collector_note' => 'note',
        ];
    }
}
