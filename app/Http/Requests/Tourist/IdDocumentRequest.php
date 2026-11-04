<?php

namespace App\Http\Requests\Tourist;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The guest's government-issued ID, on its way to the private disk.
 *
 * BOTH the extension and the real content type are checked. `mimes` reads
 * the extension, which the sender picks; `mimetypes` reads what the file
 * actually is. A PHP script named licence.jpg passes the first and fails
 * the second, which is the only one of the two that matters here — the file
 * lands on a disk no web server maps a URL onto, and keeping it that way
 * starts with not storing an executable in the first place.
 *
 * The accepted kinds and the size ceiling come from config, which already
 * carries them for the owner's business permit. No second list.
 */
class IdDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against BookingPolicy::uploadId.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $uploads = config('visitdasol.uploads');

        return [
            'id_document' => [
                'required',
                'file',
                'mimes:'.implode(',', $uploads['document_mimes']),
                'mimetypes:image/jpeg,image/png,application/pdf',
                'max:'.$uploads['document_max_kb'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $sizeMb = round(config('visitdasol.uploads.document_max_kb') / 1024, 1);

        return [
            'id_document.required' => 'Choose a photo or PDF of your valid ID.',
            'id_document.file' => 'Choose a photo or PDF of your valid ID.',
            'id_document.mimes' => 'Upload your ID as a JPG, PNG, or PDF.',
            'id_document.mimetypes' => 'Upload your ID as a JPG, PNG, or PDF.',
            'id_document.max' => "Your ID must be {$sizeMb} MB or smaller.",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['id_document' => 'valid ID'];
    }
}
