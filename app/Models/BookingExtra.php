<?php

namespace App\Models;

use App\Enums\ExtraPriceUnit;
use App\Enums\ExtraStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a guest asked for alongside their room.
 *
 * The name, the price and the unit are COPIED here when the guest asks
 * and never read back from the resort's list. An owner who raises the
 * kayak price next month must not change what a guest was quoted in
 * writing last month — the same rule the room rate and the payment
 * terms already follow.
 *
 * Nothing here reaches the booking total. Extras are settled at the
 * resort, which is why asking for one changes no deposit and no
 * payment deadline.
 */
class BookingExtra extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'requested',
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtraStatus::class,
            'price_unit' => ExtraPriceUnit::class,
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The resort's listing, or null once the owner has retired it. */
    public function extra(): BelongsTo
    {
        return $this->belongsTo(ResortExtra::class, 'resort_extra_id');
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /* Scopes */

    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', ExtraStatus::Requested->value);
    }

    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', ExtraStatus::Confirmed->value);
    }

    /**
     * How this reads on a bill: "Banana boat x 4, per person".
     */
    public function line(): string
    {
        return $this->name.' × '.$this->quantity.', '.$this->price_unit->suffix();
    }
}
