<?php

namespace Tests\Feature\Reports;

use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-15 09:00:00');
        $this->reports = app(ReportService::class);
        $this->mto = User::factory()->superAdmin()->create();
    }

    private function period(string $from = '2030-06-01', string $to = '2030-06-30'): array
    {
        return [CarbonImmutable::parse($from), CarbonImmutable::parse($to)];
    }

    /* The numbers ---------------------------------------------------------- */

    public function test_only_real_stays_count_towards_guests_and_value(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create(['total_rooms' => 5, 'price_per_night' => 1000]);

        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()->create(['guests' => 2]);
        Booking::factory()->for($room)->forStay('2030-06-14', '2030-06-15')->completed()->create(['guests' => 3]);
        Booking::factory()->for($room)->forStay('2030-06-20', '2030-06-22')->cancelled()->create(['guests' => 9]);
        Booking::factory()->for($room)->forStay('2030-06-25', '2030-06-26')->rejected()->create(['guests' => 9]);
        Booking::factory()->for($room)->forStay('2030-06-28', '2030-06-29')->create(['guests' => 4]);

        [$from, $to] = $this->period();
        $summary = $this->reports->summary($from, $to);

        $this->assertSame(5, $summary['reservations']);
        $this->assertSame(2, $summary['confirmed_or_completed']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(2, $summary['cancelled_or_declined']);
        $this->assertSame(5, $summary['guests'], 'Only confirmed and completed guests count.');
        $this->assertSame(50, $summary['cancellation_rate'], '2 of 4 decided reservations fell through.');
    }

    public function test_room_nights_are_clipped_to_the_period(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create(['total_rooms' => 5]);

        // Starts in May, ends in June: only the June nights belong to June.
        Booking::factory()->for($room)->forStay('2030-05-29', '2030-06-03')->confirmed()->create();

        [$from, $to] = $this->period();

        $this->assertSame(2, $this->reports->roomNights($from, $to), 'June 1 and 2 only.');
        $this->assertSame(3, $this->reports->roomNights(CarbonImmutable::parse('2030-05-01'), CarbonImmutable::parse('2030-05-31')));
    }

    public function test_room_nights_count_each_room_of_a_multi_room_booking(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create(['total_rooms' => 5]);
        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-13', 3)->confirmed()->create();

        [$from, $to] = $this->period();

        $this->assertSame(9, $this->reports->roomNights($from, $to), '3 nights x 3 rooms.');
    }

    public function test_occupancy_compares_booked_nights_with_rooms_on_offer(): void
    {
        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['total_rooms' => 1]);

        // 10 of the 30 nights in June are booked for the only room.
        Booking::factory()->for($room)->forStay('2030-06-01', '2030-06-11')->confirmed()->create();

        [$from, $to] = $this->period();

        $this->assertSame(33, $this->reports->occupancyRate($from, $to), '10 of 30 nights.');
        $this->assertSame(0, $this->reports->occupancyRate(
            CarbonImmutable::parse('2030-07-01'), CarbonImmutable::parse('2030-07-31')
        ));
    }

    public function test_the_monthly_trend_includes_quiet_months(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create();
        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()->create(['guests' => 2]);

        $trend = $this->reports->monthlyTrend(CarbonImmutable::parse('2030-04-01'), CarbonImmutable::parse('2030-06-30'));

        $this->assertCount(3, $trend);
        $this->assertSame(['Apr 2030', 'May 2030', 'Jun 2030'], $trend->pluck('label')->all());
        $this->assertSame([0, 0, 1], $trend->pluck('reservations')->all());
    }

    public function test_reports_can_be_read_by_stay_dates_or_by_request_date(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create();

        // Requested in June, staying in August.
        $booking = Booking::factory()->for($room)->forStay('2030-08-10', '2030-08-12')->confirmed()->create();
        $booking->forceFill(['created_at' => '2030-06-05 10:00:00'])->save();

        [$from, $to] = $this->period();

        $this->assertSame(0, $this->reports->summary($from, $to)['reservations'], 'Not staying in June.');
        $this->assertSame(1, $this->reports->summary($from, $to, null, 'requested')['reservations'], 'But booked in June.');
    }

    public function test_the_busiest_resorts_are_ranked(): void
    {
        $busy = Resort::factory()->active()->create(['name' => 'Busy Resort']);
        $quiet = Resort::factory()->active()->create(['name' => 'Quiet Resort']);

        Booking::factory()->count(3)->for(Room::factory()->for($busy))->forStay('2030-06-10', '2030-06-11')->confirmed()->create();
        Booking::factory()->for(Room::factory()->for($quiet))->forStay('2030-06-10', '2030-06-11')->confirmed()->create();
        Booking::factory()->count(5)->for(Room::factory()->for($quiet))->forStay('2030-06-12', '2030-06-13')->cancelled()->create();

        [$from, $to] = $this->period();
        $top = $this->reports->topResorts($from, $to);

        $this->assertSame('Busy Resort', $top->first()['name']);
        $this->assertSame(3, $top->first()['reservations']);
        $this->assertSame(1, $top->last()['reservations'], 'Cancelled stays do not inflate the ranking.');
    }

    public function test_one_resort_can_be_reported_on_alone(): void
    {
        $mine = Resort::factory()->active()->create();
        $other = Resort::factory()->active()->create();

        Booking::factory()->for(Room::factory()->for($mine))->forStay('2030-06-10', '2030-06-12')->confirmed()->create();
        Booking::factory()->count(4)->for(Room::factory()->for($other))->forStay('2030-06-10', '2030-06-12')->confirmed()->create();

        [$from, $to] = $this->period();

        $this->assertSame(1, $this->reports->summary($from, $to, $mine->id)['reservations']);
        $this->assertSame(5, $this->reports->summary($from, $to)['reservations']);
    }

    /* The pages ------------------------------------------------------------ */

    public function test_the_office_report_page_renders_with_figures(): void
    {
        $room = Room::factory()->for(Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']))->create(['price_per_night' => 2000]);
        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()->create(['guests' => 2]);

        $this->actingAs($this->mto)->get(route('admin.reports.index', ['from' => '2030-06-01', 'to' => '2030-06-30']))
            ->assertOk()
            ->assertSee('Tourism statistics')
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Occupancy')
            ->assertSee('data-chart', false)
            ->assertViewHas('summary', fn (array $summary) => $summary['confirmed_or_completed'] === 1 && $summary['guests'] === 2);
    }

    /**
     * Reading the report by request date joins resorts and users, and both of
     * those carry a created_at of their own. An unqualified column there made
     * the whole page fail with "Column 'created_at' in where clause is
     * ambiguous", which is easy to miss because the default view works.
     */
    public function test_the_report_page_works_when_read_by_request_date(): void
    {
        $room = Room::factory()->for(Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']))->create();
        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()->create();

        $this->actingAs($this->mto)
            ->get(route('admin.reports.index', ['basis' => 'requested']))
            ->assertOk()
            ->assertSee('Reservations in this period');
    }

    /** The figures say how many; the office also has to see which ones. */
    public function test_the_report_page_lists_the_reservations_behind_the_figures(): void
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $room = Room::factory()->for($resort)->create(['name' => 'Seaview Deluxe']);

        Booking::factory()->for($room)->for($resort)->forStay('2030-06-10', '2030-06-12')->confirmed()
            ->create(['contact_name' => 'Bea Santos', 'contact_phone' => '09171234567']);

        $this->actingAs($this->mto)
            ->get(route('admin.reports.index', ['from' => '2030-06-01', 'to' => '2030-06-30']))
            ->assertOk()
            ->assertSee('Reservations in this period')
            ->assertSee('Bea Santos')
            ->assertSee('09171234567')
            ->assertSee('Seaview Deluxe');
    }

    public function test_a_silly_date_range_falls_back_instead_of_breaking(): void
    {
        $this->actingAs($this->mto)->get(route('admin.reports.index', ['from' => 'banana', 'to' => '2030-13-45']))
            ->assertOk();

        // Reversed dates are swapped rather than returning nothing.
        $this->actingAs($this->mto)->get(route('admin.reports.index', ['from' => '2030-06-30', 'to' => '2030-06-01']))
            ->assertOk()
            ->assertViewHas('from', fn ($from) => $from->toDateString() === '2030-06-01');
    }

    /**
     * The export is the DOT accommodation return: one line per resort,
     * counting arrivals, nights and where guests came from.
     *
     * It used to be a list of reservations with each guest's name and
     * phone number on it, and this test still asserted that. The
     * format changed to the statistical return and the test did not,
     * so it had been failing ever since.
     *
     * The aggregate form is also the better one to have. A downloaded
     * spreadsheet gets forwarded, and a return that counts people
     * cannot leak one.
     */
    public function test_the_csv_download_is_the_accommodation_return(): void
    {
        $room = Room::factory()->for(Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']))->create();

        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()
            ->create(['contact_name' => 'Bea Santos', 'contact_phone' => '09171234567']);

        Booking::factory()->for($room)->forStay('2030-09-10', '2030-09-12')->confirmed()
            ->create(['contact_name' => 'Later Guest']);

        $response = $this->actingAs($this->mto)->get(route('admin.reports.export', ['from' => '2030-06-01', 'to' => '2030-06-30']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // The return's own columns, in the order the DOT form wants.
        $this->assertStringContainsString('Resort,GCheckin,O-night,R-used,Male,Female', $csv);
        $this->assertStringContainsString('Salt Breeze Resort', $csv);

        // Excel reads a CSV as the system codepage without this.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        /*
         * NO GUEST REACHES THIS FILE. The return counts people; it
         * does not name them. A spreadsheet that did would hand a
         * guest's phone number to whoever it was forwarded to.
         */
        $this->assertStringNotContainsString('Bea Santos', $csv);
        $this->assertStringNotContainsString('09171234567', $csv);

        // And the stay outside the chosen period is not counted either.
        $this->assertStringNotContainsString('Later Guest', $csv);
    }

    /**
     * A downloaded file gets forwarded. A spreadsheet naming each resort's
     * takings beside each guest hands that resort's trade to whoever opens it,
     * so the export carries no rates and no totals.
     */
    public function test_the_csv_download_carries_no_money(): void
    {
        $room = Room::factory()->for(Resort::factory()->active()->create())->create(['price_per_night' => 7500]);
        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()->create();

        $csv = $this->actingAs($this->mto)
            ->get(route('admin.reports.export', ['from' => '2030-06-01', 'to' => '2030-06-30']))
            ->streamedContent();

        $this->assertStringNotContainsString('Rate per night', $csv);
        $this->assertStringNotContainsString('Total', $csv);
        $this->assertStringNotContainsString('7500', $csv);
        $this->assertStringNotContainsString('15000', $csv);
    }

    /**
     * Where the line falls on money.
     *
     * The tourism office is there to count visitors and room use. What each
     * resort takes is that resort's own trade, and the office report names
     * every resort, so a takings column there would set out the earnings of
     * every business in town. A resort owner still sees their own figures.
     */
    public function test_the_office_report_shows_no_takings(): void
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $room = Room::factory()->for($resort)->create(['price_per_night' => 7500]);
        Booking::factory()->for($room)->for($resort)->forStay('2030-06-10', '2030-06-12')->confirmed()->create();

        $response = $this->actingAs($this->mto)
            ->get(route('admin.reports.index', ['from' => '2030-06-01', 'to' => '2030-06-30']))
            ->assertOk()
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Occupancy');

        // 2 nights at 7,500 = 15,000. Neither the rate nor the total belongs here.
        $response->assertDontSee('Estimated value');
        $response->assertDontSee('15,000');
        $response->assertDontSee('7,500');
    }

    public function test_an_owner_still_sees_their_own_takings(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();
        $room = Room::factory()->for($resort)->create(['price_per_night' => 7500]);
        Booking::factory()->for($room)->for($resort)->forStay('2030-06-10', '2030-06-12')->confirmed()->create();

        // Their own business, on their own page.
        $this->actingAs($owner)
            ->get(route('owner.statistics', ['from' => '2030-06-01', 'to' => '2030-06-30']))
            ->assertOk()
            ->assertSee('15,000');
    }

    public function test_owners_and_tourists_cannot_open_the_office_reports(): void
    {
        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.reports.index'))->assertForbidden();
            $this->actingAs($intruder)->get(route('admin.reports.export'))->assertForbidden();
        }

        $this->post(route('logout'));
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    }

    /* Owner statistics ----------------------------------------------------- */

    public function test_an_owner_sees_statistics_for_their_resort_only(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $mine = Resort::factory()->for($owner, 'owner')->active()->create(['name' => 'My Resort']);
        $room = Room::factory()->for($mine)->create(['price_per_night' => 1000]);
        Booking::factory()->for($room)->forStay('2030-06-10', '2030-06-12')->confirmed()->create(['guests' => 2]);

        // Another resort's busy month must not leak in.
        $other = Resort::factory()->active()->create();
        Booking::factory()->count(6)->for(Room::factory()->for($other))->forStay('2030-06-10', '2030-06-12')->confirmed()->create();

        $this->actingAs($owner)->get(route('owner.statistics'))
            ->assertOk()
            ->assertSee('My Resort')
            ->assertSee('Expected income')
            ->assertViewHas('summary', fn (array $summary) => $summary['confirmed_or_completed'] === 1 && $summary['guests'] === 2);
    }

    public function test_an_owner_without_a_resort_is_sent_to_register_one(): void
    {
        $this->actingAs(User::factory()->resortOwner()->create())
            ->get(route('owner.statistics'))
            ->assertRedirect(route('owner.resort.create'));
    }

    public function test_the_period_buttons_change_the_range(): void
    {
        $owner = User::factory()->resortOwner()->create();
        Resort::factory()->for($owner, 'owner')->active()->create();

        $this->actingAs($owner)->get(route('owner.statistics', ['months' => 3]))
            ->assertOk()
            ->assertViewHas('from', fn ($from) => $from->toDateString() === '2030-04-01');

        // A value that is not offered falls back to a year.
        $this->actingAs($owner)->get(route('owner.statistics', ['months' => 999]))
            ->assertOk()
            ->assertViewHas('months', 12);
    }
}
