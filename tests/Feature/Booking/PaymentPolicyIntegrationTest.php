<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentPreference;
use App\Enums\PaymentRequirement;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's Payment Terms, end to end into the guest's booking.
 *
 * THREE PLACES DECIDE WHICH METHODS EXIST and they have to agree: the
 * booking form draws them, StoreBookingRequest accepts them, and
 * BookingService refuses anything else. The first and last passed the
 * stay's real terms; the rule in the middle passed only the GCash
 * account, so it was answering a different question — and the moment
 * "Pay at the resort" started depending on the terms, it would have
 * rejected the one option the form had shown.
 *
 * Every assertion here goes through a real HTTP request, because the
 * requirement is that the rules hold server-side and not only in the
 * script that redraws the figures as the guest types.
 */
class PaymentPolicyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tourist = User::factory()->create();
    }

    /** A resort with the given terms, and a room in it. */
    private function roomWith(array $policy, bool $withGcash = false): Room
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->active()->for($owner, 'owner')->create();

        $resort->paymentPolicy()->create(array_merge([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'allow_on_site' => true,
        ], $policy));

        if ($withGcash) {
            $resort->manualAccount()->create([
                'account_name' => 'Juan Dela Cruz',
                'gcash_number' => '09171234567',
                'active' => true,
            ]);
        }

        return Room::factory()->for($resort->refresh())->create([
            'price_per_night' => 1500, 'capacity' => 4, 'total_rooms' => 2,
        ]);
    }

    private function formFor(Room $room): string
    {
        return $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $room->id,
                'check_in' => today()->addDays(20)->toDateString(),
                'check_out' => today()->addDays(22)->toDateString(),
            ]))
            ->assertOk()
            ->getContent();
    }

    /** @return array<string, mixed> */
    private function payload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room' => $room->id,
            'check_in_date' => today()->addDays(20)->toDateString(),
            'check_out_date' => today()->addDays(22)->toDateString(),
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
            'id_document' => $this->validId(),
        ], $overrides);
    }

    private function book(Room $room, array $overrides = [])
    {
        return $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($room, $overrides));
    }

    /* No advance payment ----------------------------------------------------- */

    /**
     * THE CASE THAT OFFERED NOTHING AT ALL.
     *
     * Both prepaid methods are hidden because there is no amount for
     * them to collect, and "Pay at the resort" was withdrawn outright,
     * so the form drew an empty list and never told the guest how they
     * would pay.
     */
    public function test_no_advance_payment_offers_pay_at_the_resort(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ], withGcash: true);

        $html = $this->formFor($room);

        $this->assertStringContainsString('value="'.PaymentPreference::OnArrival->value.'"', $html);
        $this->assertStringContainsString('Pay at the resort', $html);

        // And it is the one selected, or the form posts no preference.
        $this->assertMatchesRegularExpression(
            '/value="on_arrival"[^>]*\schecked\b/s',
            $html
        );
    }

    /** The message the guest reads, in both the form and the summary. */
    public function test_the_no_advance_payment_message_is_shown(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ]);

        $this->assertStringContainsString(
            'No advance payment required. Pay directly at the resort upon arrival.',
            $this->formFor($room)
        );
    }

    /** Nothing is due before arrival, and the bill itself is unchanged. */
    public function test_no_advance_payment_sets_the_amount_due_to_zero(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ]);

        $this->book($room, ['payment_preference' => PaymentPreference::OnArrival->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame('0.00', (string) $booking->amount_due_now);
        $this->assertSame(PaymentRequirement::None, $booking->payment_requirement);

        // Two nights at 1,500 — the total is untouched; only the timing moved.
        $this->assertSame('3000.00', (string) $booking->total_amount);
    }

    /**
     * THE SERVER HAS TO ACCEPT WHAT THE FORM OFFERED.
     *
     * StoreBookingRequest builds its accepted list from the same enum,
     * and it used to do so without the terms — so it would have refused
     * "on_arrival" as an invalid choice while the form was showing it.
     */
    public function test_the_server_accepts_pay_at_the_resort_when_nothing_is_due(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ]);

        $this->book($room, ['payment_preference' => PaymentPreference::OnArrival->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            PaymentPreference::OnArrival,
            Booking::query()->latest('id')->firstOrFail()->payment_preference
        );
    }

    /** No deposit means no GCash, so no reference number and no receipt. */
    public function test_no_advance_payment_asks_for_no_gcash_proof(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ], withGcash: true);

        $html = $this->formFor($room);

        /*
         * GCash is still offered — the resort accepts it, and that is
         * the owner's answer, not the stay's. What "no advance payment"
         * decides is that there is nothing to collect yet, so no
         * reference number and no receipt are ever asked for; the next
         * test holds that.
         */
        $this->assertStringContainsString('value="'.PaymentPreference::ManualGcash->value.'"', $html);

        $this->book($room, ['payment_preference' => PaymentPreference::ManualGcash->value])
            ->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame('0.00', (string) $booking->amount_due_now);
        $this->assertSame(0, $booking->payments()->count());
    }

    /** The guest cannot open a manual payment there is nothing to pay. */
    public function test_no_manual_payment_can_be_started_when_nothing_is_due(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ], withGcash: true);

        $this->book($room);
        $booking = Booking::query()->latest('id')->firstOrFail();
        $booking->forceFill(['status' => 'confirmed'])->save();

        $this->actingAs($this->tourist)
            ->post(route('tourist.manual-payments.start', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, $booking->payments()->count());
    }

    /** The whole bill is left to settle on arrival. */
    public function test_pay_at_the_resort_leaves_the_full_amount_as_the_balance(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $room->resort->paymentTerms(),
            '3000.00',
            \Carbon\CarbonImmutable::today()->addDays(20),
        );

        $this->assertSame('0.00', $terms->dueNow);
        $this->assertSame('3000.00', $terms->balance);
        $this->assertSame('3000.00', $terms->total);
        $this->assertFalse($terms->needsAdvancePayment());

        // The configured percentage is not applied, whatever it says.
        $this->assertSame(0, $terms->depositPercent);
    }

    /**
     * A RESORT THAT DOES NOT TAKE MONEY ON ARRIVAL DOES NOT OFFER IT.
     *
     * booking-form.js has always hidden the option in this case. The
     * server now refuses it too, so hiding is no longer the only thing
     * standing between a guest and a method the resort will not take.
     */
    public function test_pay_at_the_resort_cannot_be_bypassed_when_the_switch_is_off(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => false,
        ]);

        $html = $this->formFor($room);
        $this->assertStringNotContainsString('value="'.PaymentPreference::OnArrival->value.'"', $html);

        // And posted by hand it is refused, not quietly accepted.
        $this->book($room, ['payment_preference' => PaymentPreference::OnArrival->value])
            ->assertSessionHasErrors('payment_preference');

        $this->assertDatabaseCount('bookings', 0);
    }

    /* Nothing is paid by booking ----------------------------------------------- */

    /**
     * SENDING A REQUEST IS NOT PAYING, AND NEITHER IS BEING ACCEPTED.
     *
     * Both arrangements stay unpaid until a payment is recorded and
     * verified. The resort's approval moves the booking's status and
     * touches no money.
     */
    public function test_booking_and_approval_leave_the_payment_unpaid(): void
    {
        foreach ([
            ['requirement' => PaymentRequirement::None, 'deposit_percent' => 0],
            ['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30],
        ] as $policy) {
            $tourist = User::factory()->create();
            $room = $this->roomWith($policy, withGcash: true);

            $this->actingAs($tourist)->post(
                route('tourist.bookings.store'),
                $this->payload($room)
            )->assertSessionHasNoErrors();

            $booking = Booking::query()->latest('id')->firstOrFail();

            // Sending the request paid nothing.
            $this->assertSame(0, $booking->payments()->count());
            $this->assertSame(\App\Enums\BookingStatus::Pending, $booking->status);

            $this->actingAs($room->resort->owner)
                ->patch(route('owner.bookings.confirm', $booking))
                ->assertRedirect();

            $booking->refresh();

            // Accepting it did not either.
            $this->assertSame(\App\Enums\BookingStatus::Confirmed, $booking->status);
            $this->assertSame(0, $booking->payments()->where('status', 'paid')->count());
        }
    }

    /* The last-minute rule still bites ------------------------------------------ */

    /**
     * An imminent arrival is governed by the resort's last-minute rule
     * instead of its ordinary one, and that is untouched by any of this.
     */
    public function test_the_last_minute_rule_still_overrides_the_ordinary_one(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'last_minute_rule' => 'full',
            'last_minute_hours' => 72,
        ]);

        $policies = app(\App\Services\Payments\PaymentPolicyService::class);
        $terms = $policies->terms(
            $room->resort->paymentTerms(),
            '3000.00',
            \Carbon\CarbonImmutable::today()->addDay(),
        );

        $this->assertTrue($terms->lastMinute);
        $this->assertSame(PaymentRequirement::Full, $terms->requirement);
        $this->assertSame('3000.00', $terms->dueNow);

        // Nothing is due only for an arrival outside that window.
        $this->assertSame(
            '0.00',
            $policies->terms(
                $room->resort->paymentTerms(),
                '3000.00',
                \Carbon\CarbonImmutable::today()->addDays(20),
            )->dueNow
        );
    }

    /* A deposit --------------------------------------------------------------- */

    public function test_a_deposit_is_required_and_the_balance_is_shown(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true);

        $html = $this->formFor($room);

        // 30% of 3,000.
        $this->assertStringContainsString('900.00', $html);
        $this->assertStringContainsString('2,100.00', $html);
        $this->assertStringContainsString('30% deposit', $html);

        /*
         * Both methods are offered: the resort takes GCash and it takes
         * money at the desk. The deposit above is unchanged either way
         * — it says how much, the method says where.
         */
        $this->assertStringContainsString('value="'.PaymentPreference::ManualGcash->value.'"', $html);
        $this->assertStringContainsString('value="'.PaymentPreference::OnArrival->value.'"', $html);
    }

    public function test_a_deposit_is_stored_on_the_booking(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ]);

        $this->book($room)->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame('900.00', (string) $booking->amount_due_now);
        $this->assertSame(30, (int) $booking->payment_deposit_percent);
        $this->assertSame(PaymentRequirement::Deposit, $booking->payment_requirement);
    }

    /**
     * Paying on arrival is taken even while a deposit is owed, and the
     * deposit survives the choice.
     *
     * This asserted the refusal. The deposit was what decided whether
     * the method existed, which made the owner's switch look like it
     * did nothing on any stay that owed one. The switch decides now;
     * what the deposit decides is the amount, and that is checked here
     * rather than assumed.
     */
    public function test_pay_at_the_resort_is_taken_and_the_deposit_still_stands(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ]);

        $this->book($room, ['payment_preference' => PaymentPreference::OnArrival->value])
            ->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame(PaymentPreference::OnArrival, $booking->payment_preference);
        $this->assertSame('900.00', (string) $booking->amount_due_now);
        $this->assertSame(PaymentRequirement::Deposit, $booking->payment_requirement);
    }

    /** And refused where the resort has said it does not take it. */
    public function test_pay_at_the_resort_is_refused_when_the_resort_switched_it_off(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'allow_on_site' => false,
        ]);

        $this->book($room, ['payment_preference' => PaymentPreference::OnArrival->value])
            ->assertSessionHasErrors('payment_preference');

        $this->assertDatabaseCount('bookings', 0);
    }

    /* The full amount --------------------------------------------------------- */

    public function test_the_full_amount_is_required_before_arrival(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::Full,
            'deposit_percent' => 30,
        ]);

        /*
         * The form is read BEFORE booking. A tourist may hold only one
         * unfinished reservation, so once this one exists the create
         * page answers with the "you already have a booking" screen
         * instead of the form — a 200, but not the page being asserted.
         */
        $this->assertStringContainsString('the full amount', $this->formFor($room));

        $this->book($room)->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame('3000.00', (string) $booking->amount_due_now);
        $this->assertSame('3000.00', (string) $booking->total_amount);
        $this->assertSame(PaymentRequirement::Full, $booking->payment_requirement);
    }

    /* Changing the terms ------------------------------------------------------- */

    /**
     * A NEW BOOKING FOLLOWS THE NEW TERMS; AN OLD ONE KEEPS ITS OWN.
     *
     * Each booking snapshots the terms it was agreed under, so an owner
     * raising their deposit cannot reach back into a reservation a guest
     * is already working to.
     */
    public function test_changing_the_terms_moves_new_bookings_only(): void
    {
        $room = $this->roomWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
        ]);

        $this->book($room, ['payment_preference' => PaymentPreference::OnArrival->value]);
        $first = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame('0.00', (string) $first->amount_due_now);

        /*
         * The owner now asks for half up front — through their own
         * screen, not by writing to the table. The controller is what
         * stamps a new version on the policy, and a booking records
         * which edition it was made under.
         */
        $this->actingAs($room->resort->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'deposit',
            'deposit_percent' => 50,
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 72,
            'allow_on_site' => '1',
        ])->assertSessionHasNoErrors();

        // The guest's existing reservation is unmoved.
        $this->assertSame('0.00', (string) $first->fresh()->amount_due_now);
        $this->assertSame(PaymentRequirement::None, $first->fresh()->payment_requirement);

        // A second guest, booking now, meets the new terms.
        $other = User::factory()->create();

        $this->actingAs($other)->post(
            route('tourist.bookings.store'),
            $this->payload($room, ['contact_name' => 'Noel Cruz'])
        )->assertSessionHasNoErrors();

        $second = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame('1500.00', (string) $second->amount_due_now);
        $this->assertSame(PaymentRequirement::Deposit, $second->payment_requirement);
        $this->assertGreaterThan(
            (int) $first->payment_policy_version,
            (int) $second->payment_policy_version
        );
    }
}
