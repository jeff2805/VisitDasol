<?php

namespace App\Http\Requests\Owner;

use App\Http\Requests\Concerns\ValidatesImages;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RoomImageRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return true; // The controller authorizes against RoomPolicy.
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
            $room = $this->route('room');

            if (! $room instanceof Room) {
                return;
            }

            $limit = config('visitdasol.uploads.gallery_limits.room');
            $remaining = max(0, $limit - $room->images()->count());
            $uploaded = count((array) $this->file('images', []));

            if ($uploaded > $remaining) {
                $validator->errors()->add('images', $remaining === 0
                    ? "This room type already has {$limit} photos. Remove one before adding another."
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
