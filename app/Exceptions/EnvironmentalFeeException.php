<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A municipal fee could not be collected or changed.
 *
 * Controllers show these to the officer at the gate, so every message
 * has to say what is wrong in words they can act on — "no rate has been
 * set" is something to take to the office; "error" is not.
 */
class EnvironmentalFeeException extends RuntimeException
{
    public static function notCollectable(string $reason): self
    {
        return new self($reason);
    }

    public static function checkpointInactive(string $name): self
    {
        return new self(
            "{$name} is not an active checkpoint, so nothing can be collected there."
        );
    }

    public static function notAuthorised(string $name): self
    {
        return new self(
            "You are not assigned to collect at {$name}. The tourism office "
            .'assigns collectors to each checkpoint separately.'
        );
    }

    public static function receiptRequired(): self
    {
        return new self('Enter the number on the receipt you issued.');
    }

    public static function receiptUsed(string $receipt): self
    {
        return new self(
            "Receipt {$receipt} has already been recorded. Check the number, "
            .'or look it up to see what was collected against it.'
        );
    }

    public static function voidNeedsReason(): self
    {
        return new self('Say why this collection is being voided.');
    }

    /* GCash ------------------------------------------------------------- */

    public static function gcashNotConfigured(): self
    {
        return new self(
            'GCash is not set up for the environmental fee yet. The tourism '
            .'office has to add the municipal GCash number and its QR code first. '
            .'Take cash for now.'
        );
    }

    public static function gcashReferenceRequired(): self
    {
        return new self(
            'Enter the GCash reference number from the payer\'s receipt. Without '
            .'it the office has nothing to look for in the GCash app.'
        );
    }

    public static function gcashReferenceUsed(string $reference): self
    {
        return new self(
            "GCash reference {$reference} has already been recorded against another "
            .'fee. One transfer pays one fee. Check the number on the payer\'s receipt.'
        );
    }

    public static function rejectionNeedsReason(): self
    {
        return new self(
            'Say why the payment could not be confirmed, so the office knows what '
            .'to chase.'
        );
    }

    public static function nothingToVerify(string $status): self
    {
        return new self(
            "This payment is already marked \"{$status}\" and cannot be checked again."
        );
    }

    public static function alreadyClosed(string $status): self
    {
        return new self(
            "This collection is already {$status} and cannot be changed again."
        );
    }
}
