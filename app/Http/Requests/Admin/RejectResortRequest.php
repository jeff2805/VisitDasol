<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning down a resort always needs a reason: the owner sees it on their
 * dashboard and fixes the listing before sending it back.
 */
class RejectResortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against ResortPolicy.
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'review_remarks' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'review_remarks.required' => 'Tell the owner what to fix.',
            'review_remarks.min' => 'Please write at least a short sentence.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['review_remarks' => 'reason'];
    }
}
