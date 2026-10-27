<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Where a handover has got to.
 *
 * Deliberately short. Every extra state in a money workflow is another
 * place for a figure to sit unexplained, and the office needs exactly
 * three answers: it has been handed in, it has been counted and agreed,
 * or it has been counted and does NOT agree.
 *
 * Disputed is not a failure state to be cleared away. It is the record
 * that the money handed over did not match the receipts written, and it
 * stays on the row so the difference can be chased.
 */
enum RemittanceStatus: string
{
    use EnumHelpers;

    case Submitted = 'submitted';
    case Reconciled = 'reconciled';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Waiting to be counted',
            self::Reconciled => 'Counted and agreed',
            self::Disputed => 'Does not agree',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Submitted => 'The collector has handed this in. The office has not counted it yet.',
            self::Reconciled => 'The office counted this and it matches the receipts written.',
            self::Disputed => 'The office counted this and it does not match the receipts written.',
        };
    }

    /** Matches the status badge colours used across the office screens. */
    public function badge(): string
    {
        return match ($this) {
            self::Submitted => 'pending',
            self::Reconciled => 'active',
            self::Disputed => 'rejected',
        };
    }

    /** Whether the office has finished with it. */
    public function isReviewed(): bool
    {
        return $this !== self::Submitted;
    }
}
