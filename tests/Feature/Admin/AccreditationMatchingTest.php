<?php

namespace Tests\Feature\Admin;

use App\Enums\AccreditationMatch;
use App\Enums\AccreditationStatus;
use App\Enums\ResortStatus;
use App\Models\AccreditedResort;
use App\Models\Resort;
use App\Models\User;
use App\Services\AccreditationMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether an applicant appears on the office's accreditation list.
 *
 * The tests that matter most here are the ones about NOT matching. A
 * missed match costs the office a minute with two names side by side; a
 * wrong match tells them a business is accredited when a different one
 * is, in a green badge, with nothing on the page to cast doubt on it.
 * So most of this file is about near misses staying near misses.
 */
class AccreditationMatchingTest extends TestCase
{
    use RefreshDatabase;

    private AccreditationMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = app(AccreditationMatcher::class);
    }

    private function listed(array $attributes = []): AccreditedResort
    {
        return AccreditedResort::create(array_merge([
            'resort_name' => 'ABC Beach Resort',
            'owner_name' => 'Juan Dela Cruz',
            'barangay' => 'Tambobong',
            'accreditation_no' => 'ACC-001',
            'accreditation_status' => AccreditationStatus::Active->value,
            'valid_until' => now()->addYear()->toDateString(),
        ], $attributes));
    }

    private function applicant(array $attributes = [], array $owner = []): Resort
    {
        $user = User::factory()->resortOwner()->create(array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ], $owner));

        return Resort::factory()->for($user, 'owner')->create(array_merge([
            'name' => 'ABC Beach Resort',
            'barangay' => 'Tambobong',
            'status' => ResortStatus::Pending,
        ], $attributes));
    }

    /* Matching --------------------------------------------------------- */

    public function test_an_identical_resort_is_listed(): void
    {
        $record = $this->listed();
        $found = $this->matcher->check($this->applicant());

        $this->assertSame(AccreditationMatch::Listed, $found['status']);
        $this->assertTrue($record->is($found['accredited']));
    }

    /**
     * The office writes "ABC Beach Resort, Inc." and the owner registers
     * as "abc resort". One business, two spellings — which is the whole
     * reason the names are normalised rather than compared as typed.
     */
    public function test_the_same_resort_spelled_differently_is_listed(): void
    {
        $this->listed(['resort_name' => 'ABC Beach Resort, Inc.']);

        $found = $this->matcher->check($this->applicant(['name' => 'abc resort']));

        $this->assertSame(AccreditationMatch::Listed, $found['status']);
    }

    public function test_a_barangay_written_with_its_prefix_still_matches(): void
    {
        $this->listed(['barangay' => 'Brgy. Tambobong']);

        $found = $this->matcher->check($this->applicant(['barangay' => 'Tambobong']));

        $this->assertSame(AccreditationMatch::Listed, $found['status']);
    }

    /**
     * The person registering is often a manager or a son running the
     * business, while the list carries whoever signed the accreditation.
     * Requiring the owner names to agree would send nearly every true
     * match to review, and the office would stop reading the badge.
     */
    public function test_a_different_owner_name_does_not_prevent_a_match(): void
    {
        $this->listed(['owner_name' => 'Juan Dela Cruz']);

        $found = $this->matcher->check($this->applicant(owner: [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]));

        $this->assertSame(AccreditationMatch::Listed, $found['status']);

        // But the office is told, because it is worth a glance.
        $this->assertStringContainsString('Juan Dela Cruz', $found['note']);
    }

    /* Not matching ----------------------------------------------------- */

    public function test_an_unknown_resort_is_not_listed(): void
    {
        $this->listed();

        $found = $this->matcher->check($this->applicant(['name' => 'Sunset Cove Paradise']));

        $this->assertSame(AccreditationMatch::NotListed, $found['status']);
        $this->assertNull($found['accredited']);
    }

    public function test_an_empty_list_leaves_everything_not_listed(): void
    {
        $found = $this->matcher->check($this->applicant());

        $this->assertSame(AccreditationMatch::NotListed, $found['status']);
    }

    /* Near misses stay near misses ------------------------------------- */

    /**
     * The case the whole design exists to prevent: two businesses whose
     * names are one letter apart must never be reported as one.
     */
    public function test_a_similar_but_different_name_is_only_a_review(): void
    {
        $this->listed(['resort_name' => 'Marina Bay Resort']);

        $found = $this->matcher->check($this->applicant(['name' => 'Marine Bay Resort']));

        $this->assertSame(AccreditationMatch::NeedsReview, $found['status']);
        $this->assertStringContainsString('Marina Bay Resort', $found['note']);
    }

    public function test_the_same_name_in_a_different_barangay_is_only_a_review(): void
    {
        $this->listed(['barangay' => 'Tambobong']);

        $found = $this->matcher->check($this->applicant(['barangay' => 'Osmeña']));

        $this->assertSame(AccreditationMatch::NeedsReview, $found['status']);
        $this->assertStringContainsString('Tambobong', $found['note']);
    }

    /**
     * Two accredited resorts share a name. Reporting either one as the
     * match would be a coin toss presented as a fact.
     */
    public function test_two_records_sharing_a_name_go_to_review(): void
    {
        $this->listed(['barangay' => 'Tambobong', 'accreditation_no' => 'ACC-001']);
        $this->listed(['barangay' => 'Osmeña', 'accreditation_no' => 'ACC-002']);

        $found = $this->matcher->check($this->applicant(['barangay' => 'Bobonot']));

        $this->assertSame(AccreditationMatch::NeedsReview, $found['status']);
        $this->assertNull($found['accredited']);
    }

    /** Unless the barangay picks one of them out. */
    public function test_the_barangay_can_single_one_out_of_a_shared_name(): void
    {
        $this->listed(['barangay' => 'Tambobong', 'accreditation_no' => 'ACC-001']);
        $wanted = $this->listed(['barangay' => 'Osmeña', 'accreditation_no' => 'ACC-002']);

        $found = $this->matcher->check($this->applicant(['barangay' => 'Osmeña']));

        $this->assertSame(AccreditationMatch::Listed, $found['status']);
        $this->assertTrue($wanted->is($found['accredited']));
    }

    /* Accreditation that is not current -------------------------------- */

    public function test_an_expired_accreditation_is_not_reported_as_listed(): void
    {
        $this->listed(['valid_until' => now()->subMonth()->toDateString()]);

        $found = $this->matcher->check($this->applicant());

        $this->assertSame(AccreditationMatch::NeedsReview, $found['status']);
        $this->assertStringContainsString('expired', $found['note']);
    }

    public function test_a_suspended_accreditation_is_not_reported_as_listed(): void
    {
        $this->listed(['accreditation_status' => AccreditationStatus::Suspended->value]);

        $found = $this->matcher->check($this->applicant());

        $this->assertSame(AccreditationMatch::NeedsReview, $found['status']);
        $this->assertStringContainsString('suspended', $found['note']);
    }

    /**
     * A record with no expiry on file is not expired. Reading a blank
     * date as "lapsed" would send a correct list to review wholesale.
     */
    public function test_a_record_with_no_expiry_date_is_still_current(): void
    {
        $this->listed(['valid_until' => null]);

        $this->assertSame(
            AccreditationMatch::Listed,
            $this->matcher->check($this->applicant())['status'],
        );
    }

    /* Writing the finding down ----------------------------------------- */

    public function test_the_finding_is_saved_on_the_application(): void
    {
        $record = $this->listed();
        $resort = $this->applicant();

        $this->matcher->apply($resort);
        $resort->refresh();

        $this->assertSame(AccreditationMatch::Listed, $resort->accreditation_match_status);
        $this->assertSame($record->id, $resort->matched_accredited_resort_id);
        $this->assertSame('ACC-001', $resort->matched_accreditation_no);
        $this->assertNotNull($resort->accreditation_checked_at);
        $this->assertNotEmpty($resort->accreditation_match_note);
    }

    /**
     * The office's own design decision: the uploaded list is the
     * reference, and an application keeps the finding it was decided on
     * even after the list behind it is replaced.
     */
    public function test_a_finding_survives_the_record_it_matched_being_removed(): void
    {
        $record = $this->listed();
        $resort = $this->applicant();

        $this->matcher->apply($resort);
        $record->delete();
        $resort->refresh();

        $this->assertSame(AccreditationMatch::Listed, $resort->accreditation_match_status);
        $this->assertNull($resort->matched_accredited_resort_id);

        // The number is what survives, and it is what the office reads.
        $this->assertSame('ACC-001', $resort->matched_accreditation_no);
    }

    public function test_the_check_is_written_to_the_activity_log(): void
    {
        $this->listed();
        $resort = $this->applicant();

        $this->matcher->apply($resort);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'resort.accreditation_checked',
            'subject_type' => 'resort',
            'subject_id' => $resort->id,
        ]);
    }

    /* Re-checking after an upload -------------------------------------- */

    public function test_uploading_the_list_updates_applications_still_waiting(): void
    {
        $resort = $this->applicant();
        $this->matcher->apply($resort);

        $this->assertSame(AccreditationMatch::NotListed, $resort->fresh()->accreditation_match_status);

        // The office adds them to the register.
        $this->listed();
        $this->matcher->recheckPending();

        $this->assertSame(AccreditationMatch::Listed, $resort->fresh()->accreditation_match_status);
    }

    /**
     * An application already decided keeps its finding. It is part of
     * the record of a decision, not a live reading.
     */
    public function test_a_decided_application_is_left_alone_by_a_recheck(): void
    {
        $resort = $this->applicant();
        $this->matcher->apply($resort);

        $resort->forceFill(['status' => ResortStatus::Active->value])->save();

        $this->listed();
        $this->matcher->recheckPending();

        $this->assertSame(AccreditationMatch::NotListed, $resort->fresh()->accreditation_match_status);
    }
}
