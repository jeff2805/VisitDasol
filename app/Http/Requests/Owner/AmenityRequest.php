<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A resort owner adding something to the shared amenity list.
 *
 * The same rules the tourism office's form used, because the row being
 * written is the same row. Only the authority to write it has moved.
 */
class AmenityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route group already settles the role; the controller
        // authorizes the action itself.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }

        if (is_string($this->input('icon'))) {
            $this->merge(['icon' => Str::lower(trim($this->input('icon'))) ?: null]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Unique across the whole table, not just this owner's
             * resort. Two spellings of the same thing split the public
             * filter in two, and a visitor ticking "Wi-Fi" would then
             * miss every resort that wrote "WiFi".
             */
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('amenities', 'name'),
            ],

            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'That amenity is already on the list — tick it on your resort instead.',
            'icon.regex' => 'Use a Bootstrap Icons name such as "wifi" or "cup-hot".',
        ];
    }
}
