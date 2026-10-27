<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesImages;
use App\Models\Destination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestinationImageRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return true; // The controller authorizes against DestinationPolicy.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1'],
            'images.*' => $this->imageRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $destination = $this->route('destination');

            if (! $destination instanceof Destination) {
                return;
            }

            $limit = config('visitdasol.uploads.gallery_limits.destination');
            $remaining = max(0, $limit - $destination->images()->count());
            $uploaded = count((array) $this->file('images', []));

            if ($uploaded > $remaining) {
                $validator->errors()->add('images', $remaining === 0
                    ? "This destination already has {$limit} photos. Remove one before adding another."
                    : "You can add {$remaining} more ".str('photo')->plural($remaining).'.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = ['images.required' => 'Choose at least one photo to upload.'];

        foreach ($this->imageMessages('photo') as $rule => $message) {
            $messages["images.*.{$rule}"] = $message;
        }

        return $messages;
    }
}
