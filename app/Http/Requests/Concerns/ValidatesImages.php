<?php

namespace App\Http\Requests\Concerns;

/**
 * One place for the photo upload rules, taken from config/visitdasol.php.
 * SVG is never allowed: it can contain scripts.
 */
trait ValidatesImages
{
    /**
     * @return list<mixed>
     */
    protected function imageRules(): array
    {
        $uploads = config('visitdasol.uploads');

        return [
            'image',
            'mimes:'.implode(',', $uploads['image_mimes']),
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.$uploads['image_max_kb'],
            'dimensions:min_width='.$uploads['image_min_width']
                .',min_height='.$uploads['image_min_height']
                .',max_width='.$uploads['image_max_width']
                .',max_height='.$uploads['image_max_height'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function imageMessages(string $attribute = 'photo'): array
    {
        $uploads = config('visitdasol.uploads');
        $sizeMb = round($uploads['image_max_kb'] / 1024, 1);

        return [
            'mimes' => 'Upload a JPG, PNG, or WEBP '.$attribute.'.',
            'mimetypes' => 'Upload a JPG, PNG, or WEBP '.$attribute.'.',
            'max' => 'Each '.$attribute.' must be '.$sizeMb.' MB or smaller.',
            'dimensions' => 'Each '.$attribute.' must be at least '
                .$uploads['image_min_width'].'x'.$uploads['image_min_height'].' pixels.',
        ];
    }
}
