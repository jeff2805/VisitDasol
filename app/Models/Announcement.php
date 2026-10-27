<?php

namespace App\Models;

use App\Enums\AnnouncementStatus;
use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\ResolvesPublicImage;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An MTO announcement. body is plain text; always display it escaped.
 * Not mass assignable: status, is_featured, published_at, image, slug, created_by.
 *
 * @property AnnouncementStatus $status
 * @property-read string $image_url
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUniqueSlug, ResolvesPublicImage;

    protected $fillable = [
        'title',
        'excerpt',
        'body',
    ];

    protected $attributes = [
        'status' => 'draft',
        'is_featured' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => AnnouncementStatus::class,
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function slugSource(): string
    {
        return 'title';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->publicImageUrl($this->image));
    }

    public function isPublished(): bool
    {
        return $this->status === AnnouncementStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /** Announcements the public may read. */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', AnnouncementStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
