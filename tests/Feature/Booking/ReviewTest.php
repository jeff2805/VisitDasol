<?php

namespace Tests\Feature\Booking;

use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Review;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest reviews.
 *
 * Most of these guard one rule: a review must hang from a completed stay
 * belonging to the person writing it. That is what makes the ratings on this
 * site worth reading, so it is the rule worth testing hardest.
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private User $owner;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->room = Room::factory()->for($this->resort)->create();
        $this->tourist = User::factory()->create();
    }

    private function stay(?User $guest = null, string $state = 'completed'): Booking
    {
        $booking = Booking::factory()
            ->for($this->room)
            ->for($this->resort)
            ->for($guest ?? $this->tourist, 'tourist');

        return (match ($state) {
            'completed' => $booking->completed(),
            'confirmed' => $booking->confirmed(),
            default => $booking,
        })->create();
    }

    /* Who may write one --------------------------------------------------- */

    public function test_a_guest_can_review_a_stay_they_finished(): void
    {
        $booking = $this->stay();

        $this->actingAs($this->tourist)
            ->post(route('tourist.reviews.store', $booking), [
                'rating' => 5,
                'comment' => 'The beach was spotless and the staff were kind.',
            ])->assertRedirect(route('tourist.bookings.show', $booking));

        $review = Review::sole();

        $this->assertSame(5, $review->rating);
        // Taken from the booking, never from the form.
        $this->assertSame($this->resort->id, $review->resort_id);
        $this->assertSame($this->tourist->id, $review->user_id);
        $this->assertSame(ReviewStatus::Published, $review->status);
    }

    public function test_a_stay_that_has_not_finished_cannot_be_reviewed(): void
    {
        $booking = $this->stay(state: 'confirmed');

        $this->actingAs($this->tourist)
            ->post(route('tourist.reviews.store', $booking), ['rating' => 5])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_request_nobody_confirmed_cannot_be_reviewed(): void
    {
        $booking = $this->stay(state: 'pending');

        $this->actingAs($this->tourist)
            ->post(route('tourist.reviews.store', $booking), ['rating' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_nobody_can_review_somebody_elses_stay(): void
    {
        $booking = $this->stay();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('tourist.reviews.store', $booking), ['rating' => 1, 'comment' => 'Never been here.'])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_one_review_per_stay(): void
    {
        $booking = $this->stay();

        $this->actingAs($this->tourist)->post(route('tourist.reviews.store', $booking), ['rating' => 4]);
        $this->actingAs($this->tourist)->post(route('tourist.reviews.store', $booking), ['rating' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame(4, Review::sole()->rating);
    }

    public function test_a_guest_who_stayed_twice_may_review_twice(): void
    {
        $first = $this->stay();
        $second = $this->stay();

        $this->actingAs($this->tourist)->post(route('tourist.reviews.store', $first), ['rating' => 5]);
        $this->actingAs($this->tourist)->post(route('tourist.reviews.store', $second), ['rating' => 3]);

        $this->assertDatabaseCount('reviews', 2);
    }

    /* What they may write -------------------------------------------------- */

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $booking = $this->stay();

        foreach ([0, 6, -1, 99] as $bad) {
            $this->actingAs($this->tourist)
                ->post(route('tourist.reviews.store', $booking), ['rating' => $bad])
                ->assertSessionHasErrors('rating');
        }

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_the_author_may_correct_their_own_review(): void
    {
        $booking = $this->stay();
        $this->actingAs($this->tourist)->post(route('tourist.reviews.store', $booking), ['rating' => 2]);

        $this->actingAs($this->tourist)
            ->put(route('tourist.reviews.update', $booking), [
                'rating' => 4,
                'comment' => 'On reflection the stay was better than I first said.',
            ])->assertRedirect();

        $this->assertSame(4, Review::sole()->rating);
    }

    /* Averages -------------------------------------------------------------- */

    public function test_the_average_counts_only_published_reviews(): void
    {
        Review::factory()->rated(5)->create(['resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id]);
        Review::factory()->rated(1)->hidden()->create(['resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id]);

        $resort = Resort::query()->withRating()->find($this->resort->id);

        // The hidden 1-star must not drag the average down to 3.
        $this->assertSame(5.0, round((float) $resort->rating_average, 1));
        $this->assertSame(1, $resort->rating_count);
    }

    public function test_the_resort_page_shows_published_reviews_and_hides_the_rest(): void
    {
        Review::factory()->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id,
            'user_id' => $this->tourist->id, 'comment' => 'Lovely quiet cove.',
        ]);
        Review::factory()->hidden()->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id,
            'user_id' => $this->tourist->id, 'comment' => 'This text was taken down.',
        ]);

        $this->get(route('resorts.show', $this->resort))
            ->assertOk()
            ->assertSee('Lovely quiet cove.')
            ->assertDontSee('This text was taken down.');
    }

    public function test_only_a_first_name_and_initial_are_published(): void
    {
        $guest = User::factory()->create(['first_name' => 'Bea', 'last_name' => 'Santos']);

        Review::factory()->create([
            'resort_id' => $this->resort->id,
            'booking_id' => $this->stay($guest)->id,
            'user_id' => $guest->id,
            'comment' => 'A fine stay by the bay.',
        ]);

        $this->get(route('resorts.show', $this->resort))
            ->assertOk()
            ->assertSee('Bea S.')
            ->assertDontSee('Bea Santos');
    }

    /* The resort's answer ---------------------------------------------------- */

    public function test_an_owner_may_reply_to_a_review_of_their_own_resort(): void
    {
        $review = Review::factory()->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id,
        ]);

        $this->actingAs($this->owner)
            ->post(route('owner.reviews.reply', $review), ['owner_reply' => 'Thank you, we hope to see you again.'])
            ->assertRedirect();

        $this->assertSame('Thank you, we hope to see you again.', $review->fresh()->owner_reply);
    }

    public function test_an_owner_cannot_reply_to_another_resorts_review(): void
    {
        $other = User::factory()->resortOwner()->create();
        $review = Review::factory()->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id,
        ]);

        $this->actingAs($other)
            ->post(route('owner.reviews.reply', $review), ['owner_reply' => 'Not my resort at all.'])
            ->assertForbidden();

        $this->assertNull($review->fresh()->owner_reply);
    }

    public function test_an_owner_cannot_hide_a_bad_review(): void
    {
        $review = Review::factory()->rated(1)->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id,
        ]);

        // Being able to bury criticism would make every other rating worthless.
        $this->actingAs($this->owner)
            ->patch(route('admin.reviews.hide', $review), ['hidden_reason' => 'I do not like it.'])
            ->assertForbidden();

        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
    }

    /* Moderation -------------------------------------------------------------- */

    public function test_the_office_can_hide_a_review_and_put_it_back(): void
    {
        $office = User::factory()->superAdmin()->create();
        $review = Review::factory()->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id,
        ]);

        $this->actingAs($office)
            ->patch(route('admin.reviews.hide', $review), ['hidden_reason' => 'Abusive language.'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(ReviewStatus::Hidden, $review->status);
        $this->assertSame('Abusive language.', $review->hidden_reason);
        $this->assertSame($office->id, $review->hidden_by);
        // Hiding never deletes: the row survives so the decision can be reviewed.
        $this->assertDatabaseCount('reviews', 1);

        $this->actingAs($office)->patch(route('admin.reviews.restore', $review))->assertRedirect();

        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
        $this->assertNull($review->fresh()->hidden_reason);
    }

    public function test_hiding_a_review_needs_a_reason(): void
    {
        $office = User::factory()->superAdmin()->create();
        $review = Review::factory()->create([
            'resort_id' => $this->resort->id, 'booking_id' => $this->stay()->id, 'user_id' => $this->tourist->id,
        ]);

        $this->actingAs($office)
            ->patch(route('admin.reviews.hide', $review), ['hidden_reason' => ''])
            ->assertSessionHasErrors('hidden_reason');

        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
    }
}
