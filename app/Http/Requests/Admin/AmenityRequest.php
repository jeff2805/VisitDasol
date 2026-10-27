<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AmenityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against AmenityPolicy.
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
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('amenities', 'name')->ignore($this->route('amenity')?->id),
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
            'name.unique' => 'That amenity is already on the list.',
            'icon.regex' => 'Use a Bootstrap Icons name such as "wifi" or "cup-hot".',
        ];
    }
}
