<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Destination visibility. Stored in destinations.status.
 *
 * Only Active is public. A spot sent in by a barangay official starts
 * Pending and stays invisible until the Municipal Tourism Office has read
 * it, which is the whole point of the queue: nobody publishes a claim about
 * a real place in Dasol except the office.
 *
 *   pending  -> active | rejected
 *   rejected -> pending   (the barangay corrects it and sends it again)
 *   active   -> inactive  (the office takes it down)
 */
enum DestinationStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the office',
            self::Active => 'Published',
            self::Rejected => 'Sent back',
            self::Inactive => 'Hidden',
        };
    }

    /** True while the barangay may still edit and resend it. */
    public function isWithTheBarangay(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
    }
}
