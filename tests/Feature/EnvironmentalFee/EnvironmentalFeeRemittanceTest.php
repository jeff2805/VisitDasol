<?php

namespace Tests\Feature\EnvironmentalFee;

use App\Enums\CollectionStatus;
use App\Enums\FeeScope;
use App\Enums\RemittanceStatus;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeeCollector;
use App\Models\EnvironmentalFeePolicy;
use App\Models\EnvironmentalFeeRemittance;
use App\Models\User;
use App\Services\EnvironmentalFeeRemittanceService;
use App\Services\EnvironmentalFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Handing collected fee money over, and counting it.
 *
 * TWO RULES RUN THROUGH ALL OF THIS:
 *
 *   1. THE EXPECTED FIGURE IS NEVER SUPPLIED BY THE FORM. It is summed
 *      from the receipts on the server, every time. A reconciliation
 *      where the person being reconciled provides both numbers is not
 *      a reconciliation.
 *
 *   2. DECLARING AND COUNTING ARE DIFFERENT JOBS. Anyone who could do
 *      both could hand in a short envelope and certify it themselves.
 */
class EnvironmentalFeeRemittanceTest extends TestCase
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

        $this->travelTo('2030-06-10 09:00:00');

        $this->mto = User::factory()->superAdmin()->create();
        $this->official = User::factory()->create([
            'role' => 'barangay_official',
            'barangay' => 'Tambobong',
        ]);
        $this->owner = User::factory()->resortOwner()->create();
        $this->tourist = User::factory()->create();

        $this->checkpoint = $this->makeCheckpoint('Tambobong beach road');
        $this->assign($this->official);
    }

    /* Fixtures ------------------------------------------------------------ */

    private function makeCheckpoint(string $name): EnvironmentalFeeCheckpoint
    {
        $checkpoint = new EnvironmentalFeeCheckpoint([
            'name' => $name,
            'barangay' => 'Tambobong',
            'latitude' => 15.9327,
            'longitude' => 119.7823,
        ]);

        $checkpoint->createdBy()->associate($this->mto);
        $checkpoint->active = true;
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

    /**
     * The municipal GCash account. Needed only where a GCash receipt
     * has to exist; the module rightly refuses one otherwise.
     */
    private function gcashAccount(): \App\Models\EnvironmentalFeeAccount
    {
        $account = new \App\Models\EnvironmentalFeeAccount([
            'account_name' => 'Municipality of Dasol',
            'gcash_number' => '09171234567',
        ]);

        $account->qr_image = 'environmental-fee-qr/test.png';
        $account->active = true;
        $account->updated_by = $this->mto->id;
        $account->save();

        return $account;
    }

    private function policy(string $amount = '50.00'): EnvironmentalFeePolicy
    {
        $policy = new EnvironmentalFeePolicy([
            'amount' => $amount,
            'scope' => FeeScope::PerVisitor,
            'effective_from' => '2030-01-01',
        ]);

        $policy->createdBy()->associate($this->mto);
        $policy->enabled = true;
        $policy->enabled_by = $this->mto->id;
        $policy->enabled_at = now();
        $policy->save();

        return $policy->refresh();
    }

    /**
     * A receipt written at the gate, through the real service, so the
     * figures these tests reconcile against are the ones the module
     * actually produces.
     */
    private function collect(
        int $quantity = 1,
        string $receipt = null,
        string $method = 'cash',
        ?CarbonImmutable $at = null,
    ): EnvironmentalFeeCollection {
        static $n = 0;
        $n++;

        if ($at !== null) {
            $this->travelTo($at->toDateTimeString());
        }

        $collection = app(EnvironmentalFeeService::class)->record(
            $this->official,
            $this->checkpoint,
            [
                'quantity' => $quantity,
                'receipt_number' => $receipt ?? 'OR-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'payment_method' => $method,
            ] + ($method === 'gcash' ? [
                'gcash_reference' => 'GC'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
                'gcash_sender' => 'A Payer',
            ] : []),
        );

        if ($at !== null) {
            $this->travelTo('2030-06-10 09:00:00');
        }

        return $collection;
    }

    private function submit(string $declared, array $overrides = [])
    {
        return $this->actingAs($this->official)->post(
            route('environmental-fee.remittances.store'),
            array_merge([
                'checkpoint_id' => $this->checkpoint->id,
                'period_start' => '2030-06-09',
                'period_end' => '2030-06-10',
                'declared_amount' => $declared,
            ], $overrides)
        );
    }

    /* Who may reach any of it --------------------------------------------- */

    public function test_a_tourist_is_refused_every_handover_screen(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('environmental-fee.remittances.index'))
            ->assertForbidden();

        $this->actingAs($this->tourist)
            ->get(route('admin.environmental-fee.remittances.index'))
            ->assertForbidden();

        $this->actingAs($this->tourist)
            ->post(route('environmental-fee.remittances.store'), [])
            ->assertForbidden();
    }

    public function test_a_resort_owner_is_refused_every_handover_screen(): void
    {
        $this->actingAs($this->owner)
            ->get(route('environmental-fee.remittances.index'))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('admin.environmental-fee.remittances.index'))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('admin.environmental-fee.remittances.export'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('environmental-fee.remittances.index'))->assertRedirect(route('login'));
        $this->get(route('admin.environmental-fee.remittances.index'))->assertRedirect(route('login'));
    }

    public function test_a_collector_cannot_reach_the_office_screens(): void
    {
        $this->actingAs($this->official)
            ->get(route('admin.environmental-fee.remittances.index'))
            ->assertForbidden();
    }

    public function test_a_collector_can_reach_their_own_screen(): void
    {
        $this->actingAs($this->official)
            ->get(route('environmental-fee.remittances.index'))
            ->assertOk()
            ->assertSee('Hand in takings');
    }

    /* What is owed -------------------------------------------------------- */

    public function test_the_expected_figure_is_summed_from_the_receipts(): void
    {
        $this->policy('50.00');
        $this->collect(2);   // 100.00
        $this->collect(1);   // 50.00

        $expected = app(EnvironmentalFeeRemittanceService::class)->expected(
            $this->checkpoint,
            $this->official,
            CarbonImmutable::parse('2030-06-09'),
            CarbonImmutable::parse('2030-06-10'),
        );

        $this->assertSame('150.00', $expected['amount']);
        $this->assertSame(2, $expected['receipts']);
        $this->assertSame(3, $expected['quantity']);
    }

    /** A voided receipt is not money anybody has to hand over. */
    public function test_a_voided_receipt_drops_out_of_what_is_owed(): void
    {
        $this->policy('50.00');
        $this->collect(2);
        $voided = $this->collect(1);

        app(EnvironmentalFeeService::class)->void($voided, $this->mto, 'Wrong amount taken.');

        $expected = app(EnvironmentalFeeRemittanceService::class)->expected(
            $this->checkpoint,
            $this->official,
            CarbonImmutable::parse('2030-06-09'),
            CarbonImmutable::parse('2030-06-10'),
        );

        $this->assertSame('100.00', $expected['amount']);
        $this->assertSame(1, $expected['receipts']);
    }

    /**
     * GCash goes straight to the municipal account. There is no cash in
     * an envelope for it, so a collector must not be asked to hand it
     * over — and must certainly not be made short by it.
     */
    public function test_gcash_receipts_are_not_part_of_a_cash_handover(): void
    {
        $this->policy('50.00');

        // The module refuses a GCash payment until the office has set
        // the municipal account up, which is the correct behaviour and
        // has its own tests. One is needed here to get that far.
        $this->gcashAccount();

        $this->collect(2, method: 'cash');
        $this->collect(4, method: 'gcash');

        $expected = app(EnvironmentalFeeRemittanceService::class)->expected(
            $this->checkpoint,
            $this->official,
            CarbonImmutable::parse('2030-06-09'),
            CarbonImmutable::parse('2030-06-10'),
        );

        $this->assertSame('100.00', $expected['amount']);
        $this->assertSame(1, $expected['receipts']);
    }

    /* Handing in ---------------------------------------------------------- */

    public function test_a_collector_hands_a_period_in(): void
    {
        $this->policy('50.00');
        $this->collect(2);

        $this->submit('100.00')->assertSessionHasNoErrors();

        $remittance = EnvironmentalFeeRemittance::sole();

        $this->assertSame('100.00', $remittance->expected_amount);
        $this->assertSame('100.00', $remittance->declared_amount);
        $this->assertSame(1, $remittance->expected_receipts);
        $this->assertSame(RemittanceStatus::Submitted, $remittance->status);
        $this->assertNull($remittance->counted_amount);
        $this->assertSame((int) $this->official->id, (int) $remittance->collector_id);
        $this->assertStringStartsWith('VDR-', $remittance->reference);
    }

    /**
     * THE RULE AT THE HEART OF THIS. Whatever the form posts, the
     * expected figure comes from the receipts.
     */
    public function test_the_form_cannot_dictate_what_is_owed(): void
    {
        $this->policy('50.00');
        $this->collect(2);   // the receipts say 100.00

        $this->submit('100.00', [
            // All three are hopeful nonsense and must be ignored.
            'expected_amount' => '5.00',
            'expected_receipts' => 99,
            'status' => 'reconciled',
        ])->assertSessionHasNoErrors();

        $remittance = EnvironmentalFeeRemittance::sole();

        $this->assertSame('100.00', $remittance->expected_amount);
        $this->assertSame(1, $remittance->expected_receipts);
        $this->assertSame(RemittanceStatus::Submitted, $remittance->status);
    }

    public function test_a_short_handover_is_accepted_and_the_gap_recorded(): void
    {
        $this->policy('50.00');
        $this->collect(2);

        $this->submit('80.00', ['collector_note' => 'Two hundred-peso notes were torn.'])
            ->assertSessionHasNoErrors();

        $remittance = EnvironmentalFeeRemittance::sole();

        // Refusing a short figure would only mean it never gets written
        // down, which is the opposite of what an audit trail is for.
        $this->assertSame('80.00', $remittance->declared_amount);
        $this->assertSame('-20.00', $remittance->declaredVariance());
        $this->assertTrue($remittance->hasDiscrepancy());
    }

    public function test_handing_in_the_same_period_twice_is_refused(): void
    {
        $this->policy('50.00');
        $this->collect(2);

        $this->submit('100.00')->assertSessionHasNoErrors();
        $this->submit('100.00')->assertSessionHas('error');

        $this->assertSame(1, EnvironmentalFeeRemittance::count());
    }

    public function test_a_period_with_no_receipts_cannot_be_handed_in(): void
    {
        $this->policy('50.00');

        $this->submit('100.00')->assertSessionHas('error');

        $this->assertDatabaseCount('environmental_fee_remittances', 0);
    }

    public function test_a_collector_cannot_hand_in_for_a_gate_they_do_not_work(): void
    {
        $this->policy('50.00');
        $this->collect(2);

        $other = $this->makeCheckpoint('Someone else gate');

        $this->actingAs($this->official)
            ->post(route('environmental-fee.remittances.store'), [
                'checkpoint_id' => $other->id,
                'period_start' => '2030-06-09',
                'period_end' => '2030-06-10',
                'declared_amount' => '100.00',
            ])
            ->assertForbidden();
    }

    public function test_a_future_period_is_refused(): void
    {
        $this->policy('50.00');
        $this->collect(2);

        $this->submit('100.00', [
            'period_start' => '2031-01-01',
            'period_end' => '2031-01-02',
        ])->assertSessionHasErrors('period_start');
    }

    public function test_the_last_day_cannot_be_before_the_first(): void
    {
        $this->policy('50.00');
        $this->collect(2);

        $this->submit('100.00', [
            'period_start' => '2030-06-10',
            'period_end' => '2030-06-09',
        ])->assertSessionHasErrors('period_end');
    }

    /* Counting ------------------------------------------------------------ */

    private function handedIn(string $declared = '100.00'): EnvironmentalFeeRemittance
    {
        $this->policy('50.00');
        $this->collect(2);
        $this->submit($declared)->assertSessionHasNoErrors();

        return EnvironmentalFeeRemittance::sole();
    }

    /**
     * The office's handover page, actually rendered.
     *
     * Every other test here posts a count or reads the list; nothing
     * opened this page, and a sweep of the app could not reach it
     * either because it needs a row that only a submission creates. A
     * page nothing has ever rendered is where a view defect sits
     * unnoticed — which is exactly how a stray @endif made every
     * environmental fee receipt unopenable.
     */
    public function test_the_office_can_open_a_handover_before_and_after_counting_it(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.remittances.show', $remittance))
            ->assertOk()
            ->assertSee($remittance->reference)
            ->assertSee($this->official->full_name)
            ->assertSee($this->checkpoint->name)
            ->assertSee('₱100.00');

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '90.00',
                'review_note' => 'Ten pesos short; collector informed.',
            ])
            ->assertSessionHasNoErrors();

        $this->flushSession();

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.remittances.show', $remittance->refresh()))
            ->assertOk()
            ->assertSee('Ten pesos short; collector informed.')
            ->assertSee('₱90.00');
    }

    /** A cashier counts envelopes; that is the whole job. */
    public function test_a_cashier_can_open_a_handover(): void
    {
        $remittance = $this->handedIn();

        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)
            ->get(route('admin.environmental-fee.remittances.show', $remittance))
            ->assertOk()
            ->assertSee($remittance->reference);
    }

    public function test_a_resort_owner_cannot_open_a_handover(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->owner)
            ->get(route('admin.environmental-fee.remittances.show', $remittance))
            ->assertForbidden();

        $this->actingAs($this->tourist)
            ->get(route('admin.environmental-fee.remittances.show', $remittance))
            ->assertForbidden();
    }

    public function test_the_office_counts_a_matching_handover(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '100.00',
            ])
            ->assertSessionHasNoErrors();

        $remittance->refresh();

        $this->assertSame(RemittanceStatus::Reconciled, $remittance->status);
        $this->assertSame('100.00', $remittance->counted_amount);
        $this->assertSame('0.00', $remittance->variance());
        $this->assertSame((int) $this->mto->id, (int) $remittance->reviewed_by);
        $this->assertNotNull($remittance->reviewed_at);
    }

    /**
     * The status is DERIVED, not chosen. An officer cannot mark a short
     * envelope agreed by posting the word.
     */
    public function test_a_count_that_does_not_match_is_disputed_whatever_is_posted(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '90.00',
                'review_note' => 'Ten pesos short; collector informed.',
                'status' => 'reconciled',
            ])
            ->assertSessionHasNoErrors();

        $remittance->refresh();

        $this->assertSame(RemittanceStatus::Disputed, $remittance->status);
        $this->assertSame('-10.00', $remittance->variance());
    }

    /** An overage is a discrepancy too: money nobody wrote a receipt for. */
    public function test_counting_more_than_the_receipts_is_also_disputed(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '120.00',
                'review_note' => 'Twenty over. Checking for an unwritten receipt.',
            ])
            ->assertSessionHasNoErrors();

        $remittance->refresh();

        $this->assertSame(RemittanceStatus::Disputed, $remittance->status);
        $this->assertSame('20.00', $remittance->variance());
    }

    public function test_a_mismatch_cannot_be_recorded_without_an_explanation(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '90.00',
            ])
            ->assertSessionHas('error');

        $remittance->refresh();

        $this->assertSame(RemittanceStatus::Submitted, $remittance->status);
        $this->assertNull($remittance->counted_amount);
    }

    public function test_a_handover_is_only_counted_once(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '100.00',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '40.00',
                'review_note' => 'Trying again.',
            ])
            ->assertForbidden();

        $this->assertSame('100.00', $remittance->refresh()->counted_amount);
    }

    public function test_a_collector_cannot_count_anything(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->official)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '100.00',
            ])
            ->assertForbidden();

        $this->assertNull($remittance->refresh()->counted_amount);
    }

    /**
     * THE SEPARATION, ON THE ROW RATHER THAN THE ROLE.
     *
     * Nothing stops one person being both the office and a collector.
     * When they are, they still may not sign off their own envelope.
     */
    public function test_an_officer_cannot_count_their_own_handover(): void
    {
        $this->policy('50.00');
        $this->assign($this->mto);

        // The office collects at the gate itself, then hands in.
        app(EnvironmentalFeeService::class)->record(
            $this->mto,
            $this->checkpoint,
            ['quantity' => 2, 'receipt_number' => 'OR-SELF-1', 'payment_method' => 'cash'],
        );

        $this->actingAs($this->mto)->post(route('environmental-fee.remittances.store'), [
            'checkpoint_id' => $this->checkpoint->id,
            'period_start' => '2030-06-09',
            'period_end' => '2030-06-10',
            'declared_amount' => '100.00',
        ])->assertSessionHasNoErrors();

        $own = EnvironmentalFeeRemittance::sole();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $own), [
                'counted_amount' => '100.00',
            ])
            ->assertForbidden();

        $this->assertNull($own->refresh()->counted_amount);
    }

    /* Reading it ---------------------------------------------------------- */

    public function test_a_collector_reads_their_own_slip_but_not_another(): void
    {
        $mine = $this->handedIn();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.remittances.show', $mine))
            ->assertOk()
            ->assertSee($mine->reference);

        // Somebody else's gate and somebody else's money.
        $stranger = User::factory()->create(['role' => 'barangay_official', 'barangay' => 'Uli']);
        $other = $this->makeCheckpoint('Uli road');
        $this->assign($stranger, $other);

        app(EnvironmentalFeeService::class)->record(
            $stranger,
            $other,
            ['quantity' => 1, 'receipt_number' => 'OR-OTHER-1', 'payment_method' => 'cash'],
        );

        $this->actingAs($stranger)->post(route('environmental-fee.remittances.store'), [
            'checkpoint_id' => $other->id,
            'period_start' => '2030-06-09',
            'period_end' => '2030-06-10',
            'declared_amount' => '50.00',
        ])->assertSessionHasNoErrors();

        $theirs = EnvironmentalFeeRemittance::query()
            ->where('collector_id', $stranger->id)->sole();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.remittances.show', $theirs))
            ->assertForbidden();
    }

    /**
     * A receipt voided after the handover changes what the receipts
     * say. Both figures are shown; neither is quietly rewritten.
     */
    public function test_a_slip_says_when_the_receipts_have_changed_under_it(): void
    {
        $this->policy('50.00');
        $kept = $this->collect(2);
        $later = $this->collect(1);

        $this->submit('150.00')->assertSessionHasNoErrors();

        app(EnvironmentalFeeService::class)->void($later, $this->mto, 'Duplicate.');

        $remittance = EnvironmentalFeeRemittance::sole();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.remittances.show', $remittance))
            ->assertOk()
            ->assertSee('The receipts have changed since this was handed in.');

        // The frozen figure is left exactly as it was claimed.
        $this->assertSame('150.00', $remittance->expected_amount);
        $this->assertSame((int) $kept->id, (int) $kept->id);
    }

    public function test_the_office_sees_every_handover(): void
    {
        $remittance = $this->handedIn();

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.remittances.index'))
            ->assertOk()
            ->assertSee($remittance->reference)
            ->assertSee('Waiting to be counted');
    }

    /* The audit trail ------------------------------------------------------ */

    public function test_handing_in_and_counting_are_both_written_down(): void
    {
        $remittance = $this->handedIn();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'environmental_fee.remittance_submitted',
            'subject_type' => 'environmental_fee_remittance',
            'subject_id' => $remittance->id,
            'user_id' => $this->official->id,
        ]);

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.remittances.review', $remittance), [
                'counted_amount' => '100.00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'environmental_fee.remittance_reviewed',
            'subject_type' => 'environmental_fee_remittance',
            'subject_id' => $remittance->id,
            'user_id' => $this->mto->id,
        ]);
    }

    /** Figures and the people responsible. Never a payer. */
    public function test_the_audit_trail_records_the_figures_and_no_payer(): void
    {
        $remittance = $this->handedIn('80.00');

        $log = \App\Models\ActivityLog::query()
            ->where('action', 'environmental_fee.remittance_submitted')
            ->sole();

        $properties = is_string($log->properties)
            ? json_decode($log->properties, true)
            : $log->properties;

        $this->assertSame('100.00', $properties['expected']);
        $this->assertSame('80.00', $properties['declared']);

        foreach (['payer', 'gcash_sender', 'contact_name', 'email', 'phone'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $properties);
        }
    }

    /* Keeping municipal money apart from resort money ---------------------- */

    /**
     * The table has no column that could reach a booking, and nothing
     * in the module joins to one. Checked structurally so a future
     * "convenient" foreign key has to break a test to get in.
     */
    public function test_a_handover_cannot_reach_resort_income(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing(
            'environmental_fee_remittances'
        );

        foreach (['booking_id', 'resort_id', 'room_id', 'payment_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_handing_in_changes_no_booking_total(): void
    {
        $booking = \App\Models\Booking::factory()->create();
        $before = $booking->total_amount;

        $this->handedIn();

        $this->assertSame($before, $booking->refresh()->total_amount);
    }

    /* The export ----------------------------------------------------------- */

    public function test_the_office_can_export_the_handovers(): void
    {
        $remittance = $this->handedIn();

        $response = $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.remittances.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString($remittance->reference, $csv);
        $this->assertStringContainsString('Expected', $csv);
        $this->assertStringContainsString('Declared', $csv);
        $this->assertStringContainsString('Difference', $csv);

        // Excel reads the ñ in Osmeña as rubbish without the BOM.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_only_the_office_can_export(): void
    {
        $this->handedIn();

        $this->actingAs($this->official)
            ->get(route('admin.environmental-fee.remittances.export'))
            ->assertForbidden();
    }

    /* Nothing deletes a financial record ----------------------------------- */

    public function test_nobody_may_delete_a_handover(): void
    {
        $remittance = $this->handedIn();

        $this->assertFalse($this->mto->can('delete', $remittance));
        $this->assertFalse($this->official->can('delete', $remittance));
    }
}
