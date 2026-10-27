<?php

namespace App\Models;

use App\Enums\BookingType;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room TYPE within a resort, with total_rooms identical units.
 *
 * Bookable rule: room is active AND its resort is publicly visible.
 * Not mass assignable: resort_id, status.
 *
 * @property RoomType $room_type
 * @property RoomStatus $status
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'room_type',
        'description',
        'capacity',
        'bed_count',
        'price_per_night',

        // Null means the room does not offer that kind of booking.
        'day_tour_price',
        'night_tour_price',

        'total_rooms',

        // Child discount settings
        'child_discount_enabled',
        'child_discount_percent',
        'child_discount_min_age',
        'child_discount_max_age',

        // PWD discount settings
        'pwd_discount_enabled',
        'pwd_discount_percent',
    ];

    protected $attributes = [
        'status' => 'active',
        'bed_count' => 1,
        'child_discount_enabled' => false,
        'child_discount_percent' => 0,
        'child_discount_min_age' => 0,
        'child_discount_max_age' => 12,
        'pwd_discount_enabled' => false,
        'pwd_discount_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'room_type' => RoomType::class,
            'status' => RoomStatus::class,

            'capacity' => 'integer',
            'bed_count' => 'integer',
            'total_rooms' => 'integer',
            'price_per_night' => 'decimal:2',
            'day_tour_price' => 'decimal:2',
            'night_tour_price' => 'decimal:2',

            'child_discount_enabled' => 'boolean',
            'child_discount_percent' => 'integer',
            'child_discount_min_age' => 'integer',
            'child_discount_max_age' => 'integer',

            'pwd_discount_enabled' => 'boolean',
            'pwd_discount_percent' => 'integer',
        ];
    }

    /**
     * The ages this room calls a child, e.g. "ages 2 to 12".
     *
     * Guests count their own children on the booking form, so the
     * room's own boundaries have to be on the form beside the box.
     * Null when the owner has set no range at all.
     */
    public function childAgeRange(): ?string
    {
        $min = $this->child_discount_min_age;
        $max = $this->child_discount_max_age;

        if ($min === null && $max === null) {
            return null;
        }

        if ($max === null) {
            return "ages {$min} and over";
        }

        if (($min ?? 0) < 1) {
            return "aged {$max} and under";
        }

        return "ages {$min} to {$max}";
    }

    /** Whether a child in this room is actually worth something off. */
    public function hasChildDiscount(): bool
    {
        return $this->child_discount_enabled
            && $this->child_discount_percent > 0;
    }

    public function hasPwdDiscount(): bool
    {
        return $this->pwd_discount_enabled
            && $this->pwd_discount_percent > 0;
    }

    /* Day and night tours ---------------------------------------------- */

    /**
     * What this room costs for one booking of the given kind, or null
     * when the resort does not sell it that way.
     *
     * Null is the answer, not a missing default. A room with no
     * day-tour price does not run day tours, and falling back to the
     * nightly rate would advertise something the owner never agreed to.
     */
    public function priceFor(BookingType $type): ?string
    {
        $price = match ($type) {
            BookingType::Overnight => $this->price_per_night,
            BookingType::DayTour => $this->day_tour_price,
            BookingType::NightTour => $this->night_tour_price,
        };

        return ($price === null || (float) $price <= 0)
            ? null
            : (string) $price;
    }

    public function offers(BookingType $type): bool
    {
        return $this->priceFor($type) !== null;
    }

    /**
     * Every kind of booking this room can actually be sold as.
     *
     * The booking form offers exactly these, so a guest is never shown
     * a choice the resort cannot honour.
     *
     * @return list<BookingType>
     */
    public function bookingTypes(): array
    {
        return array_values(array_filter(
            BookingType::cases(),
            fn (BookingType $type) => $this->offers($type)
        ));
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(RoomImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isActive(): bool
    {
        return $this->status === RoomStatus::Active;
    }

    public function isBookable(): bool
    {
        return $this->isActive()
            && (bool) $this->resort?->isPubliclyVisible();
    }

    /**
     * Qualified with the table name to avoid ambiguous status columns
     * when the public room listing joins the resorts table.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(
            $query->qualifyColumn('status'),
            RoomStatus::Active->value
        );
    }

    /**
     * Room types tourists may book right now.
     * Date-specific availability is checked separately.
     */
    public function scopeBookable(Builder $query): void
    {
        $query->active()
            ->whereHas(
                'resort',
                fn (Builder $resort) => $resort->publiclyVisible()
            );
    }
}
