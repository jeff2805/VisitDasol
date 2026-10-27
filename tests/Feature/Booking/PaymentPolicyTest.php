<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentRequirement;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\ResortPaymentPolicy;
use App\Models\Room;
use App\Models\User;
use App\Services\Payments\PaymentPolicyService;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each resort asks for up front, and by when.
 *
 * Two things matter here above all. A guest must be charged what their
 * resort actually asked for and not the whole bill by default; and the
 * terms a booking was made under must survive the owner changing their
 * mind afterwards.
 */
class PaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tourist;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->tourist = User::factory()->create();

        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();

        // ₱1,000 a night, so the arithmetic in these tests is readable.
        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 6,
            'total_rooms' => 4,
            'price_per_night' => 1000,
        ]);
    }

    private function policy(array $attributes = []): ResortPaymentPolicy
    {
        $policy = $this->resort->paymentPolicy()->firstOrNew();

        $policy->fill($attributes + [
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'deadline_reference' => PaymentDeadlineReference::Confirmation,
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
            'allow_on_site' => true,
        ]);

        $policy->resort()->associate($this->resort);
        $policy->version = (int) ($policy->version ?? 0) + 1;
        $policy->save();

        return $policy->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'room' => $this->room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-15',   // 5 nights at 1,000 = 5,000
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'origin' => 'pangasinan',
        ], $overrides);

        $guests = (int) $payload['adults'] + (int) $payload['children'];
        $payload['guests_male'] ??= intdiv($guests, 2);
        $payload['guests_female'] ??= $guests - intdiv($guests, 2);

        return $payload;
    }

    private function book(array $overrides = []): Booking
    {
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return Booking::latest('id')->sole();
    }

    /* The four scenarios ---------------------------------------------------- */

    public function test_a_deposit_is_a_share_of_the_bill_and_leaves_a_balance(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $booking = $this->book();

        $this->assertSame('5000.00', $booking->total_amount);
        $this->assertSame('1500.00', $booking->amount_due_now);
        $this->assertSame(PaymentRequirement::Deposit, $booking->payment_requirement);
        $this->assertSame(30, $booking->payment_deposit_percent);

        $terms = app(PaymentPolicyService::class)->stored($booking);
        $this->assertSame('3500.00', $terms->balance);
    }

    public function test_an_imminent_arrival_falls_under_the_last_minute_rule(): void
    {
        $this->policy([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
        ]);

        // Arriving tomorrow, which is inside the resort's 48-hour window.
        $booking = $this->book([
            'check_in_date' => '2030-06-02',
            'check_out_date' => '2030-06-07',
        ]);

        $this->assertSame('5000.00', $booking->total_amount);
        $this->assertSame('5000.00', $booking->amount_due_now);
        $this->assertSame(PaymentRequirement::Full, $booking->payment_requirement);
        $this->assertTrue($booking->payment_last_minute);
    }

    public function test_a_resort_may_take_nothing_up_front(): void
    {
        $this->policy(['requirement' => PaymentRequirement::None]);

        $booking = $this->book();

        $this->assertSame('0.00', $booking->amount_due_now);
        $this->assertSame(PaymentRequirement::None, $booking->payment_requirement);
        $this->assertNull($booking->payment_due_at);

        // Nothing is owed before arrival, so nothing may be charged.
        $this->assertSame(0.0, app(PaymentService::class)->dueNow($booking));
        $this->assertFalse(app(PaymentService::class)->isPayable($booking));

        // The whole bill is still owed to the resort, just not yet.
        $this->assertSame(5000.0, app(PaymentService::class)->outstanding($booking));
    }

    public function test_a_last_minute_booking_may_be_let_through_to_pay_on_site(): void
    {
        $this->policy([
            'requirement' => PaymentRequirement::Full,
            'last_minute_rule' => 'on_site',
            'last_minute_hours' => 48,
        ]);

        $booking = $this->book([
            'check_in_date' => '2030-06-02',
            'check_out_date' => '2030-06-07',
        ]);

        $this->assertSame('0.00', $booking->amount_due_now);
        $this->assertTrue($booking->payment_last_minute);
        $this->assertTrue($booking->payment_on_site_allowed);
    }

    /* Deadlines -------------------------------------------------------------- */

    public function test_a_deadline_counted_from_confirmation_waits_for_the_owner(): void
    {
        $this->policy(['deadline_reference' => PaymentDeadlineReference::Confirmation, 'deadline_hours' => 24]);

        $booking = $this->book();

        // Nothing to count from yet, so no date is invented.
        $this->assertNull($booking->payment_due_at);
        $this->assertTrue(app(PaymentPolicyService::class)->stored($booking)->awaitsConfirmation());

        $this->travelTo('2030-06-02 15:00:00');
        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));

        $booking->refresh();
        $this->assertNotNull($booking->payment_due_at);
        $this->assertSame('2030-06-03 15:00:00', $booking->payment_due_at->toDateTimeString());
    }

    public function test_a_deadline_counted_from_the_request_is_known_straight_away(): void
    {
        $this->policy(['deadline_reference' => PaymentDeadlineReference::Booking, 'deadline_hours' => 12]);

        $booking = $this->book();

        $this->assertSame('2030-06-01 21:00:00', $booking->payment_due_at->toDateTimeString());
    }

    public function test_a_deadline_counted_back_from_arrival_is_known_straight_away(): void
    {
        $this->policy(['deadline_reference' => PaymentDeadlineReference::CheckIn, 'deadline_hours' => 72]);

        $booking = $this->book();

        // Three days before the start of 10 July.
        $this->assertSame('2030-07-07 00:00:00', $booking->payment_due_at->toDateTimeString());
    }

    public function test_confirming_never_moves_a_deadline_the_guest_already_has(): void
    {
        $this->policy(['deadline_reference' => PaymentDeadlineReference::Booking, 'deadline_hours' => 12]);

        $booking = $this->book();
        $given = $booking->payment_due_at->toDateTimeString();

        $this->travelTo('2030-06-05 09:00:00');
        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));

        $this->assertSame($given, $booking->refresh()->payment_due_at->toDateTimeString());
    }

    /* The snapshot ------------------------------------------------------------ */

    public function test_changing_the_policy_does_not_touch_a_booking_already_made(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $booking = $this->book();
        $this->assertSame('1500.00', $booking->amount_due_now);

        // The owner doubles their deposit afterwards.
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 60]);

        $this->assertSame('1500.00', $booking->refresh()->amount_due_now);
        $this->assertSame(30, $booking->payment_deposit_percent);
    }

    /**
     * A guest was shown the terms on the form before they pressed send.
     * Changing them under a request that is still waiting would be a
     * bait and switch, so a pending booking keeps its snapshot too.
     */
    public function test_a_pending_booking_keeps_the_terms_it_was_sent_under(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 20]);

        $booking = $this->book();
        $this->assertSame(BookingStatus::Pending, $booking->status);

        $this->policy(['requirement' => PaymentRequirement::Full]);

        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));

        $booking->refresh();
        $this->assertSame('1000.00', $booking->amount_due_now);
        $this->assertSame(PaymentRequirement::Deposit, $booking->payment_requirement);
    }

    public function test_the_policy_version_is_recorded_on_the_booking(): void
    {
        $policy = $this->policy();

        $this->assertSame($policy->version, $this->book()->payment_policy_version);
    }

    public function test_a_resort_that_has_set_no_policy_uses_the_portal_defaults(): void
    {
        $booking = $this->book();

        // 30% of 5,000, from config('visitdasol.payment_policy').
        $this->assertSame('1500.00', $booking->amount_due_now);
        $this->assertSame(0, $booking->payment_policy_version);
    }

    /* What may actually be charged --------------------------------------------- */

    public function test_only_the_deposit_may_be_charged_not_the_whole_bill(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $booking = $this->book();
        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));

        $payments = app(PaymentService::class);

        $this->assertSame(1500.0, $payments->dueNow($booking->refresh()));
        $this->assertSame(5000.0, $payments->outstanding($booking));
        $this->assertTrue($payments->isPayable($booking));
    }

    public function test_once_the_deposit_is_paid_nothing_more_is_due_before_arrival(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $booking = $this->book();
        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));
        $booking->refresh();

        $booking->payments()->create([
            'payment_reference' => 'VDP-TEST-0001',
            'provider' => 'manual_gcash',
            'amount' => '1500.00',
            'currency' => 'PHP',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $payments = app(PaymentService::class);

        $this->assertSame(0.0, $payments->dueNow($booking));
        $this->assertFalse($payments->isPayable($booking));

        // The balance is still owed, just not before the guest arrives.
        $this->assertSame(3500.0, $payments->outstanding($booking));
    }

    public function test_a_confirmed_booking_is_not_a_paid_booking(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Full]);

        $booking = $this->book();
        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));
        $booking->refresh();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertFalse(app(PaymentService::class)->isSettled($booking));
        $this->assertSame(5000.0, app(PaymentService::class)->dueNow($booking));
    }

    /* Discounts come first ------------------------------------------------------ */

    /**
     * A deposit is a share of what the guest owes, not of the rack rate.
     * Taking 30% of the undiscounted total would quietly claw back part
     * of the discount the guest was promised.
     */
    public function test_a_deposit_is_taken_after_the_child_and_pwd_discounts(): void
    {
        $this->room->forceFill([
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 20,
        ])->save();

        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 50]);

        // 5,000 over two guests is 2,500 each; 20% off one share is 500.
        $booking = $this->book(['adults' => 2, 'pwd_guests' => 1]);

        $this->assertSame('4500.00', $booking->total_amount);
        $this->assertSame('2250.00', $booking->amount_due_now);
    }

    /* The owner's settings page --------------------------------------------------- */

    public function test_an_owner_can_set_and_reset_their_terms(): void
    {
        $this->actingAs($this->owner)->get(route('owner.payment-policy.edit'))
            ->assertOk()
            ->assertSee('Payment terms')
            ->assertSee('standard terms');

        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'deposit',
            'deposit_percent' => 45,
            'deadline_hours' => 36,
            'deadline_reference' => 'booking',
            'last_minute_rule' => 'deposit',
            'last_minute_hours' => 12,
            'allow_on_site' => '1',
        ])->assertRedirect(route('owner.payment-policy.edit'));

        $policy = $this->resort->refresh()->paymentPolicy;
        $this->assertSame(45, $policy->deposit_percent);
        $this->assertSame(36, $policy->deadline_hours);
        $this->assertSame(1, $policy->version);
        $this->assertTrue($policy->allow_on_site);

        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.payment_policy_updated']);

        $this->actingAs($this->owner)->delete(route('owner.payment-policy.destroy'))
            ->assertRedirect(route('owner.payment-policy.edit'));

        $this->assertNull($this->resort->refresh()->paymentPolicy);
    }

    public function test_each_save_is_a_new_version(): void
    {
        foreach ([20, 25, 30] as $percent) {
            $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
                'requirement' => 'deposit',
                'deposit_percent' => $percent,
                'deadline_hours' => 24,
                'deadline_reference' => 'confirmation',
                'last_minute_rule' => 'full',
                'last_minute_hours' => 48,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $this->resort->refresh()->paymentPolicy->version);
    }

    public function test_a_deposit_needs_a_percentage_but_other_choices_do_not(): void
    {
        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'deposit',
            'deposit_percent' => '',
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
        ])->assertSessionHasErrors('deposit_percent');

        // Taking nothing up front needs no percentage at all.
        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'none',
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
        ])->assertSessionHasNoErrors();
    }

    public function test_invalid_terms_are_refused(): void
    {
        $cases = [
            ['requirement' => 'sometimes'],
            ['deposit_percent' => 0],
            ['deposit_percent' => 101],
            ['deadline_hours' => 0],
            ['deadline_reference' => 'whenever'],
            ['last_minute_rule' => 'maybe'],
            ['last_minute_hours' => 721],
        ];

        foreach ($cases as $override) {
            $this->actingAs($this->owner)
                ->put(route('owner.payment-policy.update'), array_merge([
                    'requirement' => 'deposit',
                    'deposit_percent' => 30,
                    'deadline_hours' => 24,
                    'deadline_reference' => 'confirmation',
                    'last_minute_rule' => 'full',
                    'last_minute_hours' => 48,
                ], $override))
                ->assertSessionHasErrors(array_key_first($override));
        }
    }

    /* Who may change them ----------------------------------------------------------- */

    public function test_an_owner_cannot_reach_another_resorts_terms(): void
    {
        $other = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($other, 'owner')->active()->create();

        $this->actingAs($other)->put(route('owner.payment-policy.update'), [
            'requirement' => 'full',
            'deadline_hours' => 1,
            'deadline_reference' => 'booking',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 1,
        ])->assertRedirect();

        // Their own resort changed; this one did not.
        $this->assertNull($this->resort->refresh()->paymentPolicy);
        $this->assertNotNull($otherResort->refresh()->paymentPolicy);
    }

    public function test_a_tourist_cannot_touch_payment_terms(): void
    {
        $this->actingAs($this->tourist)->get(route('owner.payment-policy.edit'))->assertForbidden();
        $this->actingAs($this->tourist)->put(route('owner.payment-policy.update'), [])->assertForbidden();
    }

    /**
     * What a resort charges is its own commercial decision. The office's
     * interest is visitor numbers, not a resort's takings.
     */
    public function test_the_tourism_office_cannot_set_a_resorts_terms(): void
    {
        $mto = User::factory()->superAdmin()->create();

        $this->actingAs($mto)->get(route('owner.payment-policy.edit'))->assertForbidden();
        $this->actingAs($mto)->put(route('owner.payment-policy.update'), [])->assertForbidden();
    }

    /* What the guest is shown --------------------------------------------------------- */

    public function test_the_booking_form_explains_the_terms_before_the_guest_commits(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $this->room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-15',
            ]))
            ->assertOk()
            ->assertSee('How this resort takes payment')
            ->assertSee('30% deposit')
            ->assertSee('within 24 hours of the resort confirming');
    }

    public function test_the_reservation_page_shows_the_agreed_terms(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $booking = $this->book();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('What you agreed to pay')
            ->assertSee('Deposit to pay (30%)')
            ->assertSee('₱1,500.00')
            ->assertSee('₱3,500.00');
    }

    public function test_no_payment_button_appears_when_nothing_is_due(): void
    {
        $this->policy(['requirement' => PaymentRequirement::None]);

        $booking = $this->book();
        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking));

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking->refresh()))
            ->assertOk()
            ->assertDontSee('Pay online')
            ->assertSee('Nothing to pay now');
    }

    public function test_the_owner_sees_what_the_guest_agreed_to(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $booking = $this->book();

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Agreed payment terms')
            ->assertSee('₱1,500.00');
    }
}
