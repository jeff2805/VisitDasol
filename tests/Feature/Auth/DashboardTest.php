<?php

namespace Tests\Feature\Auth;

use App\Enums\PaymentPreference;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\ResortStatus;
use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tourist_dashboard_greets_a_guest_with_nothing_booked(): void
    {
        $tourist = User::factory()->create(['first_name' => 'Bea']);

        $this->actingAs($tourist)->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertSee('Hello, Bea')
            ->assertSee('No confirmed stay yet')
            // The button, not the sentence above it: asserting on "Find a
            // resort" alone passed off the hero's lead paragraph and would have
            // kept passing with no button on the page at all.
            ->assertSee('<a class="btn btn-primary" href="'.route('resorts.index').'">Find a room</a>', false)
            // With nothing booked there is no reservation history to show.
            ->assertDontSee('Recent reservations');
    }

    /**
     * A signed-in guest gets the same search a stranger gets.
     *
     * Phase 5 put "where, when, how many" on the public home page. Until this,
     * making an account took it away again: the dashboard offered only a link
     * to the listing, so the reward for signing up was fewer ways to find a
     * room than a passer-by has.
     */
    public function test_the_tourist_dashboard_asks_where_and_when(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertSee('action="'.route('resorts.index').'"', false)
            ->assertSee('name="check_in"', false)
            ->assertSee('name="check_out"', false)
            ->assertSee('Brgy. Tambobong');
    }

    public function test_the_tourist_dashboard_leads_with_the_next_confirmed_stay(): void
    {
        $tourist = User::factory()->create(['first_name' => 'Bea']);
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $room = Room::factory()->for($resort)->create(['name' => 'Seaview Villa']);

        // Further out, so it must not win.
        Booking::factory()->for($tourist, 'tourist')->for($resort)->for($room)
            ->forStay(today()->addDays(30)->toDateString(), today()->addDays(32)->toDateString())
            ->confirmed()->create();

        $soonest = Booking::factory()->for($tourist, 'tourist')->for($resort)->for($room)
            ->forStay(today()->addDays(5)->toDateString(), today()->addDays(7)->toDateString())
            ->confirmed()->create();

        $this->actingAs($tourist)->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertSee('You have 2 stays coming up')
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Seaview Villa')
            ->assertSee($soonest->booking_reference)
            ->assertSee('in 5 days')
            ->assertSee('Recent reservations')
            ->assertViewHas('nextStay', fn ($stay) => $stay->is($soonest));
    }

    public function test_the_tourist_dashboard_counts_only_that_tourists_own_bookings(): void
    {
        $bea = User::factory()->create();
        $kevin = User::factory()->create();
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $room = Room::factory()->for($resort)->create();

        Booking::factory()->for($kevin, 'tourist')->for($resort)->for($room)
            ->forStay(today()->addDays(3)->toDateString(), today()->addDays(4)->toDateString())
            ->confirmed()->create();

        $this->actingAs($bea)->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertSee('No confirmed stay yet')
            ->assertViewHas('nextStay', null)
            ->assertViewHas('stats', fn (array $stats) => $stats['upcoming'] === 0
                && $stats['pending'] === 0
                && $stats['completed'] === 0);
    }

    public function test_a_stay_that_has_already_ended_is_not_the_next_stay(): void
    {
        $tourist = User::factory()->create();
        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create();

        Booking::factory()->for($tourist, 'tourist')->for($resort)->for($room)
            ->forStay(today()->subDays(10)->toDateString(), today()->subDays(8)->toDateString())
            ->completed()->create();

        $this->actingAs($tourist)->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertSee('No confirmed stay yet')
            ->assertViewHas('stats', fn (array $stats) => $stats['completed'] === 1 && $stats['upcoming'] === 0);
    }

    /**
     * What is not paid yet is said on the dashboard, not only inside the
     * reservation.
     *
     * The panel showed the total and stopped there, which reads as settled. A
     * guest whose stay was confirmed and unpaid had to open the reservation to
     * find that out.
     */
    public function test_the_dashboard_says_what_is_not_paid_yet_on_the_next_stay(): void
    {
        $this->withGateway();

        $booking = $this->confirmedStay();

        $this->actingAs($booking->tourist)->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertSee('not paid yet')
            ->assertSee('Pay online')
            // And as an option, not a demand: the reservation page promises the
            // resort will still take the money on arrival.
            ->assertSee('settle at the resort')
            ->assertViewHas('dueOnNextStay', (float) $booking->total_amount);
    }

    /**
     * The dashboard and the reservation page never disagree about whether a
     * stay can be paid for.
     *
     * The first version of the dashboard line also required the guest to have
     * chosen "pay online" on the booking form. The reservation page asks no
     * such thing — it offers to take the money whatever was chosen — so for a
     * booking made before that choice existed, the reservation page showed a
     * pay button while the dashboard said nothing at all.
     */
    public function test_the_dashboard_and_the_reservation_agree_about_paying(): void
    {
        $this->withGateway();

        foreach ([
            'no preference recorded' => null,
            'chose to pay online' => PaymentPreference::Online,
            'chose to pay on arrival' => PaymentPreference::OnArrival,
        ] as $case => $preference) {
            $booking = $this->confirmedStay(['payment_preference' => $preference]);

            $reservationOffersPayment = str_contains(
                $this->actingAs($booking->tourist)->get(route('tourist.bookings.show', $booking))->getContent(),
                'id="pay-title"'
            );

            $dashboardSaysSo = str_contains(
                $this->actingAs($booking->tourist)->get(route('tourist.dashboard'))->getContent(),
                'not paid yet'
            );

            $this->assertSame(
                $reservationOffersPayment,
                $dashboardSaysSo,
                "The two pages disagree about paying when the guest {$case}."
            );
        }
    }

    /**
     * And the dashboard says nothing when there is nothing to act on. Either of
     * these would otherwise put a "Pay online" button in front of a guest that
     * leads nowhere or asks for money twice.
     */
    #[DataProvider('nothingToPay')]
    public function test_the_dashboard_stays_quiet_when_there_is_nothing_to_pay(
        bool $gatewayConfigured,
        bool $settle,
    ): void {
        config([
            'services.paymongo.secret' => $gatewayConfigured ? 'sk_test_pretend' : null,
            'services.paypal.client_id' => null,
            'services.paypal.secret' => null,
        ]);

        $booking = $this->confirmedStay();

        if ($settle) {
            // There is no payment factory: payments are only ever made by the
            // service. One written straight in is enough to stand for "paid".
            Payment::create([
                'payment_reference' => 'VDP-TEST-0001',
                'booking_id' => $booking->id,
                'provider' => PaymentProvider::PayMongo,
                'amount' => $booking->total_amount,
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);
        }

        $this->actingAs($booking->tourist)->get(route('tourist.dashboard'))
            ->assertOk()
            ->assertViewHas('dueOnNextStay', null)
            ->assertDontSee('not paid yet')
            ->assertDontSee('Pay online')
            // The reservation is still reachable; only the money line is gone.
            ->assertSee('View reservation');
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function nothingToPay(): array
    {
        return [
            // The situation while the office has no gateway keys at all.
            'no gateway configured' => [false, false],
            'already paid in full' => [true, true],
        ];
    }

    /** One gateway with pretend keys, so payment is possible at all. */
    private function withGateway(): void
    {
        config([
            'services.paymongo.secret' => 'sk_test_pretend',
            'services.paymongo.methods' => ['gcash'],
        ]);
    }

    /**
     * A confirmed stay five days out, for a tourist of its own.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function confirmedStay(array $attributes = []): Booking
    {
        $tourist = User::factory()->create();
        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create();

        return Booking::factory()->for($tourist, 'tourist')->for($resort)->for($room)
            ->forStay(today()->addDays(5)->toDateString(), today()->addDays(7)->toDateString())
            ->confirmed()
            ->create($attributes);
    }

    public function test_an_owner_without_a_resort_is_asked_to_register_one(): void
    {
        $owner = User::factory()->resortOwner()->create();

        $this->actingAs($owner)->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Your resort is not registered yet');
    }

    public function test_an_owner_sees_their_own_resort_and_its_status(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->create(['name' => 'Salt Breeze Resort']);
        Room::factory()->count(2)->for($resort)->create();
        Room::factory()->for($resort)->inactive()->create();

        // Another owner's resort must never appear.
        Resort::factory()->active()->create(['name' => 'Someone Else Resort']);

        $this->actingAs($owner)->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Pending review')
            ->assertSee('Waiting for review')
            // The count is the bookable ones; the total is the note beside it.
            // These were one run-on label, "Bookable room types (3 in total)",
            // written into the tile by hand rather than through <x-stat>.
            ->assertSee('Bookable room types')
            ->assertSee('3 room types in total')
            ->assertDontSee('Someone Else Resort');
    }

    /**
     * The owner's own money, on the page they land on.
     *
     * The dashboard carried no figure in pesos at all, so the one role whose
     * living depends on it had to open the statistics page to find out whether
     * anything was coming. Only confirmed stays count: a request the owner has
     * not answered yet is not income.
     */
    public function test_the_owner_dashboard_values_the_stays_still_ahead(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();
        $room = Room::factory()->for($resort)->create();

        $booking = fn (string $in, string $out) => Booking::factory()
            ->for($resort)->for($room)->for(User::factory()->create(), 'tourist')
            ->forStay($in, $out);

        $booking(today()->addDays(4)->toDateString(), today()->addDays(6)->toDateString())
            ->confirmed()->create(['total_amount' => 2500]);
        $booking(today()->addDays(10)->toDateString(), today()->addDays(12)->toDateString())
            ->confirmed()->create(['total_amount' => 4000]);

        // None of these may be counted: still only a request, already finished,
        // or called off.
        $booking(today()->addDays(8)->toDateString(), today()->addDays(9)->toDateString())
            ->create(['total_amount' => 9999]);
        $booking(today()->subDays(9)->toDateString(), today()->subDays(7)->toDateString())
            ->completed()->create(['total_amount' => 8888]);
        $booking(today()->addDays(20)->toDateString(), today()->addDays(21)->toDateString())
            ->cancelled()->create(['total_amount' => 7777]);

        $this->actingAs($owner)->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('comingValue', 6500.0)
            ->assertViewHas('comingCount', 2)
            ->assertSee('₱6,500')
            ->assertSee('2 stays at your listed rates')
            ->assertDontSee('9,999')
            ->assertDontSee('8,888')
            ->assertDontSee('7,777');
    }

    public function test_a_rejected_resort_shows_the_reason_to_its_owner(): void
    {
        $owner = User::factory()->resortOwner()->create();
        Resort::factory()->for($owner, 'owner')->rejected('Please add a clearer location pin.')->create();

        $this->actingAs($owner)->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Please add a clearer location pin.');
    }

    public function test_the_mto_dashboard_counts_work_waiting_for_review(): void
    {
        User::factory()->count(2)->resortOwner(UserStatus::Pending)->create();
        User::factory()->count(3)->create();
        Resort::factory()->count(2)->create();
        $live = Resort::factory()->active()->create();
        Room::factory()->for($live)->create();

        $office = User::factory()->superAdmin()->create();

        $this->actingAs($office)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Applications are waiting for your review.')
            ->assertSee('2 owner applications')
            ->assertSee('2 resort applications')
            ->assertViewHas('pendingOwnerCount', 2)
            ->assertViewHas('pendingResortCount', 2)
            // The headline cards count everything, not only what is public.
            ->assertViewHas('metrics', fn (array $metrics) => $this->metric($metrics, 'Total users') === User::count()
                && $this->metric($metrics, 'Total resorts') === Resort::count()
                && $this->metric($metrics, 'Total bookings') === 0);
    }

    public function test_the_mto_dashboard_says_when_there_is_nothing_to_review(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Nothing is waiting. You are all caught up.')
            ->assertDontSee('Applications are waiting for your review.');
    }

    /**
     * The running total on one of the dashboard's headline cards.
     *
     * @param  list<array<string, mixed>>  $metrics
     */
    private function metric(array $metrics, string $label): int
    {
        foreach ($metrics as $metric) {
            if ($metric['label'] === $label) {
                return $metric['total'];
            }
        }

        $this->fail("The dashboard has no \"{$label}\" card.");
    }
}
