<?php

namespace App\Models;

use Database\Factories\OwnerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resort owner application details (one per owner account).
 * permit_document_path and the review fields are set explicitly by code.
 */
class OwnerProfile extends Model
{
    /** @use HasFactory<OwnerProfileFactory> */
    use HasFactory;

    /** Allowed values for the "position" field. */
    public const POSITIONS = ['Owner', 'Manager', 'Authorized representative'];

    protected $fillable = [
        'business_name',
        'position',
        'business_permit_no',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hasPermitDocument(): bool
    {
        return filled($this->permit_document_path);
    }
}
