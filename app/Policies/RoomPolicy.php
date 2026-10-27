<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

/**
 * Room types belong to a resort, and a resort belongs to one owner.
 * Every check goes through the room's resort, so putting someone else's
 * room id in the URL changes nothing.
 */
class RoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isResortOwner() && $user->resorts()->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Room $room): bool
    {
        return $this->owns($user, $room) || $user->isSuperAdmin();
    }

    public function update(User $user, Room $room): bool
    {
        return $this->owns($user, $room);
    }

    public function changeStatus(User $user, Room $room): bool
    {
        return $this->owns($user, $room);
    }

    public function manageImages(User $user, Room $room): bool
    {
        return $this->owns($user, $room);
    }

    /**
     * A room type with reservations is never deleted, so booking history
     * stays intact. Owners switch it off instead.
     */
    public function delete(User $user, Room $room): bool
    {
        return $this->owns($user, $room) && ! $room->bookings()->exists();
    }

    /**
     * Checked with a query rather than by reading $room->resort, so the
     * check is correct even when the relation was not loaded (which would
     * otherwise cause an extra query per row in a list).
     */
    private function owns(User $user, Room $room): bool
    {
        return $user->isResortOwner()
            && $room->resort_id !== null
            && $user->resorts()->whereKey($room->resort_id)->exists();
    }
}
