<?php

namespace Tests\Feature\EnvironmentalFee;

use App\Enums\FeeScope;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeePolicy;
use App\Models\User;
use App\Services\EnvironmentalFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a visitor is shown the environmental fee, and when they are not.
 *
 * The rule is simple and absolute: a visitor is told about a charge
 * only while that charge is actually in force. A fee whose period has
 * ended is not a fee, and showing it would be telling people to pay
 * something nobody may collect.
 *
 * The hard part is not the rule, it is the EXPLAINING. An office
 * looking at a page with no fee on it has to be told which of three
 * things is wrong — never configured, switched off, or the dates have
 * passed — because the fix is different in each case and "check the
 * dates" is useful for none of them.
 */
class EnvironmentalFeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    private User $tourist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-15 09:00:00');

        $this->mto = User::factory()->superAdmin()->create();
        $this->tourist = User::factory()->create();
    }

    private function policy(array $attributes, bool $enabled = true): EnvironmentalFeePolicy
    {
        $policy = new EnvironmentalFeePolicy($attributes + [
            'amount' => '10.00',
            'scope' => FeeScope::PerVisitor,
            'effective_from' => '2030-06-01',
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

    private function checkpoint(): EnvironmentalFeeCheckpoint
    {
        $checkpoint = new EnvironmentalFeeCheckpoint([
            'name' => 'Barangay Check Point',
            'barangay' => 'Tambobong',
            'latitude' => 15.9327,
            'longitude' => 119.7823,
        ]);

        $checkpoint->createdBy()->associate($this->mto);
        $checkpoint->active = true;
        $checkpoint->save();

        return $checkpoint;
    }

    /* What a visitor sees -------------------------------------------------- */

    public function test_a_visitor_is_told_about_a_fee_that_is_in_force(): void
    {
        $this->policy(['effective_until' => null]);
        $this->checkpoint();

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('Environmental fee');
    }

    /**
     * THE CASE THAT CAUGHT THIS OUT. A policy switched on, with a real
     * rate, whose end date has passed. Everything looks configured and
     * nothing is in force.
     */
    public function test_a_visitor_is_told_nothing_once_the_period_has_ended(): void
    {
        $this->policy([
            'effective_from' => '2030-06-01',
            'effective_until' => '2030-06-10',   // five days ago
        ]);
        $this->checkpoint();

        $this->assertNull(app(EnvironmentalFeeService::class)->activePolicy());

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('Environmental fee');
    }

    public function test_a_visitor_is_told_nothing_before_the_period_starts(): void
    {
        $this->policy([
            'effective_from' => '2030-07-01',    // a fortnight away
            'effective_until' => null,
        ]);
        $this->checkpoint();

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('Environmental fee');
    }

    public function test_a_visitor_is_told_nothing_while_the_fee_is_switched_off(): void
    {
        $this->policy(['effective_until' => null], enabled: false);
        $this->checkpoint();

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('Environmental fee');
    }

    /** The module ships silent, and must stay that way. */
    public function test_a_visitor_is_told_nothing_when_nothing_is_configured(): void
    {
        $this->assertDatabaseCount('environmental_fee_policies', 0);

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('Environmental fee');
    }

    /* What the office is told ---------------------------------------------- */

    public function test_the_office_is_told_the_period_ended_and_when(): void
    {
        $this->policy([
            'effective_from' => '2030-06-01',
            'effective_until' => '2030-06-10',
        ]);

        $reason = app(EnvironmentalFeeService::class)->blockedReason();

        $this->assertStringContainsString('stopped applying on Jun 10, 2030', $reason);
        $this->assertStringContainsString('visitors are shown nothing', $reason);

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.index'))
            ->assertOk()
            ->assertSee('stopped applying on Jun 10, 2030')
            // And the row itself is marked, not left to be worked out
            // from two dates and today's.
            ->assertSee('Ended');
    }

    public function test_the_office_is_told_when_a_fee_has_not_started(): void
    {
        $this->policy([
            'effective_from' => '2030-07-01',
            'effective_until' => null,
        ]);

        $reason = app(EnvironmentalFeeService::class)->blockedReason();

        $this->assertStringContainsString('does not start until Jul 1, 2030', $reason);

        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.index'))
            ->assertOk()
            ->assertSee('Not started');
    }

    public function test_the_office_is_told_when_a_fee_is_merely_switched_off(): void
    {
        $this->policy(['effective_until' => null], enabled: false);

        $reason = app(EnvironmentalFeeService::class)->blockedReason();

        $this->assertStringContainsString('switched off', $reason);
        $this->assertStringNotContainsString('stopped applying', $reason);
    }

    public function test_the_office_is_told_when_nothing_was_ever_configured(): void
    {
        $reason = app(EnvironmentalFeeService::class)->blockedReason();

        $this->assertStringContainsString('No environmental fee has been configured', $reason);
    }

    /**
     * The three answers must not collapse into one. Each sends the
     * officer somewhere different.
     */
    public function test_the_three_reasons_are_different_sentences(): void
    {
        $service = app(EnvironmentalFeeService::class);

        $never = $service->blockedReason();

        $this->policy(['effective_until' => '2030-06-10']);
        $ended = $service->blockedReason();

        EnvironmentalFeePolicy::query()->delete();
        $this->policy(['effective_until' => null], enabled: false);
        $off = $service->blockedReason();

        $this->assertNotSame($never, $ended);
        $this->assertNotSame($never, $off);
        $this->assertNotSame($ended, $off);
    }

    /* Putting an expired fee back in force ---------------------------------- */

    public function test_the_office_can_remove_an_end_date_and_the_fee_returns(): void
    {
        $policy = $this->policy([
            'effective_from' => '2030-06-01',
            'effective_until' => '2030-06-10',
        ]);
        $this->checkpoint();

        $this->assertNull(app(EnvironmentalFeeService::class)->activePolicy());

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.extend', $policy), [
                'effective_until' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($policy->refresh()->effective_until);
        $this->assertNotNull(app(EnvironmentalFeeService::class)->activePolicy());

        // And the visitor is told again.
        $this->get(route('map'))->assertOk()->assertSee('Environmental fee');
    }

    public function test_extending_cannot_change_the_rate(): void
    {
        $policy = $this->policy(['amount' => '10.00', 'effective_until' => '2030-06-10']);

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.extend', $policy), [
                'effective_until' => null,

                // Hopeful, and must be ignored: receipts have been
                // written at 10.00 and the trail must keep saying so.
                'amount' => '500.00',
                'scope' => 'per_vehicle',
                'effective_from' => '2020-01-01',
            ])
            ->assertSessionHasNoErrors();

        $policy->refresh();

        $this->assertSame('10.00', $policy->amount);
        $this->assertSame(FeeScope::PerVisitor, $policy->scope);
        $this->assertSame('2030-06-01', $policy->effective_from->toDateString());
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $policy = $this->policy(['effective_from' => '2030-06-01', 'effective_until' => null]);

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.extend', $policy), [
                'effective_until' => '2030-05-01',
            ])
            ->assertSessionHasErrors('effective_until');

        $this->assertNull($policy->refresh()->effective_until);
    }

    public function test_extending_is_written_to_the_audit_trail(): void
    {
        $policy = $this->policy(['effective_until' => '2030-06-10']);

        $this->actingAs($this->mto)
            ->patch(route('admin.environmental-fee.extend', $policy), ['effective_until' => null])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'environmental_fee.policy_extended',
            'subject_type' => 'environmental_fee_policy',
            'subject_id' => $policy->id,
            'user_id' => $this->mto->id,
        ]);
    }

    public function test_only_the_office_may_change_the_dates(): void
    {
        $policy = $this->policy(['effective_until' => '2030-06-10']);

        $owner = User::factory()->resortOwner()->create();

        foreach ([$this->tourist, $owner] as $user) {
            $this->actingAs($user)
                ->patch(route('admin.environmental-fee.extend', $policy), ['effective_until' => null])
                ->assertForbidden();
        }

        $this->assertSame('2030-06-10', $policy->refresh()->effective_until->toDateString());
    }

    /* Collecting follows the same rule -------------------------------------- */

    public function test_nothing_may_be_collected_once_the_period_has_ended(): void
    {
        $this->policy([
            'effective_from' => '2030-06-01',
            'effective_until' => '2030-06-10',
        ]);

        $this->assertFalse(app(EnvironmentalFeeService::class)->isCollectable());
    }
}
