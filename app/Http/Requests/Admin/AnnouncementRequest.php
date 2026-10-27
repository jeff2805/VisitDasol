<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Writing or editing an announcement.
 *
 * status, published_at, is_featured, slug, and created_by are set by the
 * controller. The body is plain text and is always shown escaped, so an
 * announcement can never inject markup into a visitor's page.
 */
class AnnouncementRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return true; // The controller authorizes against AnnouncementPolicy.
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['title', 'excerpt'] as $field) {
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
        return [
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'min:20', 'max:20000'],
            'image' => ['nullable', ...$this->imageRules()],
            'remove_image' => ['nullable', 'boolean'],
            // Optional: leave empty to publish immediately when publishing.
            'published_at' => ['nullable', 'date', 'after_or_equal:'.now()->subYear()->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'body.min' => 'Write at least a sentence or two so visitors know what is happening.',
            'excerpt.max' => 'Keep the summary short; it appears on the home page.',
        ];

        foreach ($this->imageMessages('picture') as $rule => $message) {
            $messages["image.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function announcementData(): array
    {
        return $this->safe()->only(['title', 'excerpt', 'body']);
    }
}
