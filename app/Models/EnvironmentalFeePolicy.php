<?php

namespace App\Models;

use App\Enums\FeeScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One edition of the municipality's environmental fee configuration.
 *
 * Saves make rows rather than edits, so the table is its own history.
 *
 * Nothing here is mass assignable beyond what an officer types: enabled,
 * enabled_by, enabled_at and created_by are set by the controller, never
 * by a form, because switching a municipal collection on is exactly the
 * sort of thing that must not follow from a hidden field.
 *
 * @property FeeScope|null $scope
 */
class EnvironmentalFeePolicy extends Model
{
    protected $fillable = [
        'amount',
        'currency',
        'scope',
        'effective_from',
        'effective_until',
        'authority_reference',
        'notes',
    ];

    /**
     * Off, and unpriced. A null amount is not a missing default — it is
     * the statement that nobody has set a rate, which is the only honest
     * starting position for a municipal charge.
     */
    protected $attributes = [
        'enabled' => false,
        'amount' => null,
        'currency' => 'PHP',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'scope' => FeeScope::class,
            'amount' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
            'enabled_at' => 'datetime',
        ];
    }

    /* Relationships ---------------------------------------------------- */

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(EnvironmentalFeeCollection::class, 'policy_id');
    }

    /* Is anything collectable? ------------------------------------------ */

    /**
     * Whether this policy may be collected against on the given day.
     *
     * Every condition has to hold. A policy that is switched on but has
     * no rate, or has a rate but does not start until next month, is not
     * a policy anyone may take money under.
     */
    public function isCollectableOn(CarbonImmutable $date): bool
    {
        if (! $this->enabled || $this->amount === null || $this->scope === null) {
            return false;
        }

        // An amount of nothing is not a fee.
        if ((float) $this->amount <= 0) {
            return false;
        }

        if ($this->effective_from === null || $this->effective_from->gt($date)) {
            return false;
        }

        return $this->effective_until === null
            || $this->effective_until->gte($date);
    }

    /** Why it cannot be collected against, in words an officer can act on. */
    public function blockedReason(CarbonImmutable $date): ?string
    {
        if (! $this->enabled) {
            return 'Environmental fee collection is switched off.';
        }

        if ($this->amount === null) {
            return 'No fee amount has been configured yet.';
        }

        if ((float) $this->amount <= 0) {
            return 'The configured fee amount is not a chargeable amount.';
        }

        if ($this->scope === null) {
            return 'The fee has no scope, so there is nothing to charge it against.';
        }

        if ($this->effective_from === null) {
            return 'The fee has no effective date.';
        }

        if ($this->effective_from->gt($date)) {
            return 'This fee does not take effect until '
                .$this->effective_from->format('M j, Y').'.';
        }

        if ($this->effective_until !== null && $this->effective_until->lt($date)) {
            return 'This fee stopped applying on '
                .$this->effective_until->format('M j, Y').'.';
        }

        return null;
    }

    /* Scopes ------------------------------------------------------------- */

    /**
     * Policies switched on and covering the given day.
     *
     * Two of these may never exist at once: an ambiguous rate is one
     * nobody can defend to a visitor, so PolicyRequest refuses to save
     * an enabled policy whose dates overlap another's.
     */
    public function scopeInForceOn(Builder $query, CarbonImmutable $date): void
    {
        $day = $date->toDateString();

        $query->where('enabled', true)
            ->whereNotNull('amount')
            ->where('effective_from', '<=', $day)
            ->where(function (Builder $until) use ($day): void {
                $until->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $day);
            });
    }
}
