<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentPreference;
use App\Enums\PaymentRequirement;
use App\Enums\VisitorOrigin;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A reservation for one room type (rooms_booked units) over a date range.
 *
 * A stay occupies the nights [check_in_date, check_out_date).
 * The guest leaves on check_out_date, so another guest may arrive
 * that same day.
 *
 * Almost every column is set by BookingService, never by forms.
 * Routes use booking_reference, not the numeric id.
 *
 * @property BookingStatus $status
 * @property \Carbon\CarbonImmutable $check_in_date
 * @property \Carbon\CarbonImmutable $check_out_date
 * @property string|null $check_in_time
 * @property string|null $check_out_time
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'contact_name',
        'contact_phone',
        'contact_email',
        'special_requests',
        'payment_preference',

        // Guest and visitor information
        'guests_male',
        'guests_female',
        'origin',

        // Booking schedule
        'check_in_time',
        'check_out_time',
    ];

    protected $attributes = [
        'status' => 'pending',
        'rooms_booked' => 1,

        // What every booking made before tours existed was.
        'booking_type' => 'overnight',

        // Guest counts
        'adults' => 1,
        'children' => 0,
        'pwd_guests' => 0,
        'beds' => 0,

        // Price breakdown
        'subtotal_amount' => '0.00',
        'child_discount_amount' => '0.00',
        'pwd_discount_amount' => '0.00',
        'discount_total' => '0.00',

        // The rates those amounts came from
        'child_discount_percent' => 0,
        'pwd_discount_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'booking_type' => BookingType::class,
            'payment_preference' => PaymentPreference::class,

            // Dates
            'check_in_date' => 'immutable_date',
            'check_out_date' => 'immutable_date',

            // Numeric values
            'nights' => 'integer',
            'guests' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'pwd_guests' => 'integer',
            'beds' => 'integer',
            'guests_male' => 'integer',
            'guests_female' => 'integer',
            'rooms_booked' => 'integer',
            'child_discount_percent' => 'integer',
            'pwd_discount_percent' => 'integer',

            // Money
            'unit_price' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'child_discount_amount' => 'decimal:2',
            'pwd_discount_amount' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total_amount' => 'decimal:2',

            // Visitor origin
            'origin' => VisitorOrigin::class,

            // Payment terms, frozen when the booking was made
            'payment_requirement' => PaymentRequirement::class,
            'payment_due_reference' => PaymentDeadlineReference::class,
            'amount_due_now' => 'decimal:2',
            'payment_deposit_percent' => 'integer',
            'payment_deadline_hours' => 'integer',
            'payment_policy_version' => 'integer',
            'payment_last_minute' => 'boolean',
            'payment_on_site_allowed' => 'boolean',
            'payment_due_at' => 'datetime',

            // Booking lifecycle timestamps
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'booking_reference';
    }

    /* Store dates as plain Y-m-d strings on every database engine. */

    protected function checkInDate(): Attribute
    {
        return Attribute::set(
            fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->toDateString()
        );
    }

    protected function checkOutDate(): Attribute
    {
        return Attribute::set(
            fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->toDateString()
        );
    }

    /* What the guest bought */

    /**
     * How long the guest has the room, in the terms it was sold in.
     *
     * "2 nights" for a stay, and the kind of tour for a tour: a tour has
     * no nights, and "0 nights" on a list of reservations reads as a
     * booking that went wrong rather than one that was never overnight.
     */
    public function durationLabel(): string
    {
        if ($this->booking_type->isTour()) {
            return $this->booking_type->label();
        }

        return $this->nights.' '.Str::plural('night', $this->nights);
    }

    /**
     * The dates as the guest understands them.
     *
     * A tour is stored as [D, D+1) so AvailabilityService holds the room
     * for the date, but D+1 is bookkeeping: nobody is there, and showing
     * it would have a day group expecting a second day.
     */
    public function dateRangeLabel(): string
    {
        if ($this->booking_type->isTour()) {
            return $this->check_in_date->format('M j, Y');
        }

        return $this->check_in_date->format('M j')
            .' to '.$this->check_out_date->format('M j, Y');
    }

    /* Relationships */

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** At most one, because reviews.booking_id is unique. */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * What the guest asked for besides the room.
     *
     * Requests, not purchases: each one carries the resort's answer,
     * and none of them reaches total_amount. Extras are settled at the
     * resort, which is why asking for one changes no deposit and no
     * payment deadline.
     */
    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /** Online payment attempts against this reservation, newest first. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    /* Scopes */

    /**
     * Bookings whose nights intersect [checkIn, checkOut).
     * Strict comparisons allow same-day turnover.
     */
    public function scopeOverlapping(
        Builder $query,
        CarbonInterface|string $checkIn,
        CarbonInterface|string $checkOut
    ): void {
        $query->where(
            'check_in_date',
            '<',
            self::toDateString($checkOut)
        )->where(
            'check_out_date',
            '>',
            self::toDateString($checkIn)
        );
    }

    /**
     * Bookings that hold inventory: confirmed and completed always;
     * pending only until the check-in date has passed.
     * Cancelled and rejected bookings never hold inventory.
     */
    public function scopeBlocking(Builder $query): void
    {
        $today = today()->toDateString();

        $query->where(function (Builder $blocking) use ($today): void {
            $blocking
                ->whereIn(
                    'status',
                    array_column(BookingStatus::firmStatuses(), 'value')
                )
                ->orWhere(function (Builder $pending) use ($today): void {
                    $pending
                        ->where('status', BookingStatus::Pending->value)
                        ->where('check_in_date', '>=', $today);
                });
        });
    }

    public function scopeWithStatus(
        Builder $query,
        BookingStatus $status
    ): void {
        $query->where('status', $status->value);
    }

    /**
     * A stay that is still going on: waiting for the resort, or accepted
     * and not yet finished.
     *
     * This is the rule that stops a tourist holding rooms at two resorts
     * at once. Defined here and nowhere else, because the booking form
     * warns about it and BookingService refuses on it — and a warning
     * that disagreed with the refusal would be worse than no warning.
     *
     * Rejected, cancelled and completed stays are finished and block
     * nothing.
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [
            BookingStatus::Pending->value,
            BookingStatus::Confirmed->value,
        ]);
    }

    /** Bookings for resorts owned by the given user. */
    public function scopeForOwner(Builder $query, User $owner): void
    {
        $query->whereHas(
            'resort',
            fn (Builder $resort) => $resort->where(
                'owner_id',
                $owner->id
            )
        );
    }

    public function scopeForTourist(Builder $query, User $tourist): void
    {
        $query->where('user_id', $tourist->id);
    }

    private static function toDateString(
        CarbonInterface|string $date
    ): string {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }
}
