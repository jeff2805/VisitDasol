<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay for a reservation online.
 *
 * Nothing here is mass assignable: App\Services\Payments\PaymentService owns
 * every write, because a row moving to "paid" is the record that money
 * changed hands and must never follow from form input.
 *
 * @property PaymentStatus $status
 * @property PaymentProvider $provider
 */
class Payment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
        'currency' => 'PHP',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'provider' => PaymentProvider::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'payment_reference';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function proofs()
    {
        return $this->hasMany(PaymentProof::class)->latest('id');
    }

    /* Scopes ----------------------------------------------------------- */

    public function scopeSettled(Builder $query): void
    {
        $query->where('status', PaymentStatus::Paid->value);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', PaymentStatus::Pending->value);
    }

    /* Helpers ---------------------------------------------------------- */

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * The amount in the smallest unit, which is what PayMongo works in.
     * Kept as an integer so a nightly rate never drifts through floating
     * point rounding on its way to the gateway.
     */
    public function centavos(): int
    {
        return (int) round((float) $this->amount * 100);
    }
}
