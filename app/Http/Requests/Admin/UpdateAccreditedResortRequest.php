<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccreditationStatus;
use App\Models\AccreditedResort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Correcting one row of the accreditation list by hand.
 *
 * The office needs this for the ordinary case where a single
 * accreditation is renewed or suspended between uploads, and
 * re-uploading the whole spreadsheet for one change is not reasonable.
 */
class UpdateAccreditedResortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against AccreditedResortPolicy.
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['resort_name', 'owner_name', 'barangay', 'accreditation_no'] as $field) {
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
        /** @var AccreditedResort|null $row */
        $row = $this->route('accreditedResort');

        return [
            'resort_name' => ['required', 'string', 'max:200'],
            'owner_name' => ['nullable', 'string', 'max:200'],
            'barangay' => ['nullable', 'string', 'max:120'],

            'accreditation_no' => [
                'nullable', 'string', 'max:60',
                Rule::unique('accredited_resorts', 'accreditation_no')->ignore($row?->id),
            ],

            'accreditation_status' => ['required', Rule::in(AccreditationStatus::values())],

            /*
             * No "after today" rule. An accreditation that lapsed last
             * year is a fact the office has to be able to record, and
             * refusing the date would leave the row saying "active"
             * forever — which is the one state that misleads a reviewer.
             */
            'valid_until' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accreditation_no.unique' => 'Another record already uses that accreditation number.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'resort_name' => 'resort name',
            'owner_name' => 'owner name',
            'accreditation_no' => 'accreditation number',
            'accreditation_status' => 'accreditation status',
            'valid_until' => 'validity date',
        ];
    }
}
