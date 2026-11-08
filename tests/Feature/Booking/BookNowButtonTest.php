<?php

namespace Tests\Feature\Booking;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Book now" on the rooms listing.
 *
 * ONLY THE ROOM ID TRAVELS. The form loads the room from the database
 * and takes its resort, name, price and capacity from there, so none of
 * them can be edited on the way — a price in a query string is a price
 * a visitor can change. The dates and party are the visitor's own
 * answers and are checked against the room again when the request is
 * saved.
 */
class BookNowButtonTest extends TestCase
{
    use RefreshDatabase;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($owner, 'owner')->active()->create();
    }

    private function room(array $state = []): Room
    {
        return Room::factory()->for($this->resort)->create($state + [
            'capacity' => 4, 'total_rooms' => 2, 'price_per_night' => 1500,
        ]);
    }

    private function listing(array $query = []): string
    {
        return $this->get(route('resorts.index', $query))->assertOk()->getContent();
    }

    /** Every Book now link on the page, in order. */
    private function bookLinks(string $html): array
    {
        preg_match_all(
            '/<a class="btn btn-sm btn-accent" href="([^"]+)"[^>]*>\s*<i[^>]*><\/i>\s*Book now/s',
            $html,
            $m
        );

        return array_map(html_entity_decode(...), $m[1]);
    }

    /* The buttons --------------------------------------------------------- */

    public function test_each_card_offers_book_now_and_view_details(): void
    {
        $rooms = collect([
            $this->room(['name' => 'Seaview Room']),
            $this->room(['name' => 'Garden Cottage']),
            $this->room(['name' => 'Family Suite']),
        ]);

        $html = $this->listing();

        // One Book now per card, not just on the first.
        $this->assertCount(3, $this->bookLinks($html));

        // And View details is untouched, one per card.
        $this->assertSame(3, preg_match_all('/btn-outline-primary[^>]*>View details</', $html));

        foreach ($rooms as $room) {
            $this->assertStringContainsString('room='.$room->id, $html, "No link for {$room->name}.");
        }
    }

    /** View details still goes to the resort page, as it always did. */
    public function test_view_details_still_opens_the_resort_page(): void
    {
        $this->room();

        $this->assertStringContainsString(
            '<a class="btn btn-sm btn-outline-primary" href="'.route('resorts.show', $this->resort).'">View details</a>',
            $this->listing()
        );
    }

    /* What the link carries ------------------------------------------------- */

    /**
     * The searched dates follow the guest in, so the form does not ask
     * the question they have already answered.
     */
    public function test_the_link_carries_the_dates_the_visitor_searched(): void
    {
        $room = $this->room();

        $link = $this->bookLinks($this->listing([
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-13',
            'guests' => 3,
        ]))[0];

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame((string) $room->id, $query['room']);
        $this->assertSame('2030-07-10', $query['check_in']);
        $this->assertSame('2030-07-13', $query['check_out']);
        $this->assertSame('3', $query['guests']);
    }

    /**
     * NOTHING THE SERVER WILL TRUST IS IN THE URL.
     *
     * The room's price, name and capacity are read from the database by
     * the form. Putting them in the link would be putting them within
     * reach of whoever is reading it.
     */
    public function test_the_link_carries_no_price_name_or_capacity(): void
    {
        $room = $this->room(['name' => 'Seaview Room', 'price_per_night' => 1500, 'capacity' => 4]);

        $link = $this->bookLinks($this->listing())[0];

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame(
            ['room', 'check_in', 'check_in_time', 'check_out', 'check_out_time', 'guests'],
            array_keys($query)
        );

        foreach (['price', 'amount', 'capacity', 'name', 'resort'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $query);
        }

        $this->assertStringNotContainsString('1500', $link);
    }

    /**
     * A visitor who has searched no dates still lands on a usable form.
     *
     * Without them the form refuses to draw — it says to choose dates on
     * the resort page first — which would make this button a dead end on
     * the listing's default view.
     */
    public function test_a_visitor_with_no_dates_still_gets_a_working_form(): void
    {
        $room = $this->room();

        $link = $this->bookLinks($this->listing())[0];

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame('2030-06-02', $query['check_in']);
        $this->assertSame('2030-06-04', $query['check_out']);

        // And following it reaches the form itself, not the "choose dates" notice.
        $this->actingAs(User::factory()->create())
            ->get($link)
            ->assertOk()
            ->assertSee('Request a room')
            ->assertDontSee('Choose your dates on the resort page first');
    }

    /* Following it ------------------------------------------------------------ */

    /** The form opens on the right room, priced from the database. */
    public function test_following_the_link_opens_that_rooms_booking_form(): void
    {
        $this->room(['name' => 'Garden Cottage', 'price_per_night' => 900]);
        $wanted = $this->room(['name' => 'Seaview Suite', 'price_per_night' => 2500, 'capacity' => 6]);

        $links = $this->bookLinks($this->listing(['check_in' => '2030-07-10', 'check_out' => '2030-07-12']));

        // The one whose link names the room we want.
        $link = collect($links)->first(fn ($url) => str_contains($url, 'room='.$wanted->id));

        $html = $this->actingAs(User::factory()->create())
            ->get($link)->assertOk()->getContent();

        $this->assertStringContainsString('Seaview Suite', $html);
        $this->assertStringContainsString('value="'.$wanted->id.'"', $html);

        // Two nights at the room's own stored rate.
        $this->assertStringContainsString('5,000.00', $html);
    }

    /**
     * A SIGNED-OUT VISITOR KEEPS THE ROOM THEY PICKED.
     *
     * The booking form is behind auth, so the link bounces to sign-in;
     * what matters is that the room and dates survive the round trip.
     */
    public function test_a_signed_out_visitor_is_returned_to_the_same_room(): void
    {
        $room = $this->room();

        $link = $this->bookLinks($this->listing([
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-13',
        ]))[0];

        $this->get($link)->assertRedirect(route('login'));

        $tourist = User::factory()->create(['email' => 'bea@example.com']);

        $target = $this->post(route('login'), [
            'email' => 'bea@example.com',
            'password' => 'password',
        ])->headers->get('Location');

        $this->assertStringContainsString('/tourist/bookings/create', $target);

        foreach (['room='.$room->id, 'check_in=2030-07-10', 'check_out=2030-07-13'] as $part) {
            $this->assertStringContainsString($part, urldecode((string) $target));
        }

        $this->assertAuthenticatedAs($tourist);
    }

    /** A fully booked room is still reachable; the form does the checking. */
    public function test_a_fully_booked_room_still_offers_the_button(): void
    {
        $room = $this->room(['total_rooms' => 1]);

        \App\Models\Booking::factory()->for($room)
            ->forStay('2030-07-10', '2030-07-13')
            ->confirmed()->create(['rooms_booked' => 1]);

        $html = $this->listing(['check_in' => '2030-07-10', 'check_out' => '2030-07-13']);

        $this->assertStringContainsString('Fully booked for your dates', $html);
        $this->assertCount(1, $this->bookLinks($html));
    }
}
