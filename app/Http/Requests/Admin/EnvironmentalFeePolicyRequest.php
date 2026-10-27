<?php

namespace App\Http\Requests\Admin;

use App\Enums\FeeScope;
use App\Models\EnvironmentalFeePolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configuring the municipal environmental fee.
 *
 * The amount is REQUIRED here, which looks at odds with a column that
 * is nullable — it is not. Null means nobody has configured a rate; the
 * moment somebody uses this form they are configuring one, and a blank
 * figure at that point is a mistake rather than a statement.
 *
 * enabled is not validated here. Switching collection on is its own
 * action with its own audit entry, because it is the difference between
 * writing down what an ordinance says and beginning to take money.
 */
class EnvironmentalFeePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against EnvironmentalFeePolicyPolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Non-negative, and above zero: a fee of nothing is not a fee,
             * and recording one would produce receipts for ₱0.00. An
             * office that wants to stop collecting switches the module
             * off, which says so plainly.
             */
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],

            'scope' => ['required', Rule::enum(FeeScope::class)],

            'effective_from' => ['required', 'date_format:Y-m-d'],

            // Open-ended is normal: an ordinance usually runs until it is
            // repealed, not until a date somebody guessed at.
            'effective_until' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:effective_from',
            ],

            // The ordinance or resolution, in the office's own words.
            // Free text: this project does not model legislation.
            'authority_reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * No two switched-on policies may cover the same day.
     *
     * Checked here rather than left to a human, because an overlap is a
     * municipality with two answers to "what does it cost" — and a
     * visitor at a gate would be charged whichever the code happened to
     * pick first.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->validated('effective_from');
            $until = $this->validated('effective_until');

            $clash = EnvironmentalFeePolicy::query()
                ->where('enabled', true)
                ->whereKeyNot($this->route('policy')?->id)
                // Two ranges overlap unless one ends before the other starts.
                ->where('effective_from', '<=', $until ?? '9999-12-31')
                ->where(function ($query) use ($from): void {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $from);
                })
                ->first();

            if ($clash !== null) {
                $validator->errors()->add(
                    'effective_from',
                    'Another fee is already switched on for these dates ('
                    .$clash->effective_from->format('M j, Y')
                    .($clash->effective_until
                        ? ' to '.$clash->effective_until->format('M j, Y')
                        : ' onwards')
                    .'). Switch that one off first, or choose dates after it ends.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the fee set by the ordinance.',
            'amount.min' => 'A fee of nothing is not a fee. To stop collecting, switch the module off.',
            'scope.required' => 'Say what the fee is charged against.',
            'effective_from.required' => 'A fee cannot be collected without a date it takes effect.',
            'effective_until.after_or_equal' => 'The end date cannot come before the start date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'fee amount',
            'scope' => 'what the fee applies to',
            'effective_from' => 'effective date',
            'effective_until' => 'end date',
            'authority_reference' => 'ordinance reference',
        ];
    }
}
