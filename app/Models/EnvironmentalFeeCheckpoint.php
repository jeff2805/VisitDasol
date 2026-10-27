<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where the environmental fee is collected.
 *
 * Named one at a time by the office. Not every resort, not every
 * barangay, not every beach — only the places an ordinance actually
 * designates.
 *
 * active is not fillable: switching a checkpoint off is its own action
 * with its own audit entry, not something a stray form field can do.
 */
class EnvironmentalFeeCheckpoint extends Model
{
    protected $fillable = [
        'name',
        'barangay',
        'description',
        'latitude',
        'longitude',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /* Relationships ---------------------------------------------------- */

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EnvironmentalFeeCollector::class, 'checkpoint_id');
    }

    /** The people the office has authorised to take money here. */
    public function collectors(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'environmental_fee_collectors',
            'checkpoint_id',
            'user_id'
        )->withPivot(['active'])->withTimestamps();
    }

    public function collections(): HasMany
    {
        return $this->hasMany(EnvironmentalFeeCollection::class, 'checkpoint_id');
    }

    /* Helpers ------------------------------------------------------------ */

    /**
     * Whether this person may take money here right now.
     *
     * The assignment row IS the permission. There is no collector role
     * in this system, and the office granting one person the Tambobong
     * gate must not thereby grant them every gate in Dasol.
     */
    public function isCollectedBy(User $user): bool
    {
        return $this->active
            && $this->assignments()
                ->where('user_id', $user->id)
                ->where('active', true)
                ->exists();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('active'), true);
    }

    /** Checkpoints this person is authorised at. */
    public function scopeCollectedBy(Builder $query, User $user): void
    {
        $query->active()->whereHas(
            'assignments',
            fn (Builder $assignment) => $assignment
                ->where('user_id', $user->id)
                ->where('active', true)
        );
    }
}
