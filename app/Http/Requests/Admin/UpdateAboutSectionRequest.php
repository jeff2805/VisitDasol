<?php

namespace App\Http\Requests\Admin;

use App\Enums\AboutSection as Section;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One section of the About page, as the office's form posts it.
 *
 * The text rules come from the section itself (AboutSection::rules), so the
 * shape of a section is declared in one place. What this adds is everything
 * that is the same whichever section is being saved: the picture rules.
 *
 * PICTURES ARE CHECKED AGAINST THE SITE'S OWN UPLOAD SETTINGS, not against
 * numbers written here. The office already decided what a picture may be
 * for resorts and destinations; an About page that quietly accepted a 40 MB
 * bitmap would be the odd one out, and the first page to fill the disk.
 */
class UpdateAboutSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against AboutSectionPolicy.
    }

    public function section(): Section
    {
        return Section::from((string) $this->route('section'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $section = $this->section();

        $mimes = implode(',', (array) config('visitdasol.uploads.image_mimes'));
        $maxKb = (int) config('visitdasol.uploads.image_max_kb');

        $image = ['nullable', 'image', 'mimes:'.$mimes, 'max:'.$maxKb];

        $rules = $section->rules();

        if ($section->hasImage()) {
            $rules['image'] = $image;
            // A picture the office has decided the section is better without.
            $rules['remove_image'] = ['nullable', 'boolean'];
        }

        if ($section->hasGallery()) {
            $limit = (int) config('visitdasol.uploads.gallery_limits.destination', 10);

            $rules['new_images'] = ['nullable', 'array', 'max:'.$limit];
            $rules['new_images.*'] = $image;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKb = (int) config('visitdasol.uploads.image_max_kb');

        return [
            'image.image' => 'That file is not a picture.',
            'image.max' => 'Pictures must be under '.round($maxKb / 1024, 1).' MB.',
            'new_images.*.image' => 'One of those files is not a picture.',
            'new_images.*.max' => 'Pictures must be under '.round($maxKb / 1024, 1).' MB.',
            'paragraphs.*.required' => 'A paragraph cannot be left empty. Remove it instead.',
            'destination_ids.*.exists' => 'One of those destinations no longer exists.',
        ];
    }

    /** Unticked checkboxes do not post at all. */
    protected function prepareForValidation(): void
    {
        if ($this->section() === Section::OwnerCta) {
            $this->merge(['visible' => $this->boolean('visible')]);
        }
    }
}
