<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One audit-trail entry. Rows are only ever inserted (no updated_at).
 * Written by App\Services\ActivityLogger (Phase 4). Never store secrets.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The part of the system an action belongs to, taken from the part of
     * the action name before the dot ("resort.approved" -> "resort").
     */
    public function group(): string
    {
        return str_contains($this->action, '.') ? explode('.', $this->action)[0] : 'other';
    }

    /**
     * The action in plain words, for the log viewer.
     * "owner.approved" -> "Owner approved"
     */
    public function actionLabel(): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $this->action));
    }

    /** A Bootstrap Icons name that suits the group. */
    public function icon(): string
    {
        return match ($this->group()) {
            'owner', 'user' => 'person',
            'resort' => 'buildings',
            'room' => 'door-open',
            'booking' => 'calendar-check',
            'destination', 'category' => 'signpost-2',
            'amenity' => 'stars',
            'announcement' => 'megaphone',
            default => 'clock-history',
        };
    }

    /** The person who performed the action. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The record the action was performed on (may no longer exist). */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
