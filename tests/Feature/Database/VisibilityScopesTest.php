<?php

namespace Tests\Feature\Database;

use App\Enums\ResortStatus;
use App\Enums\UserStatus;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only ACTIVE owner + ACTIVE resort (+ ACTIVE room) may reach tourists.
 */
class VisibilityScopesTest extends TestCase
{
    use RefreshDatabase;

    public static function visibilityCases(): array
    {
        return [
            'active owner, active resort' => [UserStatus::Active, ResortStatus::Active, true],
            'active owner, pending resort' => [UserStatus::Active, ResortStatus::Pending, false],
            'active owner, rejected resort' => [UserStatus::Active, ResortStatus::Rejected, false],
            'active owner, inactive resort' => [UserStatus::Active, ResortStatus::Inactive, false],
            'inactive owner, active resort' => [UserStatus::Inactive, ResortStatus::Active, false],
            'pending owner, active resort' => [UserStatus::Pending, ResortStatus::Active, false],
            'rejected owner, active resort' => [UserStatus::Rejected, ResortStatus::Active, false],
        ];
    }

    #[DataProvider('visibilityCases')]
    public function test_public_visibility(UserStatus $ownerStatus, ResortStatus $resortStatus, bool $visible): void
    {
        $owner = User::factory()->resortOwner($ownerStatus)->create();
        $resort = Resort::factory()->for($owner, 'owner')->withStatus($resortStatus)->create();
        $room = Room::factory()->for($resort)->create();

        $this->assertSame($visible, Resort::publiclyVisible()->whereKey($resort->id)->exists());
        $this->assertSame($visible, $resort->fresh()->isPubliclyVisible());
        $this->assertSame($visible, Room::bookable()->whereKey($room->id)->exists());
        $this->assertSame($visible, $room->fresh()->isBookable());
    }

    public function test_a_resort_owned_by_a_non_owner_account_is_never_public(): void
    {
        $tourist = User::factory()->create();
        $resort = Resort::factory()->for($tourist, 'owner')->active()->create();

        $this->assertFalse(Resort::publiclyVisible()->whereKey($resort->id)->exists());
        $this->assertFalse($resort->fresh()->isPubliclyVisible());
    }

    public function test_inactive_room_in_a_public_resort_is_not_bookable(): void
    {
        $resort = Resort::factory()->active()->create();
        $active = Room::factory()->for($resort)->create();
        $inactive = Room::factory()->for($resort)->inactive()->create();

        $this->assertEqualsCanonicalizing([$active->id], Room::bookable()->pluck('id')->all());
        $this->assertFalse($inactive->fresh()->isBookable());
    }

    public function test_reactivating_an_owner_restores_visibility_without_touching_the_resort(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();

        $owner->status = UserStatus::Inactive;
        $owner->save();
        $this->assertFalse(Resort::publiclyVisible()->exists());
        $this->assertSame(ResortStatus::Active, $resort->fresh()->status);

        $owner->status = UserStatus::Active;
        $owner->save();
        $this->assertTrue(Resort::publiclyVisible()->exists());
    }
}
