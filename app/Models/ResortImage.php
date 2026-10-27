<?php

namespace App\Models;

use App\Models\Concerns\ResolvesPublicImage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gallery photo of a resort.
 * "path" always comes from Storage::store(), never from user input.
 *
 * @property-read string $url
 */
class ResortImage extends Model
{
    use ResolvesPublicImage;

    protected $fillable = [
        'path',
        'caption',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => $this->publicImageUrl($this->path));
    }
}
