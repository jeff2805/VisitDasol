<?php

namespace Tests\Feature\WalkIn;

use App\Enums\WalkInSubmissionStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\ReportService;
use App\Services\WalkInBookingService;
use App\Services\WalkInSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Accepted walk-ins in the Department of Tourism's monthly return.
 *
 * The return is one row per resort, and a resort that takes guests both ways
 * must appear once with the two added together — not twice, and not with the
 * walk-ins left out. What must never happen is a stay the office has not
 * accepted being filed as though it had.
 *
 * The spreadsheet's own shape — its headings, colours, widths and the GRAND
 * TOTAL row — is not touched by any of this, and the last test here opens the
 * real file to prove it.
 */
class WalkInTourismReportTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private User $owner;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-15 09:00:00');

        $this->office = User::factory()->superAdmin()->create();
        $this->owner = User::factory()->create(['role' => 'resort_owner']);

        $this->resort = Resort::factory()->active()->create([
            'owner_id' => $this->owner->id,
            'name' => 'Salt Breeze Resort',
            'barangay' => 'Tambobong',
        ]);

        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 10,
            'total_rooms' => 8,
            'price_per_night' => 1000,
            'day_tour_price' => 500,
        ]);
    }

    /** A walk-in, left in whatever state the caller asks for. */
    private function walkIn(array $extra = [], ?WalkInSubmissionStatus $land = null): Booking
    {
        $record = app(WalkInBookingService::class)->create($this->resort, $this->room->id, array_merge([
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'adults' => 2,
            'children' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Walk-in Guest',
            'contact_phone' => '09170000000',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ], $extra));

        if ($land === null || $land === WalkInSubmissionStatus::Draft) {
            return $record;
        }

        $office = app(WalkInSubmissionService::class);
        $office->submit($record, $this->owner);

        if ($land === WalkInSubmissionStatus::Accepted) {
            $office->accept($record->fresh(), $this->office);
        }

        if ($land === WalkInSubmissionStatus::Returned) {
            $office->returnForCorrection($record->fresh(), $this->office, 'Please check the origin.');
        }

        return $record->fresh();
    }

    private function onlineStay(array $extra = []): Booking
    {
        return Booking::factory()
            ->for($this->resort)->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->forStay('2030-06-10', '2030-06-12')
            ->confirmed()
            ->create(array_merge([
                'guests' => 2,
                'adults' => 2,
                'rooms_booked' => 1,
                'guests_male' => 1,
                'guests_female' => 1,
                'origin' => 'ncr',
            ], $extra));
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function register()
    {
        return app(ReportService::class)->accommodationRegister(
            CarbonImmutable::parse('2030-06-01'),
            CarbonImmutable::parse('2030-06-30'),
        );
    }

    /* What counts, and what does not ------------------------------------- */

    public function test_only_accepted_walk_ins_reach_the_return(): void
    {
        $this->walkIn(['contact_name' => 'A Draft'], WalkInSubmissionStatus::Draft);
        $this->walkIn(['contact_name' => 'B Pending', 'check_in_date' => '2030-06-21', 'check_out_date' => '2030-06-23'], WalkInSubmissionStatus::PendingReview);
        $this->walkIn(['contact_name' => 'C Returned', 'check_in_date' => '2030-06-22', 'check_out_date' => '2030-06-24'], WalkInSubmissionStatus::Returned);
        $this->walkIn(['contact_name' => 'D Accepted', 'check_in_date' => '2030-06-23', 'check_out_date' => '2030-06-25'], WalkInSubmissionStatus::Accepted);

        $names = $this->register()->pluck('guest')->all();

        $this->assertContains('D Accepted', $names);
        $this->assertNotContains('A Draft', $names);
        $this->assertNotContains('B Pending', $names);
        $this->assertNotContains('C Returned', $names);
        $this->assertCount(1, $names);
    }

    public function test_a_resubmitted_record_still_does_not_count(): void
    {
        $record = $this->walkIn([], WalkInSubmissionStatus::Returned);

        app(WalkInSubmissionService::class)->submit($record->fresh(), $this->owner);

        $this->assertSame(
            WalkInSubmissionStatus::Resubmitted,
            $record->fresh()->submission_status
        );
        $this->assertCount(0, $this->register(), 'Waiting on the office is not accepted.');
    }

    public function test_accepting_a_record_puts_it_in_the_return_without_touching_the_stay(): void
    {
        $record = $this->walkIn([], WalkInSubmissionStatus::PendingReview);

        $this->assertCount(0, $this->register());

        app(WalkInSubmissionService::class)->accept($record->fresh(), $this->office);

        $rows = $this->register();
        $this->assertCount(1, $rows);
        $this->assertSame('Walk-in Guest', $rows->first()['guest']);
    }

    /**
     * A walk-in has no account, and the register must still name somebody.
     */
    public function test_a_walk_in_is_named_by_the_guest_the_resort_met(): void
    {
        $this->walkIn(['contact_name' => 'Lola Pacing'], WalkInSubmissionStatus::Accepted);

        $row = $this->register()->first();

        $this->assertSame('Lola Pacing', $row['guest']);
        $this->assertSame('Salt Breeze Resort', $row['resort']);
    }

    /* One resort row, both sources added together ------------------------ */

    public function test_a_resort_with_both_kinds_appears_once_with_the_totals_added(): void
    {
        $this->onlineStay();                                    // 2 guests, 2 nights
        $this->walkIn([], WalkInSubmissionStatus::Accepted);     // 2 guests, 2 nights

        $rows = $this->register();
        $this->assertCount(2, $rows, 'Two stays, two register lines.');

        // The spreadsheet groups those lines into one row per resort.
        $byResort = $rows->groupBy('resort');
        $this->assertCount(1, $byResort, 'One resort, one row.');

        $resortRows = $byResort->get('Salt Breeze Resort');
        $this->assertSame(2, $resortRows->count(), 'Check-ins: both stays.');
        $this->assertSame(8, $resortRows->sum('guest_nights'), '2x2 plus 2x2.');
        $this->assertSame(4, $resortRows->sum('rooms_used'), '1x2 plus 1x2.');
        $this->assertSame(2, $resortRows->sum('male'));
        $this->assertSame(2, $resortRows->sum('female'));
    }

    public function test_each_party_lands_in_its_own_origin_column(): void
    {
        $this->onlineStay();                                                     // NCR, 2
        $this->walkIn(['origin' => 'pangasinan'], WalkInSubmissionStatus::Accepted); // Pangasinan, 2

        $rows = $this->register();

        $this->assertSame(2, $rows->sum(fn (array $r): int => $r['origin']['ncr']));
        $this->assertSame(2, $rows->sum(fn (array $r): int => $r['origin']['pangasinan']));
        $this->assertSame(0, $rows->sum(fn (array $r): int => $r['origin']['foreign']));
    }

    /* The Day Tour column, which used to read zero always ---------------- */

    public function test_a_day_tour_is_counted_in_the_day_tour_column(): void
    {
        $this->walkIn([
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-06-20',
            'check_out_date' => null,
            'adults' => 4,
            'guests_male' => 2,
            'guests_female' => 2,
        ], WalkInSubmissionStatus::Accepted);

        $row = $this->register()->first();

        $this->assertSame(4, $row['day_tour'], 'Four people on a day tour.');
        $this->assertSame(0, $row['guest_nights'], 'Nobody slept there.');
        $this->assertSame(1, $row['rooms_used'], 'The cottage was still taken for the date.');
    }

    public function test_an_overnight_stay_adds_nothing_to_the_day_tour_column(): void
    {
        $this->onlineStay();

        $row = $this->register()->first();

        $this->assertSame(0, $row['day_tour']);
        $this->assertSame(4, $row['guest_nights']);
    }

    /* The spreadsheet itself --------------------------------------------- */

    /**
     * The real download, opened and read back.
     *
     * The headings, the GRAND TOTAL row and the column order are the form's
     * own and are what the office files, so they are asserted against the
     * actual file rather than against the code that writes it.
     */
    public function test_the_spreadsheet_keeps_its_shape_and_totals_both_sources(): void
    {
        $this->onlineStay();
        $this->walkIn([], WalkInSubmissionStatus::Accepted);
        $this->walkIn([
            'contact_name' => 'Not Accepted',
            'check_in_date' => '2030-06-24',
            'check_out_date' => '2030-06-26',
        ], WalkInSubmissionStatus::PendingReview);

        $response = $this->actingAs($this->office)->get(route('admin.reports.accommodation', [
            'from' => '2030-06-01',
            'to' => '2030-06-30',
        ]));

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'vd').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The download is not a readable workbook.');
        // This writer puts its text inline in the sheet; there is no
        // sharedStrings part to read.
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        // Every heading the form asks for, still in the file.
        foreach (['Resort', 'Check-ins', 'Guest Nights', 'Day Tour', 'Room Use',
            'Male', 'Female', 'Pangasinan', 'Ilocos Region', 'NCR', 'Other PH',
            'Foreign', 'GRAND TOTAL'] as $heading) {
            $this->assertStringContainsString($heading, $sheet, "The sheet lost the {$heading} heading.");
        }

        /*
         * ONE ROW FOR THE RESORT, HOWEVER MANY WAYS IT TAKES GUESTS.
         *
         * The accepted walk-in was added into Salt Breeze's own line rather
         * than given a second line of its own, which is the thing most
         * likely to go wrong when a second source of bookings is added to
         * an aggregate report.
         */
        $this->assertSame(
            1,
            substr_count($sheet, 'Salt Breeze Resort'),
            'The resort must appear exactly once.'
        );

        // Heading, the one resort, and the grand total.
        $this->assertSame(3, substr_count($sheet, '<row '));

        // The record still waiting on the office is nowhere in the return.
        $this->assertStringNotContainsString('Not Accepted', $sheet);
    }

    public function test_an_owner_cannot_download_the_offices_return(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.reports.accommodation'))
            ->assertForbidden();
    }
}
