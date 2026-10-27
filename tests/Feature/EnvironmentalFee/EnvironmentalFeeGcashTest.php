<?php

namespace Tests\Feature\EnvironmentalFee;

use App\Enums\FeePaymentStatus;
use App\Enums\FeeScope;
use App\Models\EnvironmentalFeeAccount;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeeCollector;
use App\Models\EnvironmentalFeePolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paying the environmental fee by GCash.
 *
 * THE ONE THING THESE TESTS EXIST TO PROTECT: a QR code is not a
 * payment gateway. Visit Dasol has no connection to the municipal
 * wallet and cannot see a transfer arrive, so a recorded GCash payment
 * is a CLAIM until a person opens the app and confirms it — and until
 * then it must not appear in a single total.
 *
 * A municipality that reported unverified transfers as collected would
 * be reporting money it may never have been sent.
 */
class EnvironmentalFeeGcashTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    private User $official;

    private EnvironmentalFeeCheckpoint $checkpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');
        Storage::fake('public');
        Storage::fake('local');

        $this->mto = User::factory()->superAdmin()->create();
        $this->official = User::factory()->create([
            'role' => 'barangay_official', 'barangay' => 'Tambobong',
        ]);

        $checkpoint = new EnvironmentalFeeCheckpoint([
            'name' => 'Tambobong beach road',
            'barangay' => 'Tambobong',
            'latitude' => 15.9327,
            'longitude' => 119.7823,
        ]);
        $checkpoint->createdBy()->associate($this->mto);
        $checkpoint->save();

        $this->checkpoint = $checkpoint;

        EnvironmentalFeeCollector::query()->create([
            'checkpoint_id' => $checkpoint->id,
            'user_id' => $this->official->id,
            'assigned_by' => $this->mto->id,
            'active' => true,
        ]);

        $policy = new EnvironmentalFeePolicy([
            'amount' => '50.00',
            'scope' => FeeScope::PerVisitor,
            'effective_from' => '2030-01-01',
        ]);
        $policy->createdBy()->associate($this->mto);
        $policy->enabled = true;
        $policy->enabled_by = $this->mto->id;
        $policy->enabled_at = now();
        $policy->save();
    }

    private function gcashAccount(bool $active = true, bool $withQr = true): EnvironmentalFeeAccount
    {
        $account = new EnvironmentalFeeAccount([
            'account_name' => 'Municipality of Dasol',
            'gcash_number' => '09171234567',
            'instructions' => 'Put your plate number in the message.',
        ]);

        $account->qr_image = $withQr ? 'environmental-fee-qr/test.png' : null;
        $account->active = $active;
        $account->updated_by = $this->mto->id;
        $account->save();

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'checkpoint_id' => $this->checkpoint->id,
            'receipt_number' => 'R-001',
            'quantity' => 2,
            'payment_method' => 'gcash',
            'gcash_reference' => 'GC-8899',
            'gcash_sender' => 'Bea Santos',
        ], $overrides);
    }

    /* Setting GCash up ------------------------------------------------------ */

    public function test_gcash_is_off_until_the_office_sets_it_up(): void
    {
        $this->assertNull(EnvironmentalFeeAccount::current());

        // The form offers no choice it cannot honour.
        $this->actingAs($this->official)
            ->get(route('environmental-fee.index'))
            ->assertOk()
            ->assertDontSee('How did they pay?');
    }

    public function test_the_office_can_upload_the_municipal_qr_code(): void
    {
        $this->actingAs($this->mto)->put(route('admin.environmental-fee.gcash.update'), [
            'account_name' => 'Municipality of Dasol',
            'gcash_number' => '0917 123 4567',
            'instructions' => 'Put your plate number in the message.',
            'qr_image' => UploadedFile::fake()->create('qr.png', 40, 'image/png'),
            'active' => '1',
        ])->assertRedirect(route('admin.environmental-fee.gcash.edit'));

        $account = EnvironmentalFeeAccount::current();

        $this->assertNotNull($account);
        $this->assertTrue($account->active);
        // Digits only, the way the resort screen stores them.
        $this->assertSame('09171234567', $account->gcash_number);
        Storage::disk('public')->assertExists($account->qr_image);

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.gcash_updated']);
    }

    /**
     * Somebody at a gate holding up a phone needs the QR to point at.
     * A number alone has them reading digits through a car window.
     */
    public function test_gcash_cannot_be_switched_on_without_a_qr_code(): void
    {
        $this->actingAs($this->mto)->put(route('admin.environmental-fee.gcash.update'), [
            'account_name' => 'Municipality of Dasol',
            'gcash_number' => '09171234567',
            'active' => '1',
        ])->assertSessionHas('error');

        $this->assertFalse((bool) EnvironmentalFeeAccount::current()?->active);
    }

    public function test_only_the_office_may_change_the_gcash_details(): void
    {
        foreach ([$this->official, User::factory()->create(), User::factory()->resortOwner()->create()] as $user) {
            $this->actingAs($user)
                ->put(route('admin.environmental-fee.gcash.update'), [
                    'account_name' => 'Somebody else',
                    'gcash_number' => '09170000000',
                ])
                ->assertForbidden();
        }

        $this->assertNull(EnvironmentalFeeAccount::current());
    }

    /* Recording a GCash payment ------------------------------------------------ */

    public function test_a_collector_can_record_a_gcash_payment(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->payload())
            ->assertSessionHas('success');

        $collection = EnvironmentalFeeCollection::sole();

        $this->assertSame('gcash', $collection->payment_method);
        $this->assertSame('GC-8899', $collection->gcash_reference);
        $this->assertSame('Bea Santos', $collection->gcash_sender);
        $this->assertSame('100.00', $collection->amount);

        // A claim, not a payment.
        $this->assertSame(FeePaymentStatus::AwaitingVerification, $collection->payment_status);
        $this->assertTrue($collection->awaitsVerification());
    }

    public function test_gcash_cannot_be_recorded_before_the_office_sets_it_up(): void
    {
        // No account at all.
        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->payload())
            ->assertSessionHas('error');

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());

        // Configured but switched off.
        $this->gcashAccount(active: false);

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->payload())
            ->assertSessionHas('error');

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    public function test_a_gcash_payment_needs_its_reference_number(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->payload(['gcash_reference' => '']))
            ->assertSessionHasErrors('gcash_reference');

        $this->assertSame(0, EnvironmentalFeeCollection::query()->count());
    }

    /**
     * One transfer pays one fee. Without this the same reference could
     * be claimed against several receipts, and money sent once would be
     * recorded as having arrived three times.
     */
    public function test_one_gcash_reference_cannot_pay_two_fees(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->payload(['receipt_number' => 'R-1']))
            ->assertSessionHas('success');

        $this->actingAs($this->official)
            ->post(route('environmental-fee.store'), $this->payload(['receipt_number' => 'R-2']))
            ->assertSessionHas('error');

        $this->assertSame(1, EnvironmentalFeeCollection::query()->count());
    }

    /**
     * A payer's screenshot carries their GCash name and often their
     * balance. A guessable path under /storage would hand it to anybody.
     */
    public function test_the_payers_screenshot_is_kept_off_the_public_disk(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload([
            'proof_image' => UploadedFile::fake()->create('receipt.png', 40, 'image/png'),
        ]))->assertSessionHas('success');

        $collection = EnvironmentalFeeCollection::sole();

        $this->assertNotNull($collection->proof_image);
        Storage::disk('local')->assertExists($collection->proof_image);
        Storage::disk('public')->assertMissing($collection->proof_image);
    }

    public function test_the_screenshot_is_served_only_to_the_office(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload([
            'proof_image' => UploadedFile::fake()->create('receipt.png', 40, 'image/png'),
        ]));

        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.proof', $collection))
            ->assertOk();

        // Not even the collector who uploaded it.
        $this->actingAs($this->official)
            ->get(route('admin.environmental-fee.proof', $collection))
            ->assertForbidden();
    }

    /* The money does not count until somebody checks ----------------------------- */

    /**
     * The heart of it. An unchecked transfer is a claim that money was
     * sent, and a municipality counting those is reporting funds it may
     * never have received.
     */
    public function test_an_unchecked_transfer_counts_towards_nothing(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());

        $this->assertSame(0, EnvironmentalFeeCollection::query()->counted()->count());
        $this->assertSame('0', (string) EnvironmentalFeeCollection::query()->counted()->sum('amount'));

        $response = $this->actingAs($this->mto)->get(route('admin.environmental-fee.reports', [
            'from' => '2030-06-01', 'to' => '2030-06-30',
        ]))->assertOk();

        $this->assertSame('0', $response->viewData('total'));
        $this->assertSame('100.00', $response->viewData('awaitingTotal'));
        $this->assertSame(1, $response->viewData('awaitingCount'));
    }

    public function test_confirming_a_transfer_makes_it_count(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());
        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.settle', $collection), ['arrived' => '1'])
            ->assertRedirect();

        $collection->refresh();

        $this->assertSame(FeePaymentStatus::Verified, $collection->payment_status);
        $this->assertSame($this->mto->id, $collection->verified_by);
        $this->assertNotNull($collection->verified_at);

        $this->assertSame('100.00', (string) EnvironmentalFeeCollection::query()->counted()->sum('amount'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.payment_verified']);
    }

    /**
     * Rejecting is not voiding. The receipt stands — a fee may well
     * have been owed — but the money did not come.
     */
    public function test_a_transfer_that_never_arrived_is_marked_and_not_counted(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());
        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)->patch(route('admin.environmental-fee.settle', $collection), [
            'arrived' => '0',
            'note' => 'No such reference in the GCash app.',
        ])->assertRedirect();

        $collection->refresh();

        $this->assertSame(FeePaymentStatus::Rejected, $collection->payment_status);
        $this->assertSame('No such reference in the GCash app.', $collection->verification_note);

        // The receipt is still on record, and still not money.
        $this->assertSame(\App\Enums\CollectionStatus::Recorded, $collection->status);
        $this->assertSame(0, EnvironmentalFeeCollection::query()->counted()->count());

        $this->assertDatabaseHas('activity_logs', ['action' => 'environmental_fee.payment_rejected']);
    }

    public function test_saying_a_transfer_did_not_arrive_needs_a_reason(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());
        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.settle', $collection), ['arrived' => '0'])
            ->assertSessionHas('error');

        $this->assertTrue($collection->refresh()->awaitsVerification());
    }

    public function test_a_transfer_cannot_be_checked_twice(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());
        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->mto)->patch(route('admin.environmental-fee.settle', $collection), ['arrived' => '1']);

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.settle', $collection), ['arrived' => '0', 'note' => 'Changed my mind.'])
            ->assertSessionHas('error');

        $this->assertSame(FeePaymentStatus::Verified, $collection->refresh()->payment_status);
    }

    /**
     * Somebody able to confirm their own transfers could record a fee,
     * mark it arrived, and pocket the cash instead.
     */
    public function test_a_collector_cannot_confirm_their_own_transfer(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());
        $collection = EnvironmentalFeeCollection::sole();

        $this->actingAs($this->official)
            ->patch(route('admin.environmental-fee.settle', $collection), ['arrived' => '1'])
            ->assertForbidden();

        $this->actingAs($this->official)
            ->get(route('admin.environmental-fee.verify'))
            ->assertForbidden();

        $this->assertTrue($collection->refresh()->awaitsVerification());
    }

    /* Cash is unaffected ---------------------------------------------------------- */

    public function test_cash_still_counts_the_moment_it_is_written_down(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload([
            'payment_method' => 'cash',
            'gcash_reference' => null,
        ]))->assertSessionHas('success');

        $collection = EnvironmentalFeeCollection::sole();

        $this->assertSame('cash', $collection->payment_method);
        $this->assertSame(FeePaymentStatus::Received, $collection->payment_status);
        $this->assertNull($collection->gcash_reference);
        $this->assertSame('100.00', (string) EnvironmentalFeeCollection::query()->counted()->sum('amount'));
    }

    public function test_the_report_tells_cash_and_gcash_apart(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload([
            'receipt_number' => 'CASH-1', 'payment_method' => 'cash', 'gcash_reference' => null,
        ]));

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload([
            'receipt_number' => 'GC-1', 'gcash_reference' => 'GC-AAAA',
        ]));

        $gcash = EnvironmentalFeeCollection::query()->where('receipt_number', 'GC-1')->sole();
        $this->actingAs($this->mto)->patch(route('admin.environmental-fee.settle', $gcash), ['arrived' => '1']);

        $response = $this->actingAs($this->mto)->get(route('admin.environmental-fee.reports', [
            'from' => '2030-06-01', 'to' => '2030-06-30',
        ]))->assertOk();

        // A treasurer reconciles the cash box and the wallet separately.
        $this->assertSame('100.00', $response->viewData('cashTotal'));
        $this->assertSame('100.00', $response->viewData('gcashTotal'));
        $this->assertSame('200.00', $response->viewData('total'));
    }

    /* What the screens say ----------------------------------------------------------- */

    public function test_the_collector_is_shown_the_qr_and_told_it_is_not_settled(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)
            ->get(route('environmental-fee.index'))
            ->assertOk()
            ->assertSee('Show this to pay by GCash')
            ->assertSee('Municipality of Dasol')
            ->assertSee('Do not treat it as', false);
    }

    /**
     * An office that believes the portal watches its wallet will stop
     * checking, and will report money it was never sent.
     */
    public function test_the_office_is_told_the_portal_cannot_see_its_wallet(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.gcash.edit'))
            ->assertOk()
            ->assertSee('not a payment system')
            ->assertSee('cannot see a transfer');

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.verify'))
            ->assertOk()
            ->assertSee('cannot see your wallet');
    }

    public function test_the_export_says_which_money_is_unconfirmed(): void
    {
        $this->gcashAccount();

        $this->actingAs($this->official)->post(route('environmental-fee.store'), $this->payload());

        $csv = $this->actingAs($this->mto)->get(route('admin.environmental-fee.export', [
            'from' => '2030-06-01', 'to' => '2030-06-30',
        ]))->assertOk()->streamedContent();

        $this->assertStringContainsString('awaiting_verification', $csv);
        $this->assertStringContainsString('NOT money the municipality holds', $csv);
    }
}
