<?php

namespace Tests\Feature\Owner;

use App\Enums\PaymentPreference;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two payment methods a resort accepts, switched on the same card.
 *
 * TWO SWITCHES, ONE STORED ANSWER EACH, AND NEITHER READS THE OTHER.
 * GCash is resort_manual_payment_accounts.active; paying on arrival is
 * resort_payment_policies.allow_on_site — the column that already held
 * it, so the control moved pages and the data did not.
 */
class PaymentMethodTogglesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
    }

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'account_name' => 'Maria Dela Cruz',
            'gcash_number' => '09171234567',
            'instructions' => null,
        ], $overrides);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->owner)
            ->put(route('owner.manual-gcash.update'), $this->settings($overrides));
    }

    private function page(): string
    {
        return $this->actingAs($this->owner)
            ->get(route('owner.manual-gcash.edit'))->assertOk()->getContent();
    }

    private function onSite(): bool
    {
        return (bool) $this->resort->fresh()->paymentTerms()->allow_on_site;
    }

    private function gcashOn(): bool
    {
        return (bool) $this->resort->manualAccount()->first()?->active;
    }

    /* The card ---------------------------------------------------------------- */

    public function test_both_switches_sit_in_the_payment_availability_card(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Payment Availability', $html);
        $this->assertStringContainsString('Accept manual GCash payments', $html);
        $this->assertStringContainsString('Accept Pay at Resort bookings', $html);

        $this->assertStringContainsString(
            'When enabled, guests can choose to pay directly at your '
            . "resort, subject to your payment terms.",
            preg_replace('/\s+/', ' ', $html)
        );

        // One card, not a second settings page.
        $this->assertSame(1, substr_count($html, 'Payment Availability'));

        // The existing GCash form is untouched around it.
        foreach (['account_name', 'gcash_number', 'instructions', 'qr_image'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }

        $this->assertStringContainsString('Save Payment Settings', $html);
        $this->assertStringContainsString('Cancel', $html);
    }

    /**
     * An unticked switch sends nothing at all, so without a hidden 0 the
     * server would read "off" as "leave it alone" and the owner could
     * never turn either one back off.
     */
    public function test_each_switch_posts_a_zero_when_it_is_off(): void
    {
        $html = $this->page();

        foreach (['active', 'allow_on_site'] as $name) {
            $this->assertMatchesRegularExpression(
                '/<input type="hidden" name="'.$name.'" value="0">/',
                $html
            );
        }
    }

    /* They are independent ------------------------------------------------------ */

    /** @return array<string, array{bool, bool}> */
    public static function combinations(): array
    {
        return [
            'both on' => [true, true],
            'gcash only' => [true, false],
            'on-site only' => [false, true],
            'neither' => [false, false],
        ];
    }

    /**
     * All four combinations are reachable and stored. Neither switch may
     * drag the other with it.
     */
    #[DataProvider('combinations')]
    public function test_every_combination_of_the_two_is_storable(bool $gcash, bool $onSite): void
    {
        $this->save([
            'active' => $gcash ? '1' : '0',
            'allow_on_site' => $onSite ? '1' : '0',
        ])->assertRedirect(route('owner.manual-gcash.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame($gcash, $this->gcashOn());
        $this->assertSame($onSite, $this->onSite());
    }

    /** Turning one off leaves the other exactly as it was. */
    public function test_switching_gcash_off_does_not_withdraw_payment_on_arrival(): void
    {
        $this->save(['active' => '1', 'allow_on_site' => '1']);
        $this->save(['active' => '0', 'allow_on_site' => '1']);

        $this->assertFalse($this->gcashOn());
        $this->assertTrue($this->onSite());
    }

    /* What it writes ------------------------------------------------------------- */

    /**
     * NO SECOND COLUMN. The answer goes to the one the booking form
     * already reads and every booking already snapshots.
     */
    public function test_it_writes_the_existing_payment_policy_column(): void
    {
        $this->save(['allow_on_site' => '0']);

        $this->assertDatabaseHas('resort_payment_policies', [
            'resort_id' => $this->resort->id,
            'allow_on_site' => false,
        ]);
    }

    /**
     * A resort that has never opened the terms page runs on the portal's
     * defaults. Saving GCash details must not quietly turn those into
     * terms of the owner's own — so an answer that changes nothing
     * writes nothing.
     */
    public function test_an_unchanged_answer_does_not_invent_a_policy(): void
    {
        $this->assertNull($this->resort->paymentPolicy()->first());

        // true is the portal's default, so this is not a change.
        $this->save(['allow_on_site' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($this->resort->fresh()->paymentPolicy()->first());
        $this->assertTrue($this->onSite());
    }

    /**
     * A real change is a new edition of the terms, because bookings
     * snapshot this column and have to say which edition they were made
     * under.
     */
    public function test_a_real_change_bumps_the_version_and_is_logged(): void
    {
        $this->save(['allow_on_site' => '0']);

        $policy = $this->resort->fresh()->paymentPolicy()->firstOrFail();

        $this->assertFalse($policy->allow_on_site);
        $this->assertSame(1, $policy->version);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'resort.payment_policy_updated',
        ]);

        // And again, the other way.
        $this->save(['allow_on_site' => '1']);
        $this->assertSame(2, $this->resort->fresh()->paymentPolicy->version);
    }

    /* The booking form ------------------------------------------------------------ */

    private function room(): Room
    {
        return Room::factory()->for($this->resort)->create([
            'capacity' => 4, 'total_rooms' => 2, 'price_per_night' => 1500,
        ]);
    }

    /**
     * The switch reaches the guest.
     *
     * Asked through PaymentPreference, which is the one gate: the form
     * draws what it returns, StoreBookingRequest accepts what it
     * returns, and BookingService refuses anything else. Checking it
     * here checks all three.
     */
    public function test_payment_on_arrival_is_offered_only_while_the_switch_is_on(): void
    {
        $this->resort->paymentPolicy()->create([
            'requirement' => 'none',
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'on_site',
            'last_minute_hours' => 48,
            'allow_on_site' => true,
        ]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $this->resort->fresh()->paymentTerms(),
            '3000.00',
            now()->addDays(30)->toImmutable(),
        );

        $this->assertTrue(PaymentPreference::OnArrival->isAvailable(null, $terms));

        $this->save(['allow_on_site' => '0']);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $this->resort->fresh()->paymentTerms(),
            '3000.00',
            now()->addDays(30)->toImmutable(),
        );

        $this->assertFalse(PaymentPreference::OnArrival->isAvailable(null, $terms));
    }

    /** Paying at the resort asks for no reference number and no receipt. */
    public function test_paying_on_arrival_needs_no_proof_upload(): void
    {
        $this->resort->paymentPolicy()->create([
            'requirement' => 'none',
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'on_site',
            'last_minute_hours' => 48,
            'allow_on_site' => true,
        ]);

        $room = $this->room();
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'adults' => 2, 'children' => 0, 'pwd_guests' => 0,
            'rooms_booked' => 1, 'guests_male' => 1, 'guests_female' => 1,
            'origin' => 'pangasinan',
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '09171234567',
            'id_document' => $this->validId(),
            'payment_preference' => PaymentPreference::OnArrival->value,
        ])->assertSessionHasNoErrors();

        $booking = \App\Models\Booking::query()->latest('id')->firstOrFail();

        $this->assertSame(PaymentPreference::OnArrival, $booking->payment_preference);

        // Nothing up front; the whole bill waits at the resort.
        $this->assertSame('0.00', $booking->amount_due_now);
        $this->assertSame('3000.00', $booking->total_amount);

        // So no manual payment was opened and no proof is asked for.
        $this->assertSame(0, $booking->payments()->count());
        $this->assertFalse(app(\App\Services\Payments\ManualPaymentService::class)
            ->isAvailable($booking));
    }

    /* The terms page no longer competes for the same value --------------------------- */

    /**
     * ONE VALUE, ONE CONTROL.
     *
     * The terms page carried this checkbox too. With the control moved,
     * the rule had to go with it: policyAttributes() writes everything
     * safe() holds, so a leftover rule plus a form that no longer sends
     * the field would have read the absent checkbox as "off" and
     * withdrawn payment on arrival on every save of the terms.
     */
    public function test_saving_the_terms_leaves_payment_on_arrival_alone(): void
    {
        $this->save(['allow_on_site' => '1']);
        $this->assertTrue($this->onSite());

        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'deposit',
            'deposit_percent' => 40,
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->onSite());
        $this->assertSame(40, $this->resort->fresh()->paymentPolicy->deposit_percent);

        // And the same when it was off.
        $this->save(['allow_on_site' => '0']);

        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'none',
            'deadline_hours' => 24,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->onSite());
    }

    /** The terms page says where the switch went rather than dropping it silently. */
    public function test_the_terms_page_points_at_the_switch(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('owner.payment-policy.edit'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="allow_on_site"', $html);
        $this->assertStringContainsString(route('owner.manual-gcash.edit'), $html);
    }

    /* Who may change it ---------------------------------------------------------------- */

    public function test_another_owner_cannot_reach_these_switches(): void
    {
        $other = User::factory()->resortOwner()->create();
        Resort::factory()->for($other, 'owner')->active()->create();

        $this->actingAs($other)
            ->put(route('owner.manual-gcash.update'), $this->settings(['allow_on_site' => '0']))
            ->assertSessionHasNoErrors();

        // Their own resort changed; this one did not.
        $this->assertTrue($this->onSite());
    }
}
