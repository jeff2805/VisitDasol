<?php

namespace App\Http\Requests\Tourist;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A guest's rating and comment.
 *
 * Whether this person is allowed to review this stay is decided by
 * ReviewPolicy, not here. This only checks the shape of what they typed.
 */
class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against ReviewPolicy.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('comment'))) {
            $comment = trim($this->input('comment'));

            // An empty box means "no comment", not an empty string.
            $this->merge(['comment' => $comment === '' ? null : $comment]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
            'comment' => ['nullable', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Choose a rating from one to five stars.',
            'rating.between' => 'A rating is between one and five stars.',
            'comment.min' => 'If you write something, please make it at least 10 characters so it helps other visitors.',
        ];
    }
}
