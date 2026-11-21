<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may write the public About page.
 *
 * THE TOURISM OFFICE, AND NOBODY ELSE.
 *
 * This page is the municipality's public statement about itself. A resort
 * owner with an interest in how the town is described, or a tourist who
 * found the address, must reach nothing here — and reach it with a refusal
 * from the server, not with a missing button. Hiding the link is a
 * courtesy; this is the gate.
 *
 * There is deliberately no "edit your own section": the About page has no
 * owner but the office.
 */
class AboutSectionPolicy
{
    /** Open the management screens at all. */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    /** Save a draft, upload a picture, reorder a paragraph. */
    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Put something in front of the public, or take it down.
     *
     * The same people as update() today, and a separate method on purpose:
     * an office that later wants writing and publishing to be two jobs
     * changes this one line, and every publish route follows.
     */
    public function publish(User $user): bool
    {
        return $this->viewAny($user);
    }
}
