<?php

namespace App\Models;

use App\Enums\RemittanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of collected environmental fee money.
 *
 * A collector declares what they are turning in for a period at a gate;
 * the office counts it and says whether it agrees. The three figures —
 * expected, declared, counted — are kept apart on purpose, because the
 * gap between them is the only thing a reconciliation is for.
 *
 * NOT a bank record. Nothing here says money reached the treasury; it
 * says two named people wrote down two numbers at two times.
 */
class EnvironmentalFeeRemittance extends Model
{
    protected $table = 'environmental_fee_remittances';

    protected $fillable = [
        'reference',
        'checkpoint_id',
        'collector_id',
        'period_start',
        'period_end',
        'expected_amount',
        'expected_receipts',
        'declared_amount',
        'currency',
        'submitted_at',
        'collector_note',
    ];

    /*
     * Everything about the office's side of this is left out of
     * $fillable deliberately. A counted figure and a status are set by
     * the service after an authorisation check, never by whatever
     * arrived in a request body.
     */
    protected $attributes = [
        'status' => 'submitted',
        'currency' => 'PHP',
    ];

    protected function casts(): array
    {
        return [
            'status' => RemittanceStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'expected_amount' => 'decimal:2',
            'declared_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'expected_receipts' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /* Relationships */

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalFeeCheckpoint::class, 'checkpoint_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /* The figures */

    /**
     * Pesos as whole centavos, the way the rest of this module counts
     * money. A variance of five centavos is still a variance, and
     * floats are how it turns into 0.049999999.
     */
    private static function centavos(string|float|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * What the office counted, less what the receipts say should be
     * there. Negative is a shortfall; positive is an overage, which is
     * just as much a discrepancy and just as much worth chasing.
     *
     * Null until somebody has actually counted.
     */
    public function variance(): ?string
    {
        if ($this->counted_amount === null) {
            return null;
        }

        return self::pesos(
            self::centavos($this->counted_amount) - self::centavos($this->expected_amount)
        );
    }

    /**
     * The gap between what was handed over and what the receipts say.
     * Available the moment it is submitted, before anybody counts, so a
     * collector sees their own arithmetic before the office does.
     */
    public function declaredVariance(): string
    {
        return self::pesos(
            self::centavos($this->declared_amount) - self::centavos($this->expected_amount)
        );
    }

    /** Whether anything at all fails to line up. */
    public function hasDiscrepancy(): bool
    {
        if (self::centavos($this->declaredVariance()) !== 0) {
            return true;
        }

        return $this->counted_amount !== null
            && self::centavos($this->variance()) !== 0;
    }

    /* Scopes */

    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', RemittanceStatus::Submitted->value);
    }

    public function scopeDisputed(Builder $query): void
    {
        $query->where('status', RemittanceStatus::Disputed->value);
    }

    /** Submitted within a span of whole days, inclusive. */
    public function scopeSubmittedBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('submitted_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }
}
