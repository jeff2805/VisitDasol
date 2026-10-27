<?php

namespace Tests\Feature\EnvironmentalFee;

use App\Enums\CollectionStatus;
use App\Enums\FeeScope;
use App\Models\Booking;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeeCollector;
use App\Models\EnvironmentalFeePolicy;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\EnvironmentalFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The municipal environmental fee.
 *
 * Two things are being protected here above everything else:
 *
 *   1. NOTHING IS COLLECTED BY DEFAULT. The module ships off, with no
 *      rate, and must refuse every collection until an authorised
 *      officer configures and enables one.
 *   2. IT NEVER TOUCHES RESORT MONEY. A municipal fee is not room
 *      revenue, and no amount of it may reach a booking total.
 */
class EnvironmentalFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    private User $official;

    private User $owner;

    private User $tourist;

    private EnvironmentalFeeCheckpoint $checkpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->mto = User::factory()->superAdmin()->create();
        $this->official = User::factory()->create(['role' => 'barangay_official', 'barangay' => 'Tambobong']);
        $this->owner = User::factory()->resortOwner()->create();
        $this->tourist = User::factory()->create();

        $this->checkpoint = $this->checkpoint('Tambobong beach road');
    }

    private function checkpoint(string $name, bool $active = true): EnvironmentalFeeCheckpoint
    {
        $checkpoint = new EnvironmentalFeeCheckpoint([
            'name' => $name,
            'barangay' => 'Tambobong',
            'latitude' => 15.9327,
            'longitude' => 119.7823,
        ]);

        $checkpoint->createdBy()->associate($this->mto);
        $checkpoint->active = $active;
        $checkpoint->save();

        return $checkpoint;
    }

    private function assign(User $user, ?EnvironmentalFeeCheckpoint $checkpoint = null): void
    {
        EnvironmentalFeeCollector::query()->create([
            'checkpoint_id' => ($checkpoint ?? $this->checkpoint)->id,
            'user_id' => $user->id,
            'assigned_by' => $this->mto->id,
            'active' => true,
        ]);
    }

    private function policy(array $attributes = [], bool $enabled = true): EnvironmentalFeePolicy
    {
        $policy = new EnvironmentalFeePolicy($attributes + [
            'amount' => '50.00',
            'scope' => FeeScope::PerVisitor,
            'effective_from' => '2030-01-01',
        ]);

        $policy->createdBy()->associate($this->mto);

        if ($enabled) {
            $policy->enabled = true;
            $policy->enabled_by = $this->mto->id;
            $policy->enabled_at = now();
        }

        $policy->save();

        return $policy->refresh();
    }

    private function collectionPayload(array $overrides = []): array
    {
        return array_merge([
            'checkpoint_id' => $this->checkpoint->id,
            'receipt_number' => '004512',
            'quantity' => 2,
        ], $overrides);
    }

    /* 1. The initial state ---------------------------------------------- */

    public function test_the_module_starts_switched_off_with_no_rate(): void
    {
        $this->assertSame(0, EnvironmentalFeePolicy::query()->count());

        $fees = app(EnvironmentalFeeService::class);

        $this->assertNull($fees->activePolicy());
        $this->assertFalse($fees->isCollectable());
        $this->assertStringContainsString('No environmental fee has been configured', $fees->blockedReason());
    }

    public function test_a_new_policy_is_unpriced_and_off_until_configured(): void
    {
        $policy = new EnvironmentalFeePolicy;

        // Null, not zero. "Nobody set a rate" and "the rate is nothing"
        // are different facts and a municipal system must not blur them.
        $this->assertNull($policy->amount);
        $this->assertFalse($policy->enabled);
    }

    public function test_saving_a_configuration_does_not_start_collection(): void
    {
        $this->actingAs($this->mto)->post(route('admin.environmental-fee.store'), [
            'amount' => '50.00',
            'scope' => 'per_visitor',
            'effective_from' => '2030-01-01',
            'authority_reference' => 'Municipal Ordinance No. 2030-01',
        ])->assertRedirect(route('admin.environmental-fee.index'));

        $policy = EnvironmentalFeePolicy::sole();

        $this->assertFalse($policy->enabled);
        $this->assertFalse(app(EnvironmentalFeeService::class)->isCollectable());

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.policy_created']);
    }

    public function test_enabling_is_its_own_act_and_records_who_did_it(): void
    {
        $policy = $this->policy(enabled: false);

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.toggle', $policy))
            ->assertRedirect();

        $policy->refresh();

        $this->assertTrue($policy->enabled);
        $this->assertSame($this->mto->id, $policy->enabled_by);
        $this->assertNotNull($policy->enabled_at);

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.enabled']);
    }

    /* 2. Nothing collected while unconfigured ----------------------------- */

    public function test_nothing_can_be_collected_while_the_module_is_off(): void
    {
        $this->policy(enabled: false);
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    public function test_nothing_can_be_collected_before_the_effective_date(): void
    {
        $this->policy(['effective_from' => '2030-12-01']);
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    public function test_nothing_can_be_collected_after_the_end_date(): void
    {
        $this->policy(['effective_from' => '2030-01-01', 'effective_until' => '2030-05-31']);
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    public function test_nothing_can_be_collected_at_a_closed_checkpoint(): void
    {
        $this->policy();
        $closed = $this->checkpoint('Closed gate', active: false);
        $this->assign($this->official, $closed);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload([
                'checkpoint_id' => $closed->id,
            ]))
            ->assertForbidden();

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    /* 3. Who may collect --------------------------------------------------- */

    public function test_an_unassigned_person_cannot_collect(): void
    {
        $this->policy();

        // A barangay official with no assignment anywhere.
        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload())
            ->assertForbidden();

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    /**
     * Authority is given one gate at a time. Somebody trusted at
     * Tambobong is not thereby trusted everywhere in Dasol.
     */
    public function test_an_assignment_at_one_checkpoint_does_not_cover_another(): void
    {
        $this->policy();
        $other = $this->checkpoint('Malacapas road');
        $this->assign($this->official, $this->checkpoint);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload([
                'checkpoint_id' => $other->id,
            ]))
            ->assertForbidden();

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    public function test_a_withdrawn_collector_can_no_longer_collect(): void
    {
        $this->policy();
        $this->assign($this->official);

        EnvironmentalFeeCollector::query()
            ->where('user_id', $this->official->id)
            ->update(['active' => false]);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload())
            ->assertForbidden();
    }

    public function test_a_resort_owner_cannot_reach_the_module_at_all(): void
    {
        $this->policy();

        $this->actingAs($this->owner)->get(route('environmental-fee.index'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('environmental-fee.store'), $this->collectionPayload())->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.environmental-fee.index'))->assertForbidden();
    }

    public function test_a_tourist_cannot_reach_the_module_at_all(): void
    {
        $this->actingAs($this->tourist)->get(route('environmental-fee.index'))->assertForbidden();
        $this->actingAs($this->tourist)->get(route('admin.environmental-fee.index'))->assertForbidden();
    }

    /**
     * A resort with an interest in visitor numbers must never be able to
     * set, or switch off, the charge on those visitors.
     */
    public function test_only_the_office_may_configure_the_fee(): void
    {
        $policy = $this->policy();

        foreach ([$this->owner, $this->tourist, $this->official] as $user) {
            $this->actingAs($user)
                ->post(route('admin.environmental-fee.store'), [
                    'amount' => '1.00', 'scope' => 'per_visitor', 'effective_from' => '2030-01-01',
                ])->assertForbidden();

            $this->actingAs($user)
                ->patch(route('admin.environmental-fee.toggle', $policy))
                ->assertForbidden();
        }

        $this->assertTrue($policy->refresh()->enabled);
    }

    /* 4. Recording ---------------------------------------------------------- */

    public function test_a_collection_is_recorded_with_the_amount_worked_out_on_the_server(): void
    {
        $this->policy(['amount' => '50.00']);
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload([
                'quantity' => 3,
                // A figure sent with the form must be ignored entirely.
                'amount' => '1.00',
                'rate' => '1.00',
            ]))
            ->assertRedirect(route('environmental-fee.index'))
            ->assertSessionHas('success');

        $collection = EnvironmentalFeeCollection::sole();

        $this->assertSame('150.00', $collection->amount);
        $this->assertSame('50.00', $collection->rate);
        $this->assertSame(3, $collection->quantity);
        $this->assertSame(CollectionStatus::Recorded, $collection->status);
        $this->assertSame('cash', $collection->payment_method);
        $this->assertStringStartsWith('VDE-', $collection->reference);

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.collected']);
    }

    public function test_a_receipt_number_cannot_be_used_twice(): void
    {
        $this->policy();
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload(['receipt_number' => 'R-1']))
            ->assertSessionHas('success');

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload(['receipt_number' => 'R-1']))
            ->assertSessionHas('error');

        $this->assertSame(1, EnvironmentalFeeCollection::query()->count());
    }

    /**
     * The rate is copied onto the receipt. An office that changes the
     * fee next month must not change what last month's receipts say.
     */
    public function test_changing_the_fee_does_not_change_what_past_receipts_say(): void
    {
        $policy = $this->policy(['amount' => '50.00']);
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload(['quantity' => 1]));

        $collection = EnvironmentalFeeCollection::sole();
        $this->assertSame('50.00', $collection->amount);

        $policy->forceFill(['amount' => '200.00'])->save();

        $this->assertSame('50.00', $collection->refresh()->amount);
        $this->assertSame('50.00', $collection->rate);
    }

    /* 5. Voiding ------------------------------------------------------------ */

    public function test_the_office_can_void_a_collection_with_a_reason(): void
    {
        $this->policy();
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload());

        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)
            ->patch(route('environmental-fee.void', $collection), [
                'void_reason' => 'Receipt written twice for the same vehicle.',
            ])
            ->assertRedirect();

        $collection->refresh();

        // Marked, never deleted.
        $this->assertSame(CollectionStatus::Voided, $collection->status);
        $this->assertSame($this->mto->id, $collection->voided_by);
        $this->assertNotNull($collection->void_reason);
        $this->assertSame(1, EnvironmentalFeeCollection::query()->count());

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.voided']);
    }

    /**
     * A collector able to cancel their own receipts could take money at
     * a gate and erase the only record that they did.
     */
    public function test_a_collector_cannot_void_their_own_collection(): void
    {
        $this->policy();
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload());

        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->official)
            ->patch(route('environmental-fee.void', $collection), ['void_reason' => 'Changed my mind.'])
            ->assertForbidden();

        $this->assertSame(CollectionStatus::Recorded, $collection->refresh()->status);
    }

    /**
     * Voiding must not rewrite when the money was taken.
     *
     * It did. MySQL gives the first NOT NULL TIMESTAMP column in a
     * table an implicit ON UPDATE CURRENT_TIMESTAMP, so cancelling a
     * receipt silently redated the collection to the moment it was
     * cancelled — a municipal record quietly saying the wrong day.
     */
    public function test_voiding_does_not_change_when_the_fee_was_collected(): void
    {
        $this->policy();
        $this->assign($this->official);
        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload());

        $collection = EnvironmentalFeeCollection::sole();
        $collectedAt = $collection->collected_at->toDateTimeString();

        // Well after the collection, as a real cancellation would be.
        $this->travelTo('2030-06-05 16:00:00');

        $this->actingAs($this->mto)->patch(route('environmental-fee.void', $collection), [
            'void_reason' => 'Issued against the wrong vehicle.',
        ]);

        $collection->refresh();

        $this->assertSame($collectedAt, $collection->collected_at->toDateTimeString());
        $this->assertSame('2030-06-05 16:00:00', $collection->voided_at->toDateTimeString());
    }

    public function test_voiding_needs_a_reason(): void
    {
        $this->policy();
        $this->assign($this->official);
        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload());

        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)
            ->patch(route('environmental-fee.void', $collection), ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->assertSame(CollectionStatus::Recorded, $collection->refresh()->status);
    }

    public function test_a_voided_collection_stops_counting(): void
    {
        $this->policy();
        $this->assign($this->official);

        foreach (['R-1', 'R-2'] as $receipt) {
            $this->actingAs($this->official)
                ->post(route('environmental-fee.store'), $this->collectionPayload([
                    'receipt_number' => $receipt, 'quantity' => 1,
                ]));
        }

        $first = EnvironmentalFeeCollection::query()->where('receipt_number', 'R-1')->sole();

        $this->actingAs($this->mto)->patch(route('environmental-fee.void', $first), [
            'void_reason' => 'Issued in error.',
        ]);

        $this->assertSame('50.00', (string) EnvironmentalFeeCollection::query()->counted()->sum('amount'));
    }

    /* 6. Ambiguous policies -------------------------------------------------- */

    public function test_two_enabled_policies_cannot_cover_the_same_day(): void
    {
        $this->policy(['effective_from' => '2030-01-01']);

        $this->actingAs($this->mto)->post(route('admin.environmental-fee.store'), [
            'amount' => '75.00',
            'scope' => 'per_visitor',
            'effective_from' => '2030-03-01',
        ])->assertSessionHasErrors('effective_from');

        $this->assertSame(1, EnvironmentalFeePolicy::query()->count());
    }

    public function test_a_policy_starting_after_another_ends_is_allowed(): void
    {
        $this->policy(['effective_from' => '2030-01-01', 'effective_until' => '2030-06-30']);

        $this->actingAs($this->mto)->post(route('admin.environmental-fee.store'), [
            'amount' => '75.00',
            'scope' => 'per_visitor',
            'effective_from' => '2030-07-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, EnvironmentalFeePolicy::query()->count());
    }

    public function test_a_fee_of_nothing_is_refused(): void
    {
        $this->actingAs($this->mto)->post(route('admin.environmental-fee.store'), [
            'amount' => '0',
            'scope' => 'per_visitor',
            'effective_from' => '2030-01-01',
        ])->assertSessionHasErrors('amount');
    }

    /* 7. Separation from resort money ----------------------------------------- */

    /**
     * The whole reason this module exists separately. A municipal fee is
     * not room revenue, and nothing it does may reach a booking.
     */
    public function test_collecting_a_fee_changes_no_booking_figure(): void
    {
        $resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 4]);

        $booking = Booking::factory()
            ->for($room)->for($resort)->for($this->tourist, 'tourist')
            ->forStay('2030-07-10', '2030-07-12')
            ->confirmed()
            ->create();

        $before = $booking->only([
            'total_amount', 'subtotal_amount', 'amount_due_now',
            'discount_total', 'unit_price', 'payment_due_at',
        ]);

        $this->policy();
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->collectionPayload())
            ->assertSessionHas('success');

        $this->assertSame($before, $booking->refresh()->only(array_keys($before)));
    }

    /** No column, no relation, no path between the two. */
    public function test_a_collection_carries_no_link_to_a_booking_or_a_resort(): void
    {
        $this->policy();
        $this->assign($this->official);
        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload());

        $columns = array_keys(EnvironmentalFeeCollection::sole()->getAttributes());

        foreach (['booking_id', 'resort_id', 'room_id', 'payment_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    /* 8. Reports ---------------------------------------------------------------- */

    public function test_the_report_separates_recorded_from_voided(): void
    {
        $this->policy();
        $this->assign($this->official);

        foreach (['R-1', 'R-2', 'R-3'] as $receipt) {
            $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload([
                'receipt_number' => $receipt, 'quantity' => 1,
            ]));
        }

        $voided = EnvironmentalFeeCollection::query()->where('receipt_number', 'R-3')->sole();
        $this->actingAs($this->mto)->patch(route('environmental-fee.void', $voided), [
            'void_reason' => 'Duplicate.',
        ]);

        $response = $this->actingAs($this->mto)->get(route('admin.environmental-fee.reports', [
            'from' => '2030-06-01', 'to' => '2030-06-30',
        ]))->assertOk();

        $this->assertSame('100.00', $response->viewData('total'));
        $this->assertSame(2, $response->viewData('count'));
        $this->assertSame('50.00', $response->viewData('voidedTotal'));

        // The figure must never be presented as money in hand.
        $response->assertSee('not remittances', false);
    }

    public function test_a_receipt_can_be_looked_up_by_its_number(): void
    {
        $this->policy();
        $this->assign($this->official);
        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload([
            'receipt_number' => 'R-4242',
        ]));

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.lookup', ['q' => 'R-4242']))
            ->assertOk()
            ->assertSee('R-4242');
    }

    public function test_exporting_is_recorded_in_the_audit_trail(): void
    {
        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.export', ['from' => '2030-06-01', 'to' => '2030-06-30']))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.report_exported']);
    }

    public function test_only_the_office_may_read_the_reports(): void
    {
        foreach ([$this->owner, $this->tourist, $this->official] as $user) {
            $this->actingAs($user)->get(route('admin.environmental-fee.reports'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.environmental-fee.export'))->assertForbidden();
        }
    }

    /* 9. Checkpoints -------------------------------------------------------------- */

    public function test_a_checkpoint_outside_dasol_is_refused(): void
    {
        $this->actingAs($this->mto)->post(route('admin.environmental-fee.checkpoints.store'), [
            'name' => 'Somewhere else',
            'barangay' => 'Tambobong',
            'latitude' => 14.5,
            'longitude' => 121.0,
        ])->assertSessionHasErrors(['latitude', 'longitude']);
    }

    public function test_a_checkpoint_in_a_barangay_dasol_does_not_have_is_refused(): void
    {
        $this->actingAs($this->mto)->post(route('admin.environmental-fee.checkpoints.store'), [
            'name' => 'Nowhere gate',
            'barangay' => 'Bamban',
            'latitude' => 15.93,
            'longitude' => 119.78,
        ])->assertSessionHasErrors('barangay');
    }

    public function test_closing_a_checkpoint_keeps_its_collections(): void
    {
        $this->policy();
        $this->assign($this->official);
        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload());

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.checkpoints.status', $this->checkpoint))
            ->assertRedirect();

        $this->assertFalse($this->checkpoint->refresh()->active);
        $this->assertSame(1, EnvironmentalFeeCollection::query()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.checkpoint_deactivated']);
    }

    public function test_assigning_and_withdrawing_a_collector_is_logged(): void
    {
        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.collectors.assign', $this->checkpoint), [
                'user_id' => $this->official->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.collector_assigned']);

        $assignment = EnvironmentalFeeCollector::sole();

        $this->actingAs($this->mto)
            ->delete(route('admin.environmental-fee.collectors.withdraw', [$this->checkpoint, $assignment]))
            ->assertRedirect();

        $this->assertFalse($assignment->refresh()->active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.collector_withdrawn']);
    }

    public function test_a_tourist_cannot_be_made_a_collector(): void
    {
        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.collectors.assign', $this->checkpoint), [
                'user_id' => $this->tourist->id,
            ])->assertSessionHasErrors('user_id');

        $this->assertSame(0, EnvironmentalFeeCollector::query()->count());
    }

    /* 10. What the tourist is told ---------------------------------------------------- */

    /**
     * A guest driving in meets the checkpoint whether or not the portal
     * mentions it. Telling them beforehand is the point.
     */
    public function test_a_guest_is_told_about_the_fee_before_they_book(): void
    {
        $this->policy(['amount' => '50.00']);

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 4]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $room->id, 'check_in' => '2030-07-10', 'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->assertSee('Dasol\'s environmental fee', false)
            ->assertSee('₱50.00')
            // The whole reason it is shown separately.
            ->assertSee('separate from what you pay the resort');
    }

    public function test_nothing_is_said_while_no_fee_is_in_force(): void
    {
        $this->policy(enabled: false);

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 4]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $room->id, 'check_in' => '2030-07-10', 'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->assertDontSee('Dasol\'s environmental fee', false);
    }

    /**
     * The line this module must never cross. Telling a guest the fee
     * exists must not add a centavo to what they owe the resort.
     */
    public function test_telling_the_guest_adds_nothing_to_the_booking(): void
    {
        $this->policy(['amount' => '50.00']);

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 4]);

        $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'adults' => 2, 'children' => 0, 'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1, 'guests_female' => 1,
            'origin' => 'pangasinan',
        ])->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->sole();

        // 1,000 x 2 nights. Not 2,100, not 2,000 plus anything.
        $this->assertSame('2000.00', $booking->total_amount);
        $this->assertSame('2000.00', $booking->subtotal_amount);
        $this->assertSame('0.00', $booking->discount_total);
    }

    public function test_the_reservation_page_reminds_the_guest(): void
    {
        $this->policy(['amount' => '50.00']);

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 4]);

        $booking = Booking::factory()
            ->for($room)->for($resort)->for($this->tourist, 'tourist')
            ->forStay('2030-07-10', '2030-07-12')
            ->confirmed()
            ->create();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Dasol\'s environmental fee', false)
            ->assertSee('Bring cash');
    }

    /**
     * The rate is shown; a total is not. Who is exempt comes from an
     * ordinance this project does not model, and a confident wrong
     * figure is worse than an honest rate.
     */
    public function test_the_notice_quotes_a_rate_and_not_a_total(): void
    {
        $this->policy(['amount' => '50.00']);

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 6]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $room->id, 'check_in' => '2030-07-10',
                'check_out' => '2030-07-12', 'guests' => 4,
            ]))
            ->assertOk()
            ->assertSee('₱50.00')
            // 4 guests x 50 would be 200. It must not be offered as fact.
            ->assertDontSee('₱200.00')
            ->assertSee('checkpoint works out what you owe');
    }

    public function test_the_notice_names_where_the_fee_is_collected(): void
    {
        $this->policy();

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 1000, 'capacity' => 4]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $room->id, 'check_in' => '2030-07-10', 'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->assertSee('Brgy. Tambobong');
    }

    /* 11. The map ------------------------------------------------------------------- */

    public function test_checkpoints_stay_off_the_public_map_while_the_fee_is_off(): void
    {
        $this->policy(enabled: false);

        $response = $this->get(route('map'))->assertOk();

        $this->assertCount(0, $response->viewData('checkpoints'));
        $response->assertDontSee('Tambobong beach road');
    }

    public function test_checkpoints_appear_once_a_fee_is_actually_in_force(): void
    {
        $this->policy();

        $response = $this->get(route('map'))->assertOk();

        $this->assertCount(1, $response->viewData('checkpoints'));
        $response->assertSee('Environmental fee checkpoints');
    }

    /** The module must not have cost the map anything it already had. */
    public function test_the_existing_map_layers_still_work(): void
    {
        $this->policy();

        $resort = Resort::factory()->active()->create();
        Room::factory()->for($resort)->create();

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('data-points', false);
        $response->assertSee('data-barangays', false);
        $response->assertSee('data-satellite-url', false);
        $this->assertNotEmpty($response->viewData('barangays'));
        $this->assertNotEmpty($response->viewData('resorts'));
    }

    /* 11. The office's own screens ---------------------------------------------------- */

    public function test_the_office_sees_the_module_is_off_before_anything_is_set_up(): void
    {
        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.index'))
            ->assertOk()
            ->assertSee('Nothing is being collected')
            ->assertSee('No environmental fee has been configured');
    }

    public function test_a_collector_with_no_assignment_is_told_why_the_form_is_missing(): void
    {
        $this->policy();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.index'))
            ->assertOk()
            ->assertSee('not assigned to a checkpoint');
    }

    public function test_a_collector_sees_the_rate_in_force(): void
    {
        $this->policy(['amount' => '50.00']);
        $this->assign($this->official);

        $this->actingAs($this->official)
            ->get(route('environmental-fee.index'))
            ->assertOk()
            ->assertSee('₱50.00')
            ->assertSee('Record a collection')
            // Never presented as an electronic payment.
            ->assertSee('electronic payment');
    }

    public function test_a_collector_sees_only_their_own_receipts(): void
    {
        $this->policy();
        $other = User::factory()->create(['role' => 'barangay_official', 'barangay' => 'Uli']);

        $this->assign($this->official);
        $this->assign($other);

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->collectionPayload([
            'receipt_number' => 'MINE',
        ]));
        $this->actingAs($other)->post(route('environmental-fee.store'), $this->collectionPayload([
            'receipt_number' => 'THEIRS',
        ]));

        /*
         * Two people, two browsers, two sessions. The test harness
         * shares one, so the other collector's "Recorded THEIRS"
         * confirmation would otherwise still be flashed on this
         * person's next page and look like a leak that cannot happen.
         */
        $this->flushSession();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.index'))
            ->assertOk()
            ->assertSee('MINE')
            ->assertDontSee('THEIRS');

        $theirs = EnvironmentalFeeCollection::query()->where('receipt_number', 'THEIRS')->sole();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.show', $theirs))
            ->assertForbidden();
    }

    /* 12. Service-level arithmetic ----------------------------------------------------- */

    public function test_the_quote_is_worked_out_in_centavos(): void
    {
        $fees = app(EnvironmentalFeeService::class);
        $policy = $this->policy(['amount' => '33.33']);

        $this->assertSame('99.99', $fees->quote($policy, 3));
        $this->assertSame('33.33', $fees->quote($policy, 1));

        // A nonsense quantity is floored at one, never negative.
        $this->assertSame('33.33', $fees->quote($policy, 0));
    }

    public function test_a_policy_knows_why_it_cannot_be_collected_against(): void
    {
        $today = CarbonImmutable::parse('2030-06-01');

        $off = $this->policy(enabled: false);
        $this->assertStringContainsString('switched off', $off->blockedReason($today));

        $future = new EnvironmentalFeePolicy([
            'amount' => '50.00', 'scope' => FeeScope::PerVisitor, 'effective_from' => '2031-01-01',
        ]);
        $future->enabled = true;

        $this->assertStringContainsString('does not take effect', $future->blockedReason($today));
    }
}
