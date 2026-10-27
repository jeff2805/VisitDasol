<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a tourist destination.
 * status, is_featured, slug, and created_by are set by the controller.
 */
class DestinationRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return true; // The controller authorizes against DestinationPolicy.
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['name', 'address', 'barangay'] as $field) {
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
            'category_id' => ['required', Rule::exists('destination_categories', 'id')],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['required', 'string', 'min:40', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', Rule::in(config('visitdasol.barangays'))],
            'latitude' => ['required', 'numeric', 'between:'.$bounds['south'].','.$bounds['north']],
            'longitude' => ['required', 'numeric', 'between:'.$bounds['west'].','.$bounds['east']],
            'main_image' => ['nullable', ...$this->imageRules()],
            'remove_main_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'description.min' => 'Write at least a couple of sentences for visitors.',
            'barangay.in' => 'Choose one of the 18 barangays of Dasol.',
            'latitude.between' => 'Place the map pin inside Dasol.',
            'longitude.between' => 'Place the map pin inside Dasol.',
            'category_id.required' => 'Choose a category.',
        ];

        foreach ($this->imageMessages('cover photo') as $rule => $message) {
            $messages["main_image.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['category_id' => 'category', 'main_image' => 'cover photo'];
    }

    /**
     * @return array<string, mixed>
     */
    public function destinationData(): array
    {
        return $this->safe()->only([
            'category_id', 'name', 'description', 'address', 'barangay', 'latitude', 'longitude',
        ]);
    }
}
