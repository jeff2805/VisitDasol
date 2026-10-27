<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's review of one stay.
 *
 * Only rating and comment are fillable. Status, the resort, the booking, the
 * author, the owner's reply and the moderation fields are all set explicitly,
 * so no form can publish a review about somebody else's stay or quietly
 * un-hide one the office took down.
 *
 * @property ReviewStatus $status
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'rating',
        'comment',
    ];

    protected $attributes = [
        'status' => 'published',
    ];

    /** Ratings are whole stars, one to five. */
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'owner_replied_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /* Relationships ---------------------------------------------------- */

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    /* Scopes ----------------------------------------------------------- */

    /** The reviews visitors may read. */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ReviewStatus::Published->value);
    }

    public function scopeHidden(Builder $query): void
    {
        $query->where('status', ReviewStatus::Hidden->value);
    }

    public function scopeForResort(Builder $query, Resort $resort): void
    {
        $query->where('resort_id', $resort->id);
    }

    /* Helpers ---------------------------------------------------------- */

    public function isPublished(): bool
    {
        return $this->status->isVisible();
    }

    public function hasOwnerReply(): bool
    {
        return filled($this->owner_reply);
    }

    /**
     * The author's display name, shortened the way review sites do it:
     * a first name and a last initial, so a real name is not published in
     * full beside an opinion.
     */
    public function authorName(): string
    {
        $author = $this->author;

        if ($author === null) {
            return 'A guest';
        }

        $initial = mb_substr((string) $author->last_name, 0, 1);

        return trim($author->first_name.' '.($initial !== '' ? $initial.'.' : ''));
    }
}
