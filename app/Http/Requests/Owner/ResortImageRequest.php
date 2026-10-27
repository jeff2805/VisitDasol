<?php

namespace App\Http\Requests\Owner;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Gallery photo upload. The remaining slots are worked out from the
 * photos the resort already has, so the limit cannot be exceeded by
 * uploading in several goes.
 */
class ResortImageRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return true; // The controller authorizes against ResortPolicy.
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
            $resort = $this->user()->resort()->first();

            if ($resort === null) {
                return;
            }

            $limit = config('visitdasol.uploads.gallery_limits.resort');
            $remaining = max(0, $limit - $resort->images()->count());
            $uploaded = count((array) $this->file('images', []));

            if ($uploaded > $remaining) {
                $validator->errors()->add('images', $remaining === 0
                    ? "You already have {$limit} photos. Remove one before adding another."
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
