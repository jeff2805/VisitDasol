<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning down an application always needs a reason: the applicant sees it
 * when they try to sign in, and it is kept in the audit trail.
 */
class RejectOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against UserPolicy.
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
            'review_remarks.required' => 'Give the applicant a reason for the decision.',
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
