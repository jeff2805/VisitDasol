<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * A payment could not be started or checked.
 *
 * Every message here is written for a guest to read and is shown as-is, so
 * it must never carry an API key, an endpoint, or a gateway's raw error.
 * The detail goes to the log instead (see PaymentService).
 */
class PaymentException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("Online payment through {$provider} is not switched on yet. Please contact the resort to arrange payment.");
    }

    public static function unreachable(string $provider): self
    {
        return new self("{$provider} could not be reached just now. Please try again in a moment, or contact the resort to arrange payment.");
    }

    public static function refused(string $provider): self
    {
        return new self("{$provider} would not open a payment for this reservation. Please try again, or contact the resort.");
    }

    public static function nothingToPay(): self
    {
        return new self('There is nothing to pay on this reservation.');
    }
}
