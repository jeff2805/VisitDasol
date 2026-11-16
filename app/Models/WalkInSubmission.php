<?php

namespace App\Models;

use App\Enums\WalkInSubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a walk-in record on its way through the
 * Municipal Tourism Office.
 *
 * Append-only. Rows are written when a record is submitted, returned,
 * accepted or resubmitted, and are never edited or deleted afterwards —
 * that is the whole value of a trail. Nothing is mass assignable, because
 * WalkInSubmissionService owns every write: a row saying the office
 * accepted a record is evidence, and must never follow from form input.
 *
 * @property WalkInSubmissionStatus $resulting_status
 */
class WalkInSubmission extends Model
{
    protected $guarded = ['id'];

    /** What was done. Mirrors the verbs in WalkInSubmissionStatus. */
    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const ACCEPTED = 'accepted';

    public const RESUBMITTED = 'resubmitted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resulting_status' => WalkInSubmissionStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The resort owner who sent it, or the office account that decided. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Whether this event was the office sending the record back. */
    public function isReturn(): bool
    {
        return $this->action === self::RETURNED;
    }
}
