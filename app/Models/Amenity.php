<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry on the shared amenity list.
 *
 * SHARED, which is the whole point: every resort picks from this one
 * table, and that is what lets a visitor filter the search by amenity
 * across the municipality. Resort owners may add to it; nothing in the
 * application renames, switches off or deletes an entry, because each
 * of those reaches into other people's listings.
 *
 * Which amenities a given resort HAS is the amenity_resort pivot, not
 * this.
 *
 * icon is a Bootstrap Icons name without the "bi-" prefix.
 */
class Amenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'icon',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function resorts(): BelongsToMany
    {
        return $this->belongsToMany(Resort::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
