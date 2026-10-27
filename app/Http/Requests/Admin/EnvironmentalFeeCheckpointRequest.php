<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Naming a place where the fee is collected.
 *
 * Coordinates are held to the municipal bounds the rest of the system
 * already enforces, so a checkpoint cannot be dropped in the next town.
 */
class EnvironmentalFeeCheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against EnvironmentalFeeCheckpointPolicy.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bounds = config('visitdasol.map.bounds');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                Rule::unique('environmental_fee_checkpoints', 'name')
                    ->ignore($this->route('checkpoint')?->id),
            ],

            // One of Dasol's own 18. A checkpoint in a barangay the
            // municipality does not have is a typo, not a location.
            'barangay' => ['required', Rule::in(config('visitdasol.barangays'))],

            'description' => ['nullable', 'string', 'max:1000'],

            'latitude' => [
                'required', 'numeric',
                'between:'.$bounds['south'].','.$bounds['north'],
            ],

            'longitude' => [
                'required', 'numeric',
                'between:'.$bounds['west'].','.$bounds['east'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a checkpoint with this name.',
            'barangay.in' => 'Choose one of Dasol\'s barangays.',
            'latitude.between' => 'That point is outside Dasol. Pick it on the map.',
            'longitude.between' => 'That point is outside Dasol. Pick it on the map.',
        ];
    }
}
