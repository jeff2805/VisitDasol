<?php

namespace Tests\Feature\Admin;

use App\Enums\VisitorOrigin;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * The Department of Tourism's monthly accommodation return.
 *
 * Every municipality files one. Until this existed, the office could get
 * arrivals, nights and rooms out of the system but had to count the sex and
 * origin columns by hand from each resort's guest register.
 */
class AccommodationReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->superAdmin()->create();
        $this->resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort', 'barangay' => 'Tambobong']);
        // Roomy and deterministic: the factory picks a random capacity, and a
        // party of three against a capacity of two is refused by the booking
        // rules, which has nothing to do with what is being tested here.
        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 10,
            'total_rooms' => 5,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function stay(string $in, string $out, array $attributes = []): Booking
    {
        return Booking::factory()
            ->for($this->resort)->for($this->room)->for(User::factory()->create(), 'tourist')
            ->forStay($in, $out)
            ->confirmed()
            ->create($attributes);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function register(string $from = '2026-06-01', string $to = '2026-06-30')
    {
        return app(ReportService::class)
            ->accommodationRegister(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    /** @return array<string, mixed> */
    private function rowFor(string $reference, string $from = '2026-06-01', string $to = '2026-06-30'): array
    {
        return $this->register($from, $to)->firstWhere('reference', $reference);
    }

    /**
     * A booking becomes a line in the office's register, under the name of the
     * person who made it.
     */
    public function test_each_stay_is_its_own_line_under_the_name_that_booked_it(): void
    {
        $tourist = User::factory()->create(['first_name' => 'Marites', 'last_name' => 'Calderon']);

        $booking = Booking::factory()
            ->for($this->resort)->for($this->room)->for($tourist, 'tourist')
            ->forStay('2026-06-10', '2026-06-12')
            ->confirmed()
            ->create(['guests' => 2, 'rooms_booked' => 1]);

        $rows = $this->register();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('Marites Calderon', $row['guest']);
        $this->assertSame('Salt Breeze Resort', $row['resort']);
        $this->assertSame($booking->booking_reference, $row['reference']);
        $this->assertSame(2, $row['guests'], 'The people who checked in on this booking.');
        $this->assertSame(4, $row['guest_nights'], '2 guests x 2 nights.');
        $this->assertSame(2, $row['rooms_used'], '1 room x 2 nights.');
    }

    /** Two stays are two lines, not one resort total. */
    public function test_two_stays_are_two_lines(): void
    {
        $this->stay('2026-06-10', '2026-06-12', ['guests' => 2, 'rooms_booked' => 1]);
        $this->stay('2026-06-20', '2026-06-21', ['guests' => 3, 'rooms_booked' => 2]);

        $rows = $this->register();

        $this->assertCount(2, $rows);
        $this->assertSame(5, $rows->sum('guests'));
        $this->assertSame(7, $rows->sum('guest_nights'), '2x2 nights, plus 3x1 night.');
        $this->assertSame(4, $rows->sum('rooms_used'), '1 room x 2 nights, plus 2 rooms x 1 night.');
    }

    /**
     * The return reports visitors, and a cancelled booking is not a visitor.
     * Counting one would overstate the municipality's arrivals to the DOT.
     */
    public function test_it_leaves_out_bookings_that_did_not_happen(): void
    {
        $this->stay('2026-06-10', '2026-06-12', ['guests' => 2]);

        Booking::factory()->for($this->resort)->for($this->room)->for(User::factory()->create(), 'tourist')
            ->forStay('2026-06-14', '2026-06-16')->cancelled()->create(['guests' => 9]);
        Booking::factory()->for($this->resort)->for($this->room)->for(User::factory()->create(), 'tourist')
            ->forStay('2026-06-18', '2026-06-19')->create(['guests' => 7]);

        $rows = $this->register();

        $this->assertCount(1, $rows, 'Only the stay that actually happened.');
        $this->assertSame(2, $rows->sum('guests'));
    }

    /**
     * The register holds who checked IN during the period. A stay that began
     * in May and ran into June belongs on May's sheet, not June's.
     */
    public function test_it_lists_by_arrival_not_by_stay(): void
    {
        $this->stay('2026-05-28', '2026-06-03', ['guests' => 4]);
        $this->stay('2026-06-05', '2026-06-07', ['guests' => 2]);

        $this->assertSame(2, $this->register()->sum('guests'));
        $this->assertSame(4, $this->register('2026-05-01', '2026-05-31')->sum('guests'));
    }

    public function test_each_line_carries_its_own_sex_and_origin(): void
    {
        $pangasinan = $this->stay('2026-06-10', '2026-06-11', [
            'guests' => 3, 'guests_male' => 1, 'guests_female' => 2,
            'origin' => VisitorOrigin::Pangasinan,
        ]);
        $foreign = $this->stay('2026-06-12', '2026-06-13', [
            'guests' => 2, 'guests_male' => 2, 'guests_female' => 0,
            'origin' => VisitorOrigin::Foreign,
        ]);

        $first = $this->rowFor($pangasinan->booking_reference);
        $second = $this->rowFor($foreign->booking_reference);

        $this->assertSame(1, $first['male']);
        $this->assertSame(2, $first['female']);
        $this->assertSame(3, $first['origin']['pangasinan']);
        $this->assertSame(0, $first['origin']['foreign']);

        $this->assertSame(2, $second['male']);
        $this->assertSame(2, $second['origin']['foreign']);
        $this->assertSame(0, $second['origin']['pangasinan']);
    }

    /**
     * A party that said nothing is reported as not stated, never as zero.
     *
     * Zero is a claim — "no male guests came" — and the office would file it
     * as fact. The columns must still add up to the arrivals beside them, or
     * the return cannot be filed at all.
     */
    public function test_what_nobody_stated_is_reported_as_such_and_still_adds_up(): void
    {
        $this->stay('2026-06-10', '2026-06-11', [
            'guests' => 2, 'guests_male' => 1, 'guests_female' => 1,
            'origin' => VisitorOrigin::Ncr,
        ]);
        // Nobody asked this party anything: the shape of every booking made
        // before these fields existed.
        $this->stay('2026-06-12', '2026-06-13', [
            'guests' => 5, 'guests_male' => null, 'guests_female' => null, 'origin' => null,
        ]);

        $rows = $this->register();

        $this->assertSame(7, $rows->sum('guests'));
        $this->assertSame(5, $rows->sum('sex_not_stated'));
        $this->assertSame(5, $rows->sum(fn ($row) => $row['origin']['not_stated']));

        // The two rules the office depends on, line by line.
        foreach ($rows as $row) {
            $this->assertSame(
                $row['guests'],
                $row['male'] + $row['female'] + $row['sex_not_stated'],
                'The sex columns must account for everyone on the line.'
            );
            $this->assertSame(
                $row['guests'],
                array_sum($row['origin']),
                'The origin columns must account for everyone on the line.'
            );
        }
    }

    /* What the booking form collects --------------------------------------- */

    /**
     * The booking form asks, and what it collects reaches the booking.
     *
     * The controller passes an allowlist to BookingService, so a field added
     * to the form and not named there is silently dropped — which would leave
     * the office filing blank columns with no sign anything was wrong.
     */
    public function test_the_booking_form_collects_what_the_return_needs(): void
    {
        $tourist = User::factory()->create();

        // With dates: without them the page shows "choose your dates first"
        // and the form is not rendered at all.
        $this->actingAs($tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $this->room->id,
                'check_in' => today()->addDays(5)->toDateString(),
                'check_out' => today()->addDays(7)->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('name="guests_male"', false)
            ->assertSee('name="guests_female"', false)
            ->assertSee('name="origin"', false)
            ->assertSee('Travelling from');

        $this->actingAs($tourist)->post(route('tourist.bookings.store'), [
            'room' => $this->room->id,
            'check_in_date' => today()->addDays(5)->toDateString(),
            'check_out_date' => today()->addDays(7)->toDateString(),
            'guests' => 3,
            'rooms_booked' => 1,
            'contact_name' => 'Marites Calderon',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 2,
            'origin' => VisitorOrigin::Ncr->value,
        ])->assertRedirect();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $booking->guests_male);
        $this->assertSame(2, $booking->guests_female);
        $this->assertSame(VisitorOrigin::Ncr, $booking->origin);
    }

    /**
     * The three answers are required.
     *
     * They were optional at first, and an optional question on a booking form
     * is one almost nobody answers — which would leave the office filing a
     * return of blanks and counting by hand exactly as before.
     */
    public function test_a_booking_that_skips_the_tourism_questions_is_refused(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->post(route('tourist.bookings.store'), [
            'room' => $this->room->id,
            'check_in_date' => today()->addDays(5)->toDateString(),
            'check_out_date' => today()->addDays(7)->toDateString(),
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Rogelio Santos',
            'contact_phone' => '09171234567',
        ])->assertSessionHasErrors(['guests_male', 'guests_female', 'origin']);

        $this->assertSame(0, Booking::query()->count());
    }

    /**
     * Zero is a real answer, not a missing one.
     *
     * A party of two women has no male guests, and `required` rejects 0 —
     * which is why the rule is `present`. Getting this wrong would make an
     * all-female or all-male booking impossible to send.
     */
    public function test_zero_of_one_sex_is_a_valid_answer(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->post(route('tourist.bookings.store'), [
            'room' => $this->room->id,
            'check_in_date' => today()->addDays(5)->toDateString(),
            'check_out_date' => today()->addDays(7)->toDateString(),
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Marites Calderon',
            'contact_phone' => '09171234567',
            'guests_male' => 0,
            'guests_female' => 2,
            'origin' => VisitorOrigin::Ilocos->value,
        ])->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->assertSame(0, $booking->guests_male);
        $this->assertSame(2, $booking->guests_female);
    }

    /** The sexes must add up to the party, or the office's return will not. */
    public function test_a_sex_split_that_does_not_add_up_is_refused(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->post(route('tourist.bookings.store'), [
            'room' => $this->room->id,
            'check_in_date' => today()->addDays(5)->toDateString(),
            'check_out_date' => today()->addDays(7)->toDateString(),
            'guests' => 4,
            'rooms_booked' => 1,
            'contact_name' => 'Marites Calderon',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
        ])->assertSessionHasErrors('guests_male');

        $this->assertSame(0, Booking::query()->count());
    }

    /* The page and the file ------------------------------------------------ */

    /** The booking a tourist made shows up on the office's report, by name. */
    public function test_the_office_sees_the_guest_by_name_on_the_reports_page(): void
    {
        $tourist = User::factory()->create(['first_name' => 'Marites', 'last_name' => 'Calderon']);

        Booking::factory()->for($this->resort)->for($this->room)->for($tourist, 'tourist')
            ->forStay('2026-06-10', '2026-06-12')->confirmed()
            ->create(['guests' => 2, 'guests_male' => 1, 'guests_female' => 1]);

        $this->actingAs($this->office)
            ->get(route('admin.reports.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertSee('Visitor register')
            ->assertSee('Marites Calderon')
            ->assertSee('Salt Breeze Resort')
            ->assertSee('C-check in')
            ->assertSee('O-night')
            ->assertSee('R-used')
            // Every origin column of the office's form.
            ->assertSee('Pang')
            ->assertSee('Ilocos')
            ->assertSee('NCR')
            ->assertSee('Foreign');
    }

    public function test_the_return_downloads_as_a_workbook_excel_can_open(): void
    {
        $tourist = User::factory()->create(['first_name' => 'Marites', 'last_name' => 'Calderon']);

        Booking::factory()->for($this->resort)->for($this->room)->for($tourist, 'tourist')
            ->forStay('2026-06-10', '2026-06-12')->confirmed()
            ->create([
                'guests' => 2, 'guests_male' => 1, 'guests_female' => 1,
                'origin' => VisitorOrigin::Pangasinan,
            ]);

        $response = $this->actingAs($this->office)->get(route('admin.reports.accommodation', [
            'from' => '2026-06-01', 'to' => '2026-06-30',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));

        // An .xlsx is a ZIP of XML parts. Excel refuses the whole workbook if
        // one part is missing or malformed, so each is checked here rather
        // than trusting that it looked right.
        $path = tempnam(sys_get_temp_dir(), 'vdtest').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The download is not a readable ZIP.');

        foreach ([
            '[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml',
        ] as $part) {
            $xml = $zip->getFromName($part);

            $this->assertNotFalse($xml, "The workbook is missing {$part}.");
            $this->assertNotFalse(simplexml_load_string($xml), "{$part} is not well-formed XML.");
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        // A style index past the end of cellXfs is what makes Excel announce
        // that the file is damaged and offer to repair it.
        preg_match('/<cellXfs count="(\d+)"/', $zip->getFromName('xl/styles.xml'), $declared);
        preg_match_all('/ s="(\d+)"/', $sheet, $used);

        $this->assertLessThan(
            (int) $declared[1],
            max(array_map('intval', $used[1])),
            'A cell refers to a style that the workbook does not define.'
        );

        $this->assertStringContainsString('Salt Breeze Resort', $sheet);

        /*
         * ONE LINE PER RESORT, NOT PER GUEST.
         *
         * This used to assert that the guest was named on their own
         * line, and the return was built that way. It is an aggregate
         * now — arrivals, nights, the sex split and where people came
         * from, counted per resort — and the assertion was never
         * updated, so this test had been failing since the change.
         *
         * The aggregate is also the form the DOT return actually
         * takes, and the safer one to hand over: a workbook that
         * counts people cannot identify one.
         */
        $this->assertStringNotContainsString(
            'Marites Calderon',
            $sheet,
            'The return counts guests; it must never name one.'
        );

        $this->assertSame(2, substr_count($sheet, '<row '), 'One heading row and one resort.');

        $zip->close();
        @unlink($path);
    }

    /**
     * The resort never sees what the guest answered for the tourism office.
     *
     * The privacy notice promises exactly this. It is a promise about code,
     * so it is checked here: the sex split and the region are collected for
     * the office's return to the Department of Tourism, and the resort has no
     * business with either.
     */
    public function test_the_resort_never_sees_the_sex_split_or_the_origin(): void
    {
        $owner = $this->resort->owner;
        $tourist = User::factory()->create(['first_name' => 'Marites', 'last_name' => 'Calderon']);

        $booking = Booking::factory()->for($this->resort)->for($this->room)->for($tourist, 'tourist')
            ->forStay('2026-06-10', '2026-06-12')->confirmed()
            ->create([
                'guests' => 3, 'guests_male' => 1, 'guests_female' => 2,
                'origin' => VisitorOrigin::Ncr,
            ]);

        foreach ([
            route('owner.bookings.index'),
            route('owner.bookings.show', $booking),
            route('owner.statistics'),
        ] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();

            foreach (['Metro Manila', 'guests_male', 'guests_female'] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $html,
                    "{$url} shows the resort what the guest answered for the tourism office."
                );
            }
        }
    }

    public function test_nobody_but_the_office_can_download_the_return(): void
    {
        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $person) {
            $this->actingAs($person)
                ->get(route('admin.reports.accommodation'))
                ->assertForbidden();
        }
    }

    /**
     * Signed out, in a test of its own: actingAs holds for the rest of the
     * test it is called in, so a guest request made after one would still
     * arrive signed in and be refused rather than redirected.
     */
    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.reports.accommodation'))->assertRedirect(route('login'));
    }
}
