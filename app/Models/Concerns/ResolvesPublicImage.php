<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored public-disk path into a browser URL,
 * falling back to the placeholder image when there is no file.
 */
trait ResolvesPublicImage
{
    protected function publicImageUrl(?string $path): string
    {
        return filled($path)
            ? Storage::disk('public')->url($path)
            : asset('images/placeholder.svg');
    }
}
