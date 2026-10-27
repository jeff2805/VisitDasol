<?php

namespace App\Http\Requests\Owner;

use App\Enums\LastMinuteRule;
use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentRequirement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An owner's payment terms.
 *
 * resort_id is not here: the controller takes the resort from the
 * signed-in owner, so a form cannot point a policy at someone else's
 * listing however it is edited.
 */
class PaymentPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against ResortPolicy.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // An unticked checkbox sends nothing, which has to read as "off"
        // and not as "leave it as it was".
        $this->merge(['allow_on_site' => $this->boolean('allow_on_site')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requirement' => ['required', Rule::enum(PaymentRequirement::class)],

            /*
             * Only asked for when a deposit is what the owner chose. A
             * resort taking nothing up front should not have to invent a
             * percentage to get past the form.
             */
            'deposit_percent' => [
                'exclude_unless:requirement,deposit',
                'required', 'integer', 'min:1', 'max:100',
            ],

            'deadline_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'deadline_reference' => ['required', Rule::enum(PaymentDeadlineReference::class)],

            'last_minute_rule' => ['required', Rule::enum(LastMinuteRule::class)],
            'last_minute_hours' => ['required', 'integer', 'min:0', 'max:720'],

            'allow_on_site' => ['boolean'],
        ];
    }

    /**
     * The values to store.
     *
     * A deposit percentage is kept at whatever it was when the policy is
     * not a deposit one, so an owner who switches to full payment and
     * back does not lose the figure they had chosen.
     *
     * @return array<string, mixed>
     */
    public function policyAttributes(int $currentPercent): array
    {
        $validated = $this->safe()->except(['deposit_percent']);

        $validated['deposit_percent'] = $this->input('requirement') === PaymentRequirement::Deposit->value
            ? (int) $this->validated('deposit_percent')
            : max(1, $currentPercent);

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deposit_percent.required' =>
                'Enter the share of the bill you take as a deposit.',
            'deposit_percent.min' =>
                'A deposit of nothing is the same as no deposit. Choose "No advance payment" instead.',
            'deadline_hours.min' =>
                'Give guests at least an hour to pay.',
            'deadline_hours.max' =>
                'A deadline longer than a year is not a deadline.',
            'last_minute_hours.max' =>
                'A last-minute window longer than 30 days would cover almost every booking.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'requirement' => 'payment requirement',
            'deposit_percent' => 'deposit',
            'deadline_hours' => 'payment deadline',
            'deadline_reference' => 'deadline start',
            'last_minute_rule' => 'last-minute rule',
            'last_minute_hours' => 'last-minute window',
            'allow_on_site' => 'payment at the resort',
        ];
    }
}
