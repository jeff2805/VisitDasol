<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Destination;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Support\TableSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sorting the office lists.
 *
 * Every one of these lists arrived newest first and offered no way to ask for
 * any other order, so "who registered earliest" or "which reservation is the
 * largest" meant reading every page.
 */
class TableSortTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = User::factory()->superAdmin()->create();
    }

    /** The order the names appear in the rendered page. */
    private function orderOf(TestResponse $response, array $needles): array
    {
        $html = $response->assertOk()->getContent();

        $found = [];

        foreach ($needles as $needle) {
            $at = strpos($html, $needle);

            if ($at !== false) {
                $found[$needle] = $at;
            }
        }

        asort($found);

        return array_keys($found);
    }

    /* The mechanism ------------------------------------------------------- */

    public function test_an_unknown_column_or_direction_falls_back_to_the_default(): void
    {
        $columns = ['name' => 'name', 'added' => '-created_at'];

        foreach ([
            ['sort' => 'name', 'dir' => 'desc'],
            ['sort' => 'drop table users', 'dir' => 'asc'],
            ['sort' => 'name', 'dir' => '; delete from users'],
            [],
        ] as $query) {
            $sort = TableSort::fromRequest(Request::create('/', 'GET', $query), $columns, 'added');

            $this->assertContains($sort->key, ['name', 'added']);
            $this->assertContains($sort->direction, ['asc', 'desc']);
        }

        // Nonsense in, the page's own default out.
        $nonsense = TableSort::fromRequest(Request::create('/', 'GET', ['sort' => 'nope']), $columns, 'added');
        $this->assertSame('added', $nonsense->key);
        $this->assertSame('desc', $nonsense->direction, 'A "-" column should start at the big end.');
    }

    /**
     * A column marked "-" starts at its big end, and a plain one starts at A.
     * Dates and money are wanted newest and largest first; names are not.
     */
    public function test_each_column_starts_the_way_it_reads_best(): void
    {
        $columns = ['name' => 'name', 'added' => '-created_at'];
        $request = Request::create('/', 'GET');

        $onName = TableSort::fromRequest($request, $columns, 'name');
        $this->assertSame('asc', $onName->direction);
        // Clicking the column in use turns it round.
        $this->assertSame('desc', $onName->nextDirection('name'));
        // Clicking a different one starts that column at its own natural end.
        $this->assertSame('desc', $onName->nextDirection('added'));

        $this->assertSame('ascending', $onName->ariaSort('name'));
        $this->assertNull($onName->ariaSort('added'), 'Only the column in use carries aria-sort.');
    }

    /* The lists ----------------------------------------------------------- */

    public function test_the_accounts_list_sorts_by_name_both_ways(): void
    {
        User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Abalos']);
        User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Mendoza']);
        User::factory()->create(['first_name' => 'Cris', 'last_name' => 'Zamora']);

        $up = $this->actingAs($this->office)
            ->get(route('admin.users.index', ['sort' => 'name', 'dir' => 'asc']));

        $this->assertSame(
            ['Ana Abalos', 'Ben Mendoza', 'Cris Zamora'],
            $this->orderOf($up, ['Ana Abalos', 'Ben Mendoza', 'Cris Zamora'])
        );

        $down = $this->actingAs($this->office)
            ->get(route('admin.users.index', ['sort' => 'name', 'dir' => 'desc']));

        $this->assertSame(
            ['Cris Zamora', 'Ben Mendoza', 'Ana Abalos'],
            $this->orderOf($down, ['Ana Abalos', 'Ben Mendoza', 'Cris Zamora'])
        );

        // The heading says which column is in use, and which way.
        $up->assertSee('aria-sort="ascending"', false);
        $down->assertSee('aria-sort="descending"', false);
    }

    /** Sorting must not throw away the filters already in force. */
    public function test_sorting_keeps_the_filters_and_resets_the_page(): void
    {
        User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Abalos', 'email' => 'ana@example.test']);
        User::factory()->resortOwner()->create(['first_name' => 'Ben', 'last_name' => 'Mendoza']);

        $response = $this->actingAs($this->office)
            ->get(route('admin.users.index', ['q' => 'ana', 'sort' => 'name', 'dir' => 'asc', 'page' => 1]));

        $response->assertOk()
            ->assertSee('Ana Abalos')
            ->assertDontSee('Ben Mendoza');

        // Every sort link carries the search with it, and drops the page so a
        // re-sorted list does not open halfway down the old order.
        $html = $response->getContent();
        $this->assertStringContainsString('q=ana', $html);
        $this->assertStringNotContainsString('page=1&amp;sort=', $html);
    }

    public function test_the_resort_list_sorts_by_room_count(): void
    {
        $quiet = Resort::factory()->active()->create(['name' => 'Quiet Cove Resort']);
        $busy = Resort::factory()->active()->create(['name' => 'Busy Bay Resort']);

        Room::factory()->for($quiet)->create();
        Room::factory()->count(4)->for($busy)->create();

        // rooms_count is an alias from withCount(), not a column on any table.
        // Qualifying it would build SQL for resorts.rooms_count and fail.
        $response = $this->actingAs($this->office)
            ->get(route('admin.resorts.index', ['sort' => 'rooms', 'dir' => 'desc']));

        $this->assertSame(
            ['Busy Bay Resort', 'Quiet Cove Resort'],
            $this->orderOf($response, ['Busy Bay Resort', 'Quiet Cove Resort'])
        );
    }

    public function test_the_reservation_list_sorts_by_amount(): void
    {
        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create();
        $tourist = User::factory()->create();

        $small = Booking::factory()->for($resort)->for($room)->for($tourist, 'tourist')
            ->create(['total_amount' => 900]);
        $large = Booking::factory()->for($resort)->for($room)->for($tourist, 'tourist')
            ->create(['total_amount' => 7500]);

        $response = $this->actingAs($this->office)
            ->get(route('admin.bookings.index', ['sort' => 'total', 'dir' => 'desc']));

        $this->assertSame(
            [$large->booking_reference, $small->booking_reference],
            $this->orderOf($response, [$small->booking_reference, $large->booking_reference])
        );
    }

    /**
     * A spot with no photograph is the one the office has to chase, so that
     * column is worth putting at the top.
     */
    public function test_the_tourist_spot_list_sorts_by_how_many_photos(): void
    {
        Destination::factory()->create(['name' => 'Bare Rock Point']);
        $shown = Destination::factory()->create(['name' => 'Well Shot Cove']);

        // There is no image factory; the gallery is written straight in, the
        // way the other gallery tests do it.
        foreach (range(1, 3) as $i) {
            $shown->images()->create(['path' => "destinations/{$shown->id}/gallery/shot-{$i}.jpg"]);
        }

        $response = $this->actingAs($this->office)
            ->get(route('admin.destinations.index', ['sort' => 'photos', 'dir' => 'asc']));

        $this->assertSame(
            ['Bare Rock Point', 'Well Shot Cove'],
            $this->orderOf($response, ['Bare Rock Point', 'Well Shot Cove']),
            'Ascending by photo count should put the spot with none first.'
        );
    }

    /**
     * The application queue answers "who has waited longest" by default, and
     * says so, rather than arriving newest first like everything else.
     */
    public function test_the_resort_queue_leads_with_the_longest_wait(): void
    {
        $owner = User::factory()->resortOwner()->create();

        // The factory already makes a pending resort, which is what an
        // application is.
        Resort::factory()->for($owner, 'owner')->create([
            'name' => 'Waited Since Monday', 'submitted_at' => now()->subDays(9),
        ]);
        Resort::factory()->for(User::factory()->resortOwner(), 'owner')->create([
            'name' => 'Arrived This Morning', 'submitted_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->office)->get(route('admin.resort-applications.index'));

        $this->assertSame(
            ['Waited Since Monday', 'Arrived This Morning'],
            $this->orderOf($response, ['Waited Since Monday', 'Arrived This Morning'])
        );

        $response->assertSee('aria-sort="ascending"', false);
    }

    /**
     * The owner list only ever grows: every approved application lands there
     * and never leaves, so without a search the only way to find somebody was
     * to read every page.
     */
    public function test_the_owner_list_can_be_searched(): void
    {
        $wanted = User::factory()->resortOwner()->create([
            'first_name' => 'Marites', 'last_name' => 'Calderon', 'email' => 'marites@example.test',
        ]);
        Resort::factory()->for($wanted, 'owner')->active()->create(['name' => 'Coral Edge Resort']);

        $other = User::factory()->resortOwner()->create([
            'first_name' => 'Rogelio', 'last_name' => 'Santos', 'email' => 'rogelio@example.test',
        ]);
        Resort::factory()->for($other, 'owner')->active()->create(['name' => 'Palm Shade Resort']);

        // By person, by email, and by the resort they manage.
        foreach (['Calderon', 'marites@', 'Coral Edge'] as $term) {
            $this->actingAs($this->office)
                ->get(route('admin.owners.index', ['q' => $term]))
                ->assertOk()
                ->assertSee('Marites Calderon')
                ->assertDontSee('Rogelio Santos');
        }

        // And a search that matches nobody says so rather than looking broken.
        $this->actingAs($this->office)
            ->get(route('admin.owners.index', ['q' => 'nobody at all']))
            ->assertOk()
            ->assertSee('No owners match');
    }

    /**
     * There is a way back to the list's own order.
     *
     * Several of these lists are curated rather than alphabetical — featured
     * resorts first, the longest-waiting application first. Once a column had
     * been clicked there was no way to return to that, because "Clear" only
     * appeared when a filter was in force.
     */
    public function test_a_sorted_list_offers_a_way_back_to_its_own_order(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);

        $this->actingAs($this->office)
            ->get(route('admin.resorts.index'))
            ->assertOk()
            ->assertDontSee('>Clear<', false);

        $this->actingAs($this->office)
            ->get(route('admin.resorts.index', ['sort' => 'name', 'dir' => 'desc']))
            ->assertOk()
            ->assertSee('>Clear<', false);
    }

    /**
     * Rows with the same value must not shuffle between pages.
     *
     * Without a tie-break on the primary key, MySQL is free to return equal
     * rows in any order, and then a row appears on two pages while another is
     * never shown at all.
     */
    public function test_rows_with_equal_values_keep_a_fixed_order_across_pages(): void
    {
        // All registered at the same moment, so only the tie-break separates them.
        $this->travelTo('2026-05-01 09:00:00');
        User::factory()->count(30)->create();

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $html = $this->actingAs($this->office)
                ->get(route('admin.users.index', ['sort' => 'registered', 'dir' => 'desc', 'page' => $page]))
                ->assertOk()
                ->getContent();

            preg_match_all('/admin\/users\/(\d+)/', $html, $found);
            $seen = array_merge($seen, $found[1]);
        }

        $this->assertSame(
            count($seen),
            count(array_unique($seen)),
            'A row was shown on more than one page, so the ordering is not stable.'
        );
    }
}
