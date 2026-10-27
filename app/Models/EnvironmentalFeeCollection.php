<?php

namespace App\Models;

use App\Enums\CollectionStatus;
use App\Enums\FeePaymentStatus;
use App\Enums\FeeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One environmental fee taken at a checkpoint.
 *
 * NOTHING IS MASS ASSIGNABLE. EnvironmentalFeeService owns every write,
 * because the amount on this row is money the municipality says it
 * collected, and that must never follow from a form field. The quantity
 * and the notes are what a collector types; the rate, the amount and the
 * references are worked out on the server.
 *
 * There is no link to a booking or a resort, and there will not be one.
 * This is municipal money taken at a gate, not resort income.
 *
 * @property CollectionStatus $status
 * @property FeeScope $scope
 */
class EnvironmentalFeeCollection extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'recorded',
        'currency' => 'PHP',
        'payment_method' => 'cash',
        'payment_status' => 'received',
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => CollectionStatus::class,
            'payment_status' => FeePaymentStatus::class,
            'scope' => FeeScope::class,
            'verified_at' => 'datetime',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'collected_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /* Relationships ---------------------------------------------------- */

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalFeeCheckpoint::class, 'checkpoint_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalFeePolicy::class, 'policy_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Whoever opened the GCash app and confirmed the transfer landed. */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** The row this one was written to replace, if it is a correction. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }

    /* Scopes ------------------------------------------------------------ */

    /**
     * Rows that count towards a total.
     *
     * TWO conditions, because there are two ways a row can fail to be
     * money the municipality holds:
     *
     *   the RECORD may be void or superseded — it stays in the table
     *   and stays in the reports, but adding it would overstate what
     *   was taken;
     *
     *   the MONEY may not have arrived. A GCash transfer nobody has
     *   checked is a claim, not a payment, and this portal cannot see
     *   anybody's wallet to know the difference.
     */
    public function scopeCounted(Builder $query): void
    {
        $query->where('status', CollectionStatus::Recorded->value)
            ->whereIn('payment_status', [
                FeePaymentStatus::Received->value,
                FeePaymentStatus::Verified->value,
            ]);
    }

    /** GCash transfers still waiting for somebody to confirm them. */
    public function scopeAwaitingVerification(Builder $query): void
    {
        $query->where('status', CollectionStatus::Recorded->value)
            ->where('payment_status', FeePaymentStatus::AwaitingVerification->value);
    }

    public function scopeCollectedBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('collected_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    public function scopeAtCheckpoint(Builder $query, int $checkpointId): void
    {
        $query->where('checkpoint_id', $checkpointId);
    }

    /* Helpers ------------------------------------------------------------ */

    public function isVoidable(): bool
    {
        return $this->status === CollectionStatus::Recorded;
    }

    public function isGcash(): bool
    {
        return $this->payment_method === 'gcash';
    }

    /** Waiting on somebody to check the GCash app for this one. */
    public function awaitsVerification(): bool
    {
        return $this->status === CollectionStatus::Recorded
            && $this->payment_status === FeePaymentStatus::AwaitingVerification;
    }
}
