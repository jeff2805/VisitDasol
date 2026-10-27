<?php

namespace App\Models;

use App\Enums\ExtraPriceUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a resort offers besides the room.
 *
 * Kayak hire, a banana boat ride, island hopping. The owner's own list,
 * priced by the owner, and offered to guests as something to ASK for
 * rather than something to buy — whether a boat goes out on the day
 * depends on the weather and the boatman, neither of which this system
 * can see.
 */
class ResortExtra extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'price_unit',
        'max_quantity',
        'sort_order',
    ];

    /*
     * resort_id and active are not fillable. The resort comes from the
     * signed-in owner, and switching one off is its own action.
     */
    protected $attributes = [
        'active' => true,
        'price_unit' => 'per_booking',
        'max_quantity' => 10,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_unit' => ExtraPriceUnit::class,
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    public function bookingExtras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /* Scopes */

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** The owner's chosen order, then alphabetical as the tie-break. */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * What this costs for a given quantity.
     *
     * Centavos, like every other sum in this project, so a rate with an
     * odd half-peso cannot drift across a multiplication.
     */
    public function quote(int $quantity): string
    {
        $quantity = max(1, min($quantity, (int) $this->max_quantity));

        $centavos = (int) round((float) $this->price * 100) * $quantity;

        return number_format($centavos / 100, 2, '.', '');
    }
}
