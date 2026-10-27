<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use Database\Factories\DestinationCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination category such as Beach or Viewpoint (MTO-managed).
 */
class DestinationCategory extends Model
{
    /** @use HasFactory<DestinationCategoryFactory> */
    use HasFactory, HasUniqueSlug;

    protected $fillable = [
        'name',
        'icon',
    ];

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class, 'category_id');
    }
}
