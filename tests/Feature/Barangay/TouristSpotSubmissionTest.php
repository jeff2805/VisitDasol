<?php

namespace Tests\Feature\Barangay;

use App\Enums\DestinationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Notifications\TouristSpotDecided;
use App\Notifications\TouristSpotSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A barangay writes up its own places; the office decides what gets published.
 *
 * The two halves of that sentence are the whole feature, and most of what is
 * below exists to hold the second half in place: nothing a barangay writes
 * reaches a visitor on its own.
 */
class TouristSpotSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $official;

    private User $office;

    private DestinationCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->official = User::factory()->barangayOfficial('Malacapas')->create();
        $this->office = User::factory()->superAdmin()->create();
        $this->category = DestinationCategory::factory()->create(['name' => 'Viewpoint']);
    }

    /** @param  array<string, mixed>  $extra */
    private function spotForm(array $extra = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'name' => 'Malacapas Sunset Rocks',
            'description' => 'A rocky shelf on the western shore where the sun drops straight into the water. '
                .'Best in the late afternoon, and reachable on foot from the view deck.',
            'address' => 'Sitio Baybay',
            'latitude' => 15.9902,
            'longitude' => 119.8811,
        ], $extra);
    }

    /* Writing one up ------------------------------------------------------ */

    public function test_an_official_can_send_in_a_spot_for_their_own_barangay(): void
    {
        Notification::fake();

        $this->actingAs($this->official)
            ->post(route('barangay.spots.store'), $this->spotForm())
            ->assertRedirect(route('barangay.spots.index'))
            ->assertSessionHasNoErrors();

        $spot = Destination::sole();

        $this->assertSame('Malacapas Sunset Rocks', $spot->name);
        $this->assertSame(DestinationStatus::Pending, $spot->status);
        $this->assertSame('Malacapas', $spot->barangay);
        $this->assertSame($this->official->id, $spot->created_by);
        $this->assertNotNull($spot->submitted_at);
        $this->assertFalse($spot->is_featured);

        Notification::assertSentTo($this->office, TouristSpotSubmitted::class);
    }

    /**
     * The barangay is read from the account, never the form. Otherwise an
     * official could file a spot against a barangay that is not theirs just by
     * editing the page before pressing send.
     */
    public function test_the_barangay_cannot_be_chosen_on_the_form(): void
    {
        Notification::fake();

        $this->actingAs($this->official)
            ->post(route('barangay.spots.store'), $this->spotForm(['barangay' => 'Tambobong']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Malacapas', Destination::sole()->barangay);
    }

    /** The point of the queue: a submission is not a publication. */
    public function test_a_submitted_spot_is_invisible_to_visitors(): void
    {
        Notification::fake();

        $this->actingAs($this->official)->post(route('barangay.spots.store'), $this->spotForm());
        $spot = Destination::sole();

        $this->post(route('logout'));

        $this->get(route('destinations.index'))->assertOk()->assertDontSee('Malacapas Sunset Rocks');
        $this->get(route('home'))->assertOk()->assertDontSee('Malacapas Sunset Rocks');

        // Nor by guessing the address.
        $this->get(route('destinations.show', $spot))->assertNotFound();
    }

    /* The office's decision ------------------------------------------------ */

    public function test_the_office_sees_it_waiting_and_can_publish_it(): void
    {
        Notification::fake();

        $spot = Destination::factory()->pending()->writtenBy($this->official)
            ->create(['name' => 'Malacapas Sunset Rocks']);

        $this->actingAs($this->office)
            ->get(route('admin.spot-submissions.index'))
            ->assertOk()
            ->assertSee('Malacapas Sunset Rocks')
            ->assertSee('Malacapas');

        $this->actingAs($this->office)
            ->patch(route('admin.spot-submissions.approve', $spot))
            ->assertRedirect(route('admin.spot-submissions.index'));

        $spot->refresh();
        $this->assertSame(DestinationStatus::Active, $spot->status);
        $this->assertSame($this->office->id, $spot->reviewed_by);
        $this->assertNotNull($spot->reviewed_at);

        // Now, and only now, a visitor can read it.
        $this->get(route('destinations.show', $spot))->assertOk()->assertSee('Malacapas Sunset Rocks');

        Notification::assertSentTo($this->official, TouristSpotDecided::class);
    }

    public function test_sending_one_back_needs_a_reason_the_barangay_can_act_on(): void
    {
        Notification::fake();

        $spot = Destination::factory()->pending()->writtenBy($this->official)->create();

        // Nothing useful said: refused, and the spot is untouched.
        $this->actingAs($this->office)
            ->patch(route('admin.spot-submissions.reject', $spot), ['review_remarks' => ''])
            ->assertSessionHasErrors('review_remarks');

        $this->assertSame(DestinationStatus::Pending, $spot->refresh()->status);

        $this->actingAs($this->office)
            ->patch(route('admin.spot-submissions.reject', $spot), [
                'review_remarks' => 'The pin is on the wrong side of the river.',
            ])->assertSessionHasNoErrors();

        $spot->refresh();
        $this->assertSame(DestinationStatus::Rejected, $spot->status);
        $this->assertSame('The pin is on the wrong side of the river.', $spot->review_remarks);

        Notification::assertSentTo($this->official, TouristSpotDecided::class);
    }

    public function test_the_barangay_is_told_what_to_change_and_can_send_it_again(): void
    {
        Notification::fake();

        $spot = Destination::factory()->sentBack('The pin is on the wrong side of the river.')
            ->writtenBy($this->official)->create();

        $this->actingAs($this->official)
            ->get(route('barangay.spots.edit', $spot))
            ->assertOk()
            ->assertSee('The tourism office sent this back')
            ->assertSee('The pin is on the wrong side of the river.');

        $this->actingAs($this->official)
            ->patch(route('barangay.spots.resubmit', $spot))
            ->assertRedirect(route('barangay.spots.index'));

        $spot->refresh();
        $this->assertSame(DestinationStatus::Pending, $spot->status);
        // The old decision is cleared: it was about text that has since changed.
        $this->assertNull($spot->review_remarks);
        $this->assertNull($spot->reviewed_at);

        Notification::assertSentTo($this->office, TouristSpotSubmitted::class);
    }

    /* What a barangay may not do ------------------------------------------- */

    /**
     * Once the office has published it, the text on the public site is the
     * office's responsibility. A quiet edit afterwards would be a way straight
     * past the review.
     */
    public function test_a_published_spot_can_no_longer_be_edited_by_the_barangay(): void
    {
        $published = Destination::factory()->writtenBy($this->official)->create(['name' => 'Already Public']);

        $this->actingAs($this->official)->get(route('barangay.spots.edit', $published))->assertForbidden();
        $this->actingAs($this->official)
            ->put(route('barangay.spots.update', $published), $this->spotForm(['name' => 'Changed Behind Your Back']))
            ->assertForbidden();

        $this->assertSame('Already Public', $published->refresh()->name);
    }

    public function test_one_barangay_cannot_touch_anothers_submission(): void
    {
        $otherOfficial = User::factory()->barangayOfficial('Tambobong')->create();
        $theirs = Destination::factory()->pending()->writtenBy($otherOfficial)->create(['name' => 'Tambobong Cove']);

        $this->actingAs($this->official)->get(route('barangay.spots.edit', $theirs))->assertForbidden();
        $this->actingAs($this->official)->delete(route('barangay.spots.destroy', $theirs))->assertForbidden();

        // And it is not even listed for them.
        $this->actingAs($this->official)
            ->get(route('barangay.spots.index'))
            ->assertOk()
            ->assertDontSee('Tambobong Cove');
    }

    public function test_a_barangay_official_cannot_publish_anything_themselves(): void
    {
        $spot = Destination::factory()->pending()->writtenBy($this->official)->create();

        $this->actingAs($this->official)
            ->patch(route('admin.spot-submissions.approve', $spot))
            ->assertForbidden();

        $this->actingAs($this->official)
            ->get(route('admin.spot-submissions.index'))
            ->assertForbidden();

        $this->assertSame(DestinationStatus::Pending, $spot->refresh()->status);
    }

    public function test_nobody_else_reaches_the_barangay_area(): void
    {
        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('barangay.dashboard'))->assertForbidden();
            $this->actingAs($outsider)->get(route('barangay.spots.create'))->assertForbidden();
        }
    }

    /* The account itself ---------------------------------------------------- */

    public function test_the_office_creates_the_account_and_ties_it_to_one_barangay(): void
    {
        $this->actingAs($this->office)
            ->post(route('admin.barangay-officials.store'), [
                'first_name' => 'Lito',
                'last_name' => 'Ramos',
                'email' => 'lito.ramos@example.test',
                'phone' => '09171234567',
                'password' => 'Barangay-Pass-2026',
                'password_confirmation' => 'Barangay-Pass-2026',
                'barangay' => 'Malacapas',
            ])
            ->assertRedirect(route('admin.barangay-officials.index'))
            ->assertSessionHasNoErrors();

        $created = User::where('email', 'lito.ramos@example.test')->sole();

        $this->assertSame(UserRole::BarangayOfficial, $created->role);
        $this->assertSame(UserStatus::Active, $created->status);
        $this->assertSame('Malacapas', $created->barangay);
        // The office vouched for the person, not for the address: that is still
        // proved by the code sent on first sign-in.
        $this->assertNull($created->email_verified_at);
    }

    public function test_only_the_office_can_create_one(): void
    {
        foreach ([$this->official, User::factory()->resortOwner()->create(), User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)
                ->get(route('admin.barangay-officials.create'))
                ->assertForbidden();
        }

        $this->assertSame(1, User::barangayOfficials()->count());
    }

    public function test_a_barangay_must_be_one_of_the_eighteen(): void
    {
        $this->actingAs($this->office)
            ->post(route('admin.barangay-officials.store'), [
                'first_name' => 'Lito',
                'last_name' => 'Ramos',
                'email' => 'lito.ramos@example.test',
                'phone' => '09171234567',
                'password' => 'Barangay-Pass-2026',
                'password_confirmation' => 'Barangay-Pass-2026',
                'barangay' => 'Nowhereville',
            ])
            ->assertSessionHasErrors('barangay');

        $this->assertDatabaseMissing('users', ['email' => 'lito.ramos@example.test']);
    }

    public function test_the_office_is_shown_which_barangays_still_have_nobody(): void
    {
        $this->actingAs($this->office)
            ->get(route('admin.barangay-officials.index'))
            ->assertOk()
            // Malacapas is covered by the official in setUp; Tambobong is not.
            ->assertSee('Tambobong')
            ->assertSee('has nobody yet');
    }
}
