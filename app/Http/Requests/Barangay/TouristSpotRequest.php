<?php

namespace App\Http\Requests\Barangay;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TouristSpotRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        // Authorization is handled by DestinationPolicy in the controller.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['name', 'address', 'payment_instructions'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = Str::squish($this->input($field));
            }
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $bounds = config('visitdasol.map.bounds');

        return [
            // Destination details
            'category_id' => [
                'required',
                Rule::exists('destination_categories', 'id'),
            ],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['required', 'string', 'min:40', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],

            // Keep the map pin within Dasol's configured bounds.
            'latitude' => [
                'required',
                'numeric',
                'between:'.$bounds['south'].','.$bounds['north'],
            ],
            'longitude' => [
                'required',
                'numeric',
                'between:'.$bounds['west'].','.$bounds['east'],
            ],

            // Existing image handling
            'main_image' => ['nullable', ...$this->imageRules()],
            'remove_main_image' => ['nullable', 'boolean'],

            /*
             * ENTRANCE FEE: NOT HERE YET, AND DELIBERATELY SO.
             *
             * Rules for entrance_fee_type, entrance_fee,
             * payment_options and payment_instructions used to sit in
             * this list. Nothing else of the feature was ever built:
             * the destinations table has no such columns, Destination
             * does not accept them, and the form does not collect
             * them. The effect was that EVERY submission failed —
             * entrance_fee_type was required and never sent, and a
             * "free" spot was then refused for having no payment
             * option. A barangay official could not write up a spot at
             * all.
             *
             * Removed rather than half-mended. Putting it back needs,
             * in this order: a migration adding the columns, the
             * fields on Destination::$fillable with a casts entry for
             * the options array, the inputs on the form, and only then
             * these rules. Charging for entry is also a municipal
             * decision, not a schema one.
             */
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'description.min' =>
                'Write at least a couple of sentences, so a visitor knows what to expect.',

            'latitude.between' =>
                'Place the map pin inside Dasol.',

            'longitude.between' =>
                'Place the map pin inside Dasol.',

            'category_id.required' =>
                'Choose what kind of place this is.',

            // Entrance-fee messages went with their rules. See the note
            // in rules() for what finishing that feature needs.
        ];

        foreach ($this->imageMessages('photo') as $rule => $message) {
            $messages["main_image.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'main_image' => 'photo',
        ];
    }

    /**
     * Return only validated data for the destination submission service.
     *
     * Every key here must be on Destination::$fillable and must be a
     * real column. The entrance-fee fields were listed and were
     * neither, so fill() threw a MassAssignmentException and no spot
     * could be saved. See the note in rules().
     *
     * @return array<string, mixed>
     */
    public function spotData(): array
    {
        return $this->safe()->only([
            'category_id',
            'name',
            'description',
            'address',
            'latitude',
            'longitude',
        ]);
    }
}
