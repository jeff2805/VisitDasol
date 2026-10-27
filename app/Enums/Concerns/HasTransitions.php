<?php

namespace App\Enums\Concerns;

/**
 * Status enums that only allow specific changes.
 * Each enum using this trait must define transitions().
 */
trait HasTransitions
{
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }
}
