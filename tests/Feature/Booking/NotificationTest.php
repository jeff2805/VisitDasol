<?php

namespace Tests\Feature\Booking;

use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Notifications\BookingDecided;
use App\Notifications\BookingRequested;
use App\Notifications\OwnerApplicationDecided;
use App\Notifications\ResortApplicationDecided;
use App\Services\AccountManager;
use App\Services\BookingStatusManager;
use App\Services\Notifier;
use App\Services\ResortManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Who gets told what, and when.
 *
 * Before these existed the system changed people's reservations and accounts
 * without ever telling them, so everyone had to keep reopening the site to
 * find out whether anything had happened.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private User $owner;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        NotificationFacade::fake();

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->room = Room::factory()->for($this->resort)->create(['capacity' => 4, 'total_rooms' => 3]);
        $this->tourist = User::factory()->create();
    }

    private function booking(string $state = 'pending'): Booking
    {
        $booking = Booking::factory()
            ->for($this->room)
            ->for($this->resort)
            ->for($this->tourist, 'tourist')
            ->forStay(now()->addDays(10)->toDateString(), now()->addDays(12)->toDateString());

        return ($state === 'confirmed' ? $booking->confirmed() : $booking)->create();
    }

    /* A new request ------------------------------------------------------- */

    public function test_the_owner_is_told_when_a_guest_asks_to_book(): void
    {
        $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), [
            'room' => $this->room->id,
            'check_in_date' => now()->addDays(20)->toDateString(),
            'check_out_date' => now()->addDays(22)->toDateString(),
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ])->assertSessionHasNoErrors();

        NotificationFacade::assertSentTo($this->owner, BookingRequested::class);
        // The guest already knows: they just pressed the button.
        NotificationFacade::assertNotSentTo($this->tourist, BookingRequested::class);
    }

    /* Decisions on a reservation ------------------------------------------ */

    public function test_the_guest_is_told_when_the_resort_confirms(): void
    {
        app(BookingStatusManager::class)->confirm($this->booking());

        NotificationFacade::assertSentTo($this->tourist, BookingDecided::class);
    }

    public function test_the_guest_is_told_why_a_request_was_declined(): void
    {
        app(BookingStatusManager::class)->reject($this->booking(), 'Fully booked that week.');

        NotificationFacade::assertSentTo(
            $this->tourist,
            BookingDecided::class,
            fn (BookingDecided $n): bool => str_contains(
                (string) $n->toMail($this->tourist)->render(),
                'Fully booked that week.'
            )
        );
    }

    public function test_a_cancellation_is_told_to_the_other_side(): void
    {
        // The guest cancels, so it is the resort that needs to hear about it.
        app(BookingStatusManager::class)
            ->cancel($this->booking('confirmed'), $this->tourist, 'Our trip was moved.');

        NotificationFacade::assertSentTo($this->owner, BookingDecided::class);
        NotificationFacade::assertNotSentTo($this->tourist, BookingDecided::class);
    }

    public function test_a_cancellation_by_the_resort_reaches_the_guest(): void
    {
        app(BookingStatusManager::class)
            ->cancel($this->booking('confirmed'), $this->owner, 'A storm is forecast.');

        NotificationFacade::assertSentTo($this->tourist, BookingDecided::class);
        NotificationFacade::assertNotSentTo($this->owner, BookingDecided::class);
    }

    /* Decisions by the tourism office ------------------------------------- */

    public function test_an_owner_hears_the_outcome_of_their_application(): void
    {
        $applicant = User::factory()->resortOwner(UserStatus::Pending)->create();

        app(AccountManager::class)->approveOwner($applicant);
        NotificationFacade::assertSentTo($applicant, OwnerApplicationDecided::class,
            fn (OwnerApplicationDecided $n): bool => $n->approved);

        $refused = User::factory()->resortOwner(UserStatus::Pending)->create();
        app(AccountManager::class)->rejectOwner($refused, 'The permit has expired.');

        NotificationFacade::assertSentTo($refused, OwnerApplicationDecided::class,
            fn (OwnerApplicationDecided $n): bool => ! $n->approved && $n->remarks === 'The permit has expired.');
    }

    public function test_an_owner_hears_the_outcome_of_their_listing(): void
    {
        $pending = Resort::factory()->for($this->owner, 'owner')->create();

        app(ResortManager::class)->approve($pending);

        NotificationFacade::assertSentTo($this->owner, ResortApplicationDecided::class,
            fn (ResortApplicationDecided $n): bool => $n->approved);
    }

    public function test_a_rejected_listing_says_what_needs_changing(): void
    {
        $pending = Resort::factory()->for($this->owner, 'owner')->create();

        app(ResortManager::class)->reject($pending, 'The location pin is in the sea.');

        NotificationFacade::assertSentTo($this->owner, ResortApplicationDecided::class,
            fn (ResortApplicationDecided $n): bool => ! $n->approved
                && $n->remarks === 'The location pin is in the sea.');
    }

    /* Reliability ---------------------------------------------------------- */

    public function test_a_mail_failure_never_undoes_the_action_that_caused_it(): void
    {
        Log::spy();

        $recipient = new class
        {
            public function notify(Notification $notification): void
            {
                throw new \RuntimeException('SMTP is down');
            }

            public function getKey(): int
            {
                return 1;
            }
        };

        // The point: this does not throw. A confirmed booking stays confirmed
        // even when the mail server is unreachable.
        app(Notifier::class)->send($recipient, new BookingRequested($this->booking()));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_nothing_is_attempted_when_there_is_nobody_to_tell(): void
    {
        app(Notifier::class)->send(null, new BookingRequested($this->booking()));

        NotificationFacade::assertNothingSent();
    }
}
