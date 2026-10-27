<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's authority to collect at one checkpoint.
 *
 * Nothing is fillable. Every column on this row is a decision the office
 * makes through a controller action that logs it, and none of them may
 * follow from a form field.
 */
class EnvironmentalFeeCollector extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'active' => true,
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalFeeCheckpoint::class, 'checkpoint_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
