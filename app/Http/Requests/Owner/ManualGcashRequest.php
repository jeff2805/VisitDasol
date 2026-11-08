<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A resort's GCash account details.
 *
 * resort_id is not here: the controller takes the resort from the
 * signed-in owner, so a form cannot point an account at someone else's
 * listing however it is edited.
 */
class ManualGcashRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against ResortPolicy.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        if (is_string($this->input('account_name'))) {
            $clean['account_name'] = Str::squish($this->input('account_name'));
        }

        /*
         * Spaces, dashes and brackets are how people write a mobile
         * number down, and none of them is part of it. Stripped before
         * the rule below so "0917 123 4567" is accepted rather than
         * refused for punctuation the owner cannot see is a problem.
         */
        if (is_string($this->input('gcash_number'))) {
            $clean['gcash_number'] = preg_replace('/[\s\-().+]/', '', $this->input('gcash_number'));
        }

        if (is_string($this->input('instructions'))) {
            $clean['instructions'] = trim($this->input('instructions')) ?: null;
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'min:2', 'max:120'],

            /*
             * A Philippine mobile number, which is what GCash runs on:
             * 09XXXXXXXXX, or the same number written 639XXXXXXXXX.
             *
             * It was 'string|max:30' and then stripped to digits on the
             * way in, so "ask me on Facebook" was stored as an empty
             * string and the guest was shown an account with no number
             * to send money to.
             */
            'gcash_number' => ['required', 'string', 'regex:/^(?:0|63)9\d{9}$/'],

            'instructions' => ['nullable', 'string', 'max:500'],
            'active' => ['nullable', 'boolean'],

            /*
             * Whether this resort takes payment on arrival.
             *
             * ASKED HERE, STORED ELSEWHERE. The column is
             * resort_payment_policies.allow_on_site, which already held
             * this answer and which every booking snapshots. This page
             * is where an owner decides which methods they accept, so
             * the control belongs here; a second column would be a
             * second answer to one question, and two answers drift.
             *
             * ManualGcashController::syncOnSite() writes it.
             */
            'allow_on_site' => ['nullable', 'boolean'],

            /*
             * Both the extension and the real content type, the way every
             * other upload in this project is checked. `mimes` reads the
             * name, which the sender picks; `mimetypes` reads what the
             * file actually is. SVG is never accepted anywhere here: it
             * can carry script.
             */
            'qr_image' => [
                'nullable',
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:2048',
            ],

            'remove_qr' => ['nullable', 'boolean'],
        ];
    }

    /** The number as it should be stored: digits only, no country prefix. */
    public function gcashNumber(): string
    {
        $digits = (string) $this->validated('gcash_number');

        // 639171234567 and 09171234567 are the same number. Stored one
        // way so the guest's page and the owner's agree on what it is.
        return Str::startsWith($digits, '63')
            ? '0'.substr($digits, 2)
            : $digits;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_name.required' =>
                'Enter the name on your GCash account, so guests know who they are paying.',

            'gcash_number.required' =>
                'Enter the GCash number guests should send money to.',

            'gcash_number.regex' =>
                'Enter a GCash mobile number, for example 09171234567.',

            'qr_image.image' =>
                'Upload a picture of your GCash QR code.',

            'qr_image.mimes' =>
                'Upload your QR code as a PNG, JPG, or WEBP.',

            'qr_image.mimetypes' =>
                'Upload your QR code as a PNG, JPG, or WEBP.',

            'qr_image.max' =>
                'Your QR code image must be 2 MB or smaller.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_name' => 'GCash account name',
            'gcash_number' => 'GCash number',
            'qr_image' => 'QR code',
        ];
    }
}
