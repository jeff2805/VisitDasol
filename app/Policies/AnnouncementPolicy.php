<?php

namespace App\Policies;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;

/**
 * Announcements speak for the Municipal Tourism Office, so only the office
 * writes and publishes them.
 */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin();
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin() && $announcement->status === AnnouncementStatus::Draft;
    }

    public function unpublish(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin() && $announcement->status === AnnouncementStatus::Published;
    }

    /** Only something the public can actually read may be pinned. */
    public function feature(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin() && $announcement->isPublished();
    }
}
