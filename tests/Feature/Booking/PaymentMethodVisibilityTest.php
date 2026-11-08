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
 * Which ways of paying a resort is offered, and which it will accept.
 *
 * WHAT WAS WRONG. PaymentPreference::isAvailable() asked only whether
 * a METHOD existed — is PayMongo configured, has this resort a GCash
 * account — and never whether the resort's own terms allowed it. So:
 *
 *   - "Pay at the resort" was offered by every resort on the site,
 *     including those whose policy has allow_on_site switched off. A
 *     guest could pick it and a required deposit became nothing to pay.
 *
 *   - Manual GCash was offered by resorts asking for no deposit at
 *     all, sending guests to produce a reference number for a payment
 *     nobody had asked for.
 *
 * Both lists — the one the form draws and the one the server accepts —
 * came from that same method, so they were wrong together and agreed
 * with each other while they were.
 */
class PaymentMethodVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A resort with a payment policy of its own.
     *
     * @param array<string, mixed> $policy
     */
    private function resortWith(array $policy, bool $withGcash = false): Resort
    {
        $owner = User::factory()->create([
            'role' => 'resort_owner',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

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

        return $resort->refresh();
    }

    private function roomAt(Resort $resort): Room
    {
        return Room::factory()->for($resort)->create([
            'price_per_night' => 1500,
            'capacity' => 4,
            'total_rooms' => 2,
        ]);
    }

    private function tourist(): User
    {
        return User::factory()->create([
            'role' => 'tourist',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function formQuery(Room $room): array
    {
        return [
            'room' => $room->id,
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(22)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Room $room, string $preference): array
    {
        return [
            'room' => $room->id,
            'check_in_date' => today()->addDays(20)->toDateString(),
            'check_out_date' => today()->addDays(22)->toDateString(),
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
            'payment_preference' => $preference,
            'id_document' => $this->validId(),
        ];
    }

    /**
     * The methods this resort allows for this stay, worked out the
     * same way the controller and the service work them out.
     *
     * @return list<PaymentPreference>
     */
    private function preferencesFor(Resort $resort, Room $room): array
    {
        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $resort->paymentTerms(),
            (string) ($room->price_per_night * 2),
            \Carbon\CarbonImmutable::today()->addDays(20),
        );

        return PaymentPreference::available(
            $resort->manualAccount()->where('active', true)->first(),
            $terms,
        );
    }

    private function offered(Room $room): string
    {
        return $this->actingAs($this->tourist())
            ->get(route('tourist.bookings.create', $this->formQuery($room)))
            ->assertOk()
            ->getContent();
    }

    /* No deposit ---------------------------------------------------------- */

    /**
     * "Pay at the resort" is withdrawn, even where the resort's own
     * policy still permits cash on arrival.
     *
     * THE POLICY SWITCH IS A DIFFERENT THING and is deliberately left
     * on in this test. allow_on_site still shapes the payment terms
     * and the sentence a guest reads about settling at the resort; it
     * simply no longer puts a radio button on the booking form.
     */
    /**
     * NO ADVANCE PAYMENT MEANS PAY AT THE RESORT, WHATEVER THE SWITCH SAYS.
     *
     * THIS TEST USED TO ASSERT THE OPPOSITE. "Pay at the resort" had
     * been withdrawn outright, which left a resort asking for nothing up
     * front offering the guest NO method at all: both prepaid options
     * are hidden because there is no amount for them to collect, and
     * this one was hidden on principle. The form drew an empty list.
     *
     * allow_on_site is deliberately false in one of the two cases below.
     * An owner who asks for no advance payment has already said the
     * money comes on arrival, and a switch saying otherwise would leave
     * the booking with no way to be paid at all — so the requirement
     * wins. The switch still shapes the terms and the last-minute rule,
     * which the test below this one holds.
     */
    public function test_paying_at_the_resort_is_offered_when_nothing_is_due(): void
    {
        $resort = $this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => true,
        ]);

        $this->assertContains(
            PaymentPreference::OnArrival,
            $this->preferencesFor($resort, $this->roomAt($resort)),
        );
    }

    /**
     * BOTH CONDITIONS, AND THE SWITCH IS ONE OF THEM.
     *
     * booking-form.js has always hidden this option where the resort
     * does not take money on arrival. The server did not, so the two
     * disagreed about the same booking. They ask the same question now.
     */
    public function test_paying_at_the_resort_needs_the_resorts_own_switch(): void
    {
        $resort = $this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => false,
        ]);

        $this->assertNotContains(
            PaymentPreference::OnArrival,
            $this->preferencesFor($resort, $this->roomAt($resort)),
        );
    }

    /**
     * AND ALSO WHERE MONEY IS DUE, BECAUSE THE SWITCH SAID SO.
     *
     * This asserted the opposite: the option was withheld whenever the
     * terms asked for anything before arrival, on the reasoning that a
     * deposit cannot be settled on the day. True of the deposit, but
     * not a reason to withdraw the method — and in practice it made
     * the owner's switch look broken. One resort, "No advance payment"
     * with a last-minute deposit rule, offered Pay at the resort for a
     * stay six weeks out and withheld it for one tomorrow, with
     * nothing on either screen explaining the difference.
     *
     * The amounts are untouched: the deposit is still worked out, still
     * due, and still shown. The method says where it is handed over.
     */
    public function test_paying_at_the_resort_is_offered_even_when_money_is_due(): void
    {
        foreach ([PaymentRequirement::Deposit, PaymentRequirement::Full] as $requirement) {
            $resort = $this->resortWith([
                'requirement' => $requirement,
                'deposit_percent' => 30,
                'allow_on_site' => true,
            ]);

            $this->assertContains(
                PaymentPreference::OnArrival,
                $this->preferencesFor($resort, $this->roomAt($resort)),
                $requirement->value,
            );
        }
    }

    /**
     * The switch is the whole gate, so turning it off withdraws the
     * method whatever the terms say.
     */
    public function test_the_switch_withdraws_it_whatever_is_due(): void
    {
        foreach ([PaymentRequirement::None, PaymentRequirement::Deposit, PaymentRequirement::Full] as $requirement) {
            $resort = $this->resortWith([
                'requirement' => $requirement,
                'deposit_percent' => 30,
                'allow_on_site' => false,
            ]);

            $this->assertNotContains(
                PaymentPreference::OnArrival,
                $this->preferencesFor($resort, $this->roomAt($resort)),
                $requirement->value,
            );
        }
    }

    /**
     * THE REASON THIS CHANGED, HELD AS A TEST.
     *
     * One resort, one switch, two stays. A last-minute rule puts a
     * deposit on the nearer one; the owner's answer to "do you take
     * payment at the desk?" is the same for both, so the option is too.
     */
    public function test_one_resort_answers_the_same_for_a_near_and_a_far_stay(): void
    {
        $resort = $this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 30,
            'allow_on_site' => true,
            'last_minute_rule' => \App\Enums\LastMinuteRule::Deposit,
            'last_minute_hours' => 48,
        ]);

        $policy = $resort->fresh()->paymentTerms();
        $service = app(\App\Services\Payments\PaymentPolicyService::class);

        $tomorrow = $service->terms($policy, '3000.00', now()->addDay()->toImmutable());
        $farOff = $service->terms($policy, '3000.00', now()->addWeeks(6)->toImmutable());

        // The near stay really does owe a deposit; that part is unchanged.
        $this->assertTrue($tomorrow->needsAdvancePayment());
        $this->assertFalse($farOff->needsAdvancePayment());

        // And the method is offered for both, because the switch is on.
        $this->assertTrue(PaymentPreference::OnArrival->isAvailable(null, $tomorrow));
        $this->assertTrue(PaymentPreference::OnArrival->isAvailable(null, $farOff));
    }

    /** The resort owner's policy switch itself is untouched. */
    public function test_the_resorts_own_on_site_policy_still_works(): void
    {
        $resort = $this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => true,
        ]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $resort->paymentTerms(),
            '3000.00',
            \Carbon\CarbonImmutable::today()->addDays(20),
        );

        // The switch itself is untouched and still reaches the terms.
        $this->assertTrue($terms->onSiteAllowed);

        /*
         * The sentence no longer branches on it. It used to read "the
         * resort will tell you how to settle" where the switch was off,
         * which is not an answer to a guest asking how they pay.
         */
        $this->assertSame(
            'No advance payment required. Pay directly at the resort upon arrival.',
            $terms->sentence()
        );
    }

    /** The same sentence where the switch is off, for the same reason. */
    public function test_the_no_payment_sentence_does_not_depend_on_the_switch(): void
    {
        $resort = $this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => false,
        ]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $resort->paymentTerms(),
            '3000.00',
            \Carbon\CarbonImmutable::today()->addDays(20),
        );

        $this->assertFalse($terms->onSiteAllowed);

        /*
         * It does not promise a method the form will not offer. With
         * the switch off, "Pay at the resort" is not among the choices,
         * so the sentence does not name it.
         */
        $this->assertSame(
            'No advance payment required. The resort will tell you how to settle.',
            $terms->sentence()
        );
    }

    /**
     * Nothing is due in advance, so there is nothing for GCash to
     * collect and the option is not shown.
     */
    /**
     * BOTH SWITCHES ON MEANS BOTH METHODS, WHATEVER THE TERMS ASK FOR.
     *
     * This asserted that GCash disappeared where nothing was due in
     * advance. Between that and the matching rule on paying at the
     * resort, a resort with both switches on showed the guest exactly
     * one method and the terms picked which — so an owner turning a
     * switch on saw nothing change, and changing the last-minute rule
     * silently swapped one method for the other.
     *
     * What the amount still decides is whether a payment can be opened,
     * which is held by test_no_manual_payment_can_be_started_when_
     * nothing_is_due over in PaymentPolicyIntegrationTest.
     */
    public function test_both_methods_are_offered_when_nothing_is_due(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => true,
        ], withGcash: true));

        $html = $this->offered($room);

        $this->assertStringContainsString(
            'value="'.PaymentPreference::ManualGcash->value.'"',
            $html,
        );

        $this->assertStringContainsString(
            'value="'.PaymentPreference::OnArrival->value.'"',
            $html,
        );
    }

    /* With a deposit ------------------------------------------------------- */

    public function test_a_deposit_with_an_active_account_offers_manual_gcash(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true));

        $this->assertStringContainsString(
            'value="'.PaymentPreference::ManualGcash->value.'"',
            $this->offered($room),
        );
    }

    public function test_a_deposit_without_an_account_does_not_offer_manual_gcash(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: false));

        $this->assertStringNotContainsString(
            'value="'.PaymentPreference::ManualGcash->value.'"',
            $this->offered($room),
        );
    }

    /**
     * Off the page when the owner's switch is off, and on it when the
     * switch is on — with a deposit due in both cases.
     *
     * The deposit used to be what decided this, so the switch had no
     * visible effect on a stay that owed one. It is the whole answer
     * now; see test_paying_at_the_resort_is_offered_even_when_money_is_due.
     */
    public function test_the_switch_is_what_puts_it_on_the_form(): void
    {
        $withheld = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 50,
            'allow_on_site' => false,
        ], withGcash: true));

        $html = $this->offered($withheld);

        $this->assertStringNotContainsString(
            'value="'.PaymentPreference::OnArrival->value.'"',
            $html,
        );

        $this->assertStringNotContainsString('Pay at the resort', $html);

        // The same terms, the switch the other way.
        $offered = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 50,
            'allow_on_site' => true,
        ], withGcash: true));

        $this->assertStringContainsString(
            'value="'.PaymentPreference::OnArrival->value.'"',
            $this->offered($offered),
        );
    }

    /* The server decides --------------------------------------------------- */

    /**
     * The one that matters: a guest who sends "pay at the resort" when
     * the resort does not take it does not get a booking.
     *
     * HIDING IS NOT ENFORCEMENT. booking-form.js takes the option off
     * the screen; this is the check that makes it stick against anyone
     * posting straight at the route.
     *
     * A deposit is due here as well, to show that it is the switch
     * doing the refusing and not the amount — the amount no longer has
     * a say in which methods exist.
     */
    public function test_the_server_refuses_paying_at_the_resort_when_the_switch_is_off(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 50,
            'allow_on_site' => false,
        ], withGcash: true));

        $this->actingAs($this->tourist())
            ->from(route('tourist.bookings.create', $this->formQuery($room)))
            ->post(
                route('tourist.bookings.store'),
                $this->payload($room, PaymentPreference::OnArrival->value),
            );

        $this->assertDatabaseCount('bookings', 0);
    }

    /** And takes it when the resort does, deposit or no deposit. */
    public function test_the_server_accepts_paying_at_the_resort_when_the_switch_is_on(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 50,
            'allow_on_site' => true,
        ], withGcash: true));

        $this->actingAs($this->tourist())
            ->post(
                route('tourist.bookings.store'),
                $this->payload($room, PaymentPreference::OnArrival->value),
            )
            ->assertSessionHasNoErrors();

        $booking = \App\Models\Booking::query()->latest('id')->firstOrFail();

        $this->assertSame(PaymentPreference::OnArrival, $booking->payment_preference);

        // THE DEPOSIT IS UNTOUCHED. The method said where the money is
        // handed over, not how much of it is owed.
        $this->assertNotSame('0.00', $booking->amount_due_now);
    }

    public function test_the_server_refuses_manual_gcash_when_the_resort_has_no_account(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: false));

        $this->actingAs($this->tourist())
            ->from(route('tourist.bookings.create', $this->formQuery($room)))
            ->post(
                route('tourist.bookings.store'),
                $this->payload($room, PaymentPreference::ManualGcash->value),
            );

        $this->assertDatabaseCount('bookings', 0);
    }

    /** An allowed method still goes through, unchanged. */
    public function test_an_allowed_method_is_accepted_and_stored(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true));

        $this->actingAs($this->tourist())->post(
            route('tourist.bookings.store'),
            $this->payload($room, PaymentPreference::ManualGcash->value),
        );

        $booking = Booking::query()->latest('id')->first();

        $this->assertNotNull($booking, 'An allowed payment method must still book.');
        $this->assertSame(PaymentPreference::ManualGcash, $booking->payment_preference);
    }

    /* The rule itself ------------------------------------------------------- */

    /**
     * With no terms, the old answers come back.
     *
     * Callers that are not deciding for one booking — a report, a
     * seeder, a screen listing what the site can do — ask what exists,
     * not what this stay permits.
     */
    public function test_without_terms_the_methods_are_reported_as_before(): void
    {
        // Withdrawn everywhere, with terms or without.
        $this->assertFalse(PaymentPreference::OnArrival->isAvailable());
        $this->assertFalse(PaymentPreference::ManualGcash->isAvailable());
    }

    /**
     * The case survives even though the option is gone.
     *
     * bookings.payment_preference is cast to this enum. Deleting the
     * case would not remove an old reservation — it would make it
     * unreadable, and throw on the guest's own page, the owner's list
     * and the office reports alike.
     */
    public function test_an_older_booking_that_chose_it_can_still_be_read(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true));

        $this->actingAs($this->tourist())->post(
            route('tourist.bookings.store'),
            $this->payload($room, PaymentPreference::ManualGcash->value),
        );

        $booking = Booking::query()->latest('id')->sole();

        // As a reservation taken before the option was withdrawn.
        $booking->forceFill(['payment_preference' => 'on_arrival'])->save();

        $read = Booking::query()->findOrFail($booking->id);

        $this->assertSame(PaymentPreference::OnArrival, $read->payment_preference);
        $this->assertSame('Pay at the resort', $read->payment_preference->label());
    }

    /** The script hides what the terms do not allow, as the page is used. */
    public function test_the_script_hides_methods_the_terms_do_not_allow(): void
    {
        $script = file_get_contents(public_path('js/booking-form.js'));

        $this->assertStringContainsString('function applyPaymentMethods(', $script);
        $this->assertStringContainsString("radio.value === 'on_arrival'", $script);
    }
}
