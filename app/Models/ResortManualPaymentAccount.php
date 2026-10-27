<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResortManualPaymentAccount extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }
}
