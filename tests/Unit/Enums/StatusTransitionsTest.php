<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingStatus;
use App\Enums\ResortStatus;
use App\Enums\RoomType;
use App\Enums\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusTransitionsTest extends TestCase
{
    public static function bookingTransitions(): array
    {
        return [
            'pending to confirmed' => [BookingStatus::Pending, BookingStatus::Confirmed, true],
            'pending to rejected' => [BookingStatus::Pending, BookingStatus::Rejected, true],
            'pending to cancelled' => [BookingStatus::Pending, BookingStatus::Cancelled, true],
            'pending to completed' => [BookingStatus::Pending, BookingStatus::Completed, false],
            'confirmed to cancelled' => [BookingStatus::Confirmed, BookingStatus::Cancelled, true],
            'confirmed to completed' => [BookingStatus::Confirmed, BookingStatus::Completed, true],
            'confirmed to rejected' => [BookingStatus::Confirmed, BookingStatus::Rejected, false],
            'confirmed to pending' => [BookingStatus::Confirmed, BookingStatus::Pending, false],
            'cancelled to confirmed' => [BookingStatus::Cancelled, BookingStatus::Confirmed, false],
            'completed to cancelled' => [BookingStatus::Completed, BookingStatus::Cancelled, false],
            'rejected to pending' => [BookingStatus::Rejected, BookingStatus::Pending, false],
        ];
    }

    #[DataProvider('bookingTransitions')]
    public function test_booking_status_transitions(BookingStatus $from, BookingStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_final_booking_statuses(): void
    {
        $this->assertTrue(BookingStatus::Rejected->isFinal());
        $this->assertTrue(BookingStatus::Cancelled->isFinal());
        $this->assertTrue(BookingStatus::Completed->isFinal());
        $this->assertFalse(BookingStatus::Pending->isFinal());
        $this->assertFalse(BookingStatus::Confirmed->isFinal());
    }

    public function test_owner_account_workflow(): void
    {
        $this->assertTrue(UserStatus::Pending->canTransitionTo(UserStatus::Active));
        $this->assertTrue(UserStatus::Pending->canTransitionTo(UserStatus::Rejected));
        $this->assertTrue(UserStatus::Active->canTransitionTo(UserStatus::Inactive));
        $this->assertTrue(UserStatus::Inactive->canTransitionTo(UserStatus::Active));
        $this->assertTrue(UserStatus::Rejected->canTransitionTo(UserStatus::Pending));

        // A rejected applicant cannot jump straight to active.
        $this->assertFalse(UserStatus::Rejected->canTransitionTo(UserStatus::Active));
        $this->assertFalse(UserStatus::Pending->canTransitionTo(UserStatus::Inactive));
    }

    public function test_resort_workflow(): void
    {
        $this->assertTrue(ResortStatus::Pending->canTransitionTo(ResortStatus::Active));
        $this->assertTrue(ResortStatus::Pending->canTransitionTo(ResortStatus::Rejected));
        $this->assertTrue(ResortStatus::Rejected->canTransitionTo(ResortStatus::Pending));
        $this->assertTrue(ResortStatus::Active->canTransitionTo(ResortStatus::Inactive));
        $this->assertTrue(ResortStatus::Inactive->canTransitionTo(ResortStatus::Active));

        // A rejected resort must be resubmitted; it cannot be activated directly.
        $this->assertFalse(ResortStatus::Rejected->canTransitionTo(ResortStatus::Active));
        $this->assertFalse(ResortStatus::Inactive->canTransitionTo(ResortStatus::Pending));
    }

    public function test_only_active_accounts_have_no_login_message(): void
    {
        $this->assertNull(UserStatus::Active->loginMessage());
        $this->assertNotNull(UserStatus::Pending->loginMessage());
        $this->assertNotNull(UserStatus::Rejected->loginMessage());
        $this->assertNotNull(UserStatus::Inactive->loginMessage());
    }

    public function test_enum_helpers(): void
    {
        /*
         * Three categories, cheapest first. Deliberately short: the list once
         * carried eight and owners filed rooms wrongly because several of them
         * meant the same thing. Pinned here so it is not quietly grown again.
         */
        $this->assertSame(['budget', 'premium', 'cottage'], RoomType::values());
        $this->assertSame('Premium', RoomType::options()['premium']);
    }

    /**
     * Every category has to explain itself. "Deluxe" and "Premium" are the
     * same word to most people, and an owner guessing is how a ₱600 room
     * ended up filed as a cottage.
     */
    public function test_every_room_category_tells_an_owner_what_it_means(): void
    {
        foreach (RoomType::cases() as $type) {
            $this->assertNotSame('', trim($type->hint()), "{$type->value} has no hint");
            $this->assertNotSame($type->label(), $type->hint());
        }
    }
}
