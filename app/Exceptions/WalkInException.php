<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A walk-in record could not move the way somebody asked it to.
 *
 * Kept apart from BookingException on purpose. That one is about the stay —
 * the dates, the party, whether a room is free. This one is about the
 * RECORD's journey through the Municipal Tourism Office, and the two fail for
 * unrelated reasons: a record can be perfectly valid as a booking and still
 * be refused here because the office is already holding it.
 *
 * Every message is shown to a resort owner or an office reviewer, so each one
 * says what happened and what to do about it.
 */
class WalkInException extends RuntimeException
{
    /** Someone tried to change a record that is no longer theirs to change. */
    public static function notEditable(): self
    {
        return new self(
            'This record is with the tourism office and cannot be changed right now. '
            .'Wait for it to be accepted or returned to you.'
        );
    }

    /** Sending something that is already sent. */
    public static function alreadySubmitted(): self
    {
        return new self(
            'This record has already been sent to the tourism office.'
        );
    }

    /** Sending something the office has already accepted. */
    public static function alreadyAccepted(): self
    {
        return new self(
            'The tourism office has already accepted this record, so it can no longer be changed.'
        );
    }

    /** The office deciding on something that is not in front of them. */
    public static function notUnderReview(): self
    {
        return new self(
            'This record is not waiting for a review, so there is nothing to decide.'
        );
    }

    /** Returning a record without saying what is wrong with it. */
    public static function remarksRequired(): self
    {
        return new self(
            'Say what needs correcting before returning this record to the resort.'
        );
    }

    /**
     * A record missing the figures the Department of Tourism return needs.
     *
     * Refused at submission rather than at acceptance, so the resort finds
     * out while the record is still theirs to fix.
     */
    public static function incomplete(string $what): self
    {
        return new self(
            "This record cannot be sent yet: {$what}"
        );
    }

    /** Not a walk-in at all. */
    public static function notAWalkIn(): self
    {
        return new self(
            'That reservation was booked online and is not a walk-in record.'
        );
    }
}
