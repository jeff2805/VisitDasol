<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google or Facebook account linked to a Visit Dasol account.
 *
 * Nothing here is mass assignable: every field comes from the provider's
 * answer and is written explicitly by SocialLogin, never from a form.
 *
 * @property SocialProvider $provider
 */
class SocialAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
