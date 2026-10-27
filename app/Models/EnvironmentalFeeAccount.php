<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The municipality's GCash details for the environmental fee.
 *
 * One row. Read through current(), so no caller has to know that.
 *
 * NOT a payment gateway and not a connection to a wallet. This is a
 * number, a name and a picture of a QR code — an instruction to the
 * payer. Nothing here can see money arrive, which is why every GCash
 * collection waits for a person to confirm it.
 *
 * active is not fillable: switching GCash on at the gates is its own
 * decision with its own audit entry.
 */
class EnvironmentalFeeAccount extends Model
{
    protected $fillable = [
        'account_name',
        'gcash_number',
        'instructions',
    ];

    /** Off until the office says the gates are ready to take it. */
    protected $attributes = [
        'active' => false,
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The one row, or null before the office has filled it in. */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * Whether a visitor may actually be asked to pay this way.
     *
     * A number alone is not enough: somebody at a gate holding up a
     * phone needs the QR to point at, and an account with no QR image
     * would have them reading digits aloud at a car window.
     */
    public function isUsable(): bool
    {
        return $this->active
            && filled($this->gcash_number)
            && filled($this->qr_image);
    }
}
