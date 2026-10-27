<?php

namespace App\Models;

use App\Enums\ResortStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\ResolvesPublicImage;
use Database\Factories\ResortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A resort listing.
 *
 * Public visibility rule (used everywhere tourists see resorts):
 *     status = active  AND  owner is an active resort owner
 *     AND the listing is not a demo one, unless DEMO_LISTINGS_PUBLIC says so
 * Use Resort::publiclyVisible() in queries and isPubliclyVisible() on a model.
 * The two must always agree; see the note on isPubliclyVisible().
 *
 * Not mass assignable (set explicitly): owner_id, status, is_featured,
 * is_demo, main_image, slug, municipality, province, submitted_at, reviewed_*.
 *
 * @property ResortStatus $status
 * @property-read string $main_image_url
 * @property-read string $full_address
 */
class Resort extends Model
{
    /** @use HasFactory<ResortFactory> */
    use HasFactory, HasUniqueSlug, ResolvesPublicImage;

    protected $fillable = [
        'name',
        'description',
        'address',
        'barangay',
        'latitude',
        'longitude',
        'contact_number',
        'email',
        'website_url',
        'check_in_time',
        'check_out_time',
    ];

    protected $attributes = [
        'municipality' => 'Dasol',
        'province' => 'Pangasinan',
        'status' => 'pending',
        'is_featured' => false,
        'is_demo' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ResortStatus::class,
            'is_featured' => 'boolean',
            'is_demo' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /* Relationships ---------------------------------------------------- */

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ResortImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->orderBy('name');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function manualAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ResortManualPaymentAccount::class);
    }

    public function paymentPolicy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ResortPaymentPolicy::class);
    }

    /**
     * This resort's payment terms, or the portal's where it has set none.
     *
     * Always a policy, never null, so no caller has to carry its own idea
     * of what a resort that has not answered should ask for.
     */
    public function paymentTerms(): ResortPaymentPolicy
    {
        return $this->paymentPolicy ?? ResortPaymentPolicy::fallback();
    }

    /**
     * Kayak hire, a banana boat ride, island hopping — whatever this
     * resort offers besides the room.
     */
    public function extras(): HasMany
    {
        return $this->hasMany(ResortExtra::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Adds rating_average and rating_count, counting only published reviews.
     *
     *     Resort::publiclyVisible()->withRating()->get()
     *
     * One subquery each rather than a join, so a resort with no reviews still
     * comes back (with nulls) instead of dropping out of the listing.
     */
    public function scopeWithRating(Builder $query): void
    {
        $query->withAvg(['reviews as rating_average' => fn (Builder $review) => $review->published()], 'rating')
            ->withCount(['reviews as rating_count' => fn (Builder $review) => $review->published()]);
    }

    /* Accessors -------------------------------------------------------- */

    protected function mainImageUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->publicImageUrl($this->main_image));
    }

    protected function fullAddress(): Attribute
    {
        return Attribute::get(fn (): string => collect([
            $this->address,
            $this->barangay ? 'Brgy. '.$this->barangay : null,
            $this->municipality,
            $this->province,
        ])->filter()->implode(', '));
    }

    /* Helpers ---------------------------------------------------------- */

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->owner_id === (int) $user->id;
    }

    /**
     * Must agree with scopePubliclyVisible(): this decides whether the detail
     * page opens, and that decides what the listings show. If the two ever
     * disagree, a hidden resort keeps a working address that anyone could
     * guess, which is the one thing hiding it was meant to prevent.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === ResortStatus::Active
            && $this->owner?->isResortOwner()
            && $this->owner->isActive()
            && (! $this->is_demo || (bool) config('visitdasol.demo.listings_public'));
    }

    /* Scopes ----------------------------------------------------------- */

    /** Resorts tourists may see and book. */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', ResortStatus::Active->value)
            ->whereHas('owner', function (Builder $owner): void {
                $owner->where('role', UserRole::ResortOwner->value)
                    ->where('status', UserStatus::Active->value);

            });

        // Fictional listings stay off the public site unless somebody
        // deliberately switches them on. One indexed column, so this costs
        // nothing and cannot depend on how the owner was loaded.
        if (! config('visitdasol.demo.listings_public')) {
            $query->where('is_demo', false);
        }
    }

    public function scopeWithStatus(Builder $query, ResortStatus $status): void
    {
        $query->where('status', $status->value);
    }

    public function scopePending(Builder $query): void
    {
        $query->withStatus(ResortStatus::Pending);
    }

    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('owner_id', $user->id);
    }
}
