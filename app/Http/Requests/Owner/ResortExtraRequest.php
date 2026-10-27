<?php

namespace App\Http\Requests\Owner;

use App\Enums\ExtraPriceUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * An owner adding or editing something their resort offers.
 *
 * resort_id and active are not here: the resort comes from the
 * signed-in owner, and switching an offering off is its own action.
 */
class ResortExtraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against ResortExtraPolicy.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $resortId = $this->user()->resort()->first()?->id;

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                Rule::unique('resort_extras', 'name')
                    ->where('resort_id', $resortId)
                    ->ignore($this->route('extra')?->id),
            ],

            'description' => ['nullable', 'string', 'max:1000'],

            'price' => ['required', 'numeric', 'min:1', 'max:1000000'],

            'price_unit' => ['required', Rule::enum(ExtraPriceUnit::class)],

            /*
             * A ceiling the guest cannot go past. Stops a typo in a
             * number box turning into a request for 500 kayaks, and
             * lets an owner say "one boat only" by setting it to 1.
             */
            'max_quantity' => ['required', 'integer', 'min:1', 'max:100'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already offer something with that name.',
            'price.min' => 'Enter the price in pesos.',
            'price_unit.required' => 'Say what the price covers — a person, an hour, or the whole booking.',
            'max_quantity.required' => 'Say the most a guest may ask for at once.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'price_unit' => 'what the price covers',
            'max_quantity' => 'most a guest may ask for',
            'sort_order' => 'position in the list',
        ];
    }
}
