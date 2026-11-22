<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The spreadsheet the office uploads.
 *
 * mimes and mimetypes both, as the rest of this project does for
 * uploads: the extension says what the file claims to be and the
 * sniffed type says what it is, and only a file that is both is taken.
 */
class AccreditationUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against AccreditedResortPolicy.
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,csv,txt',

                /*
                 * A CSV is plain text and every system labels it
                 * differently — Windows reports it as application/vnd.ms-excel,
                 * which is also what an old .xls claims. The extension
                 * rule above is what keeps .xls out.
                 */
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                    .'application/vnd.ms-excel,application/octet-stream,text/plain,text/csv,text/x-csv',

                'max:5120',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the accredited resorts file to upload.',
            'file.mimes' => 'Upload an Excel (.xlsx) or CSV file.',
            'file.mimetypes' => 'That file is not a spreadsheet. Upload an .xlsx or .csv file.',
            'file.max' => 'The file must be 5 MB or smaller.',
        ];
    }
}
