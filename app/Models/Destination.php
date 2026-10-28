<?php

namespace App\Models;

use App\Enums\DestinationStatus;
use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\ResolvesPublicImage;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination, written and published by the MTO.
 *
 * Not mass assignable: status, is_featured, main_image, slug, created_by.
 *
 * @property DestinationStatus $status
 * @property-read string $main_image_url
 */
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory, HasUniqueSlug, ResolvesPublicImage;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'address',
        'barangay',
        'latitude',
        'longitude',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_featured' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => DestinationStatus::class,
            'is_featured' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DestinationCategory::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(DestinationImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function mainImageUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->publicImageUrl($this->main_image));
    }

    public function isActive(): bool
    {
        return $this->status === DestinationStatus::Active;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', DestinationStatus::Active->value);
    }

    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    public function scopeWithStatus(Builder $query, DestinationStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /** Destinations a given account wrote. */
    public function scopeSubmittedBy(Builder $query, User $user): void
    {
        $query->where('created_by', $user->id);
    }
}
