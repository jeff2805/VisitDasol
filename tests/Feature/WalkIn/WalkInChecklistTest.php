<?php

namespace Tests\Feature\WalkIn;

use App\Enums\WalkInSubmissionStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\WalkInBookingService;
use App\Services\WalkInSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * The acceptance checklist for the walk-in module, item by item.
 *
 * WalkInRecordTest proves the engine, WalkInScreensTest the doors into it,
 * and WalkInTourismReportTest the spreadsheet. This file exists so the
 * checklist the module was asked for can be read back off the test names,
 * and it deliberately covers the cases those three left as gaps:
 *
 *   - correcting a record that was never sent anywhere (a plain draft)
 *   - the office accepting a RESUBMITTED record, not just a fresh one
 *   - the GRAND TOTAL row's actual figures, not merely its presence
 *   - the search and the filters on both lists
 */
class WalkInChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $office;

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
        ]);

        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 10,
            'total_rooms' => 8,
            'price_per_night' => 1000,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'room' => $this->room->id,
            'booking_type' => 'overnight',
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Maria Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ], $extra);
    }

    private function record(array $extra = []): Booking
    {
        return app(WalkInBookingService::class)
            ->create($this->resort, $this->room->id, $this->payload($extra));
    }

    /* OWNER 3 — edit a Draft record --------------------------------------
     *
     * The gap the other files left: they correct a record the OFFICE sent
     * back. A plain draft has never left the resort, and must be just as
     * editable.
     */
    public function test_owner_can_correct_a_draft_that_was_never_sent(): void
    {
        $record = $this->record();

        $this->assertSame(WalkInSubmissionStatus::Draft, $record->submission_status);

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.edit', $record))
            ->assertOk();

        $this->actingAs($this->owner)
            ->put(route('owner.walk-ins.update', $record), $this->payload([
                'contact_name' => 'Maria Santos-Reyes',
                'adults' => 3,
                'guests_male' => 2,
                'guests_female' => 1,
            ]))
            ->assertRedirect(route('owner.walk-ins.show', $record));

        $fresh = $record->fresh();

        $this->assertSame('Maria Santos-Reyes', $fresh->contact_name);
        $this->assertSame(3, $fresh->guests);
        $this->assertSame(
            WalkInSubmissionStatus::Draft,
            $fresh->submission_status,
            'Correcting a draft must not send it anywhere.'
        );
        $this->assertNull($fresh->submitted_at);
    }

    /* MTO 5 — review a RESUBMITTED record -------------------------------- */

    public function test_the_office_accepts_a_resubmitted_record(): void
    {
        $office = app(WalkInSubmissionService::class);
        $record = $this->record();

        $office->submit($record, $this->owner);
        $office->returnForCorrection($record->fresh(), $this->office, 'The origin looks wrong.');

        app(WalkInBookingService::class)->update($record->fresh(), $this->payload(['origin' => 'ncr']));
        $office->submit($record->fresh(), $this->owner);

        $this->assertSame(WalkInSubmissionStatus::Resubmitted, $record->fresh()->submission_status);

        // The office may decide on it again, through the screen.
        $this->actingAs($this->office)
            ->patch(route('admin.walk-ins.accept', $record))
            ->assertRedirect(route('admin.walk-ins.show', $record));

        $fresh = $record->fresh();

        $this->assertSame(WalkInSubmissionStatus::Accepted, $fresh->submission_status);
        $this->assertTrue(Booking::query()->reportable()->whereKey($fresh->id)->exists());
        $this->assertSame(4, $fresh->submissions()->count(), 'Submitted, returned, resubmitted, accepted.');
    }

    public function test_the_office_can_return_a_resubmitted_record_a_second_time(): void
    {
        $office = app(WalkInSubmissionService::class);
        $record = $this->record();

        $office->submit($record, $this->owner);
        $office->returnForCorrection($record->fresh(), $this->office, 'First complaint.');
        $office->submit($record->fresh(), $this->owner);
        $office->returnForCorrection($record->fresh(), $this->office, 'Second complaint.');

        $fresh = $record->fresh();

        $this->assertSame(WalkInSubmissionStatus::Returned, $fresh->submission_status);
        $this->assertSame('Second complaint.', $fresh->return_remarks);

        // Both reasons survive, which is the point of keeping a trail.
        $remarks = $fresh->submissions->pluck('remarks')->filter()->values()->all();
        $this->assertContains('First complaint.', $remarks);
        $this->assertContains('Second complaint.', $remarks);
    }

    /* EXCEL 4 — the GRAND TOTAL row's real figures ----------------------- */

    /**
     * The grand total adds both sources, column by column.
     *
     * The other spreadsheet test proves the row is there. This proves it is
     * right: an aggregate report whose total does not reconcile with the
     * rows above it is worse than no total at all, because somebody files
     * it.
     */
    public function test_the_grand_total_reconciles_across_both_sources(): void
    {
        // Online: 2 guests, 2 nights, 1 room, 1 male 1 female, from NCR.
        Booking::factory()
            ->for($this->resort)->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->forStay('2030-06-10', '2030-06-12')
            ->confirmed()
            ->create([
                'guests' => 2, 'adults' => 2, 'rooms_booked' => 1,
                'guests_male' => 1, 'guests_female' => 1, 'origin' => 'ncr',
            ]);

        // Walk-in, accepted: 3 guests, 2 nights, 1 room, 1 male 2 female,
        // from Pangasinan.
        $record = $this->record([
            'adults' => 2, 'children' => 1,
            'guests_male' => 1, 'guests_female' => 2,
            'origin' => 'pangasinan',
        ]);

        $office = app(WalkInSubmissionService::class);
        $office->submit($record, $this->owner);
        $office->accept($record->fresh(), $this->office);

        $total = $this->grandTotalRow();

        /*
         * Resort, Check-ins, Guest Nights, Day Tour, Room Use, Male, Female,
         * Pangasinan, Ilocos Region, NCR, Other PH, Foreign.
         */
        $this->assertSame(2, $total[0], 'Check-ins: one online, one accepted walk-in.');
        $this->assertSame(10, $total[1], 'Guest nights: 2x2 plus 3x2.');
        $this->assertSame(0, $total[2], 'Day tour: neither stay was a tour.');
        $this->assertSame(4, $total[3], 'Room use: 1x2 plus 1x2.');
        $this->assertSame(2, $total[4], 'Male: 1 plus 1.');
        $this->assertSame(3, $total[5], 'Female: 1 plus 2.');
        $this->assertSame(3, $total[6], 'Pangasinan: the walk-in party.');
        $this->assertSame(0, $total[7], 'Ilocos Region: nobody.');
        $this->assertSame(2, $total[8], 'NCR: the online party.');
        $this->assertSame(0, $total[9], 'Other PH: nobody.');
        $this->assertSame(0, $total[10], 'Foreign: nobody.');
    }

    public function test_an_unaccepted_walk_in_is_absent_from_the_grand_total(): void
    {
        Booking::factory()
            ->for($this->resort)->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->forStay('2030-06-10', '2030-06-12')
            ->confirmed()
            ->create([
                'guests' => 2, 'adults' => 2, 'rooms_booked' => 1,
                'guests_male' => 1, 'guests_female' => 1, 'origin' => 'ncr',
            ]);

        // Submitted but never accepted: holds its room, counts for nothing.
        $record = $this->record();
        app(WalkInSubmissionService::class)->submit($record, $this->owner);

        $total = $this->grandTotalRow();

        $this->assertSame(1, $total[0], 'Only the online stay is counted.');
        $this->assertSame(4, $total[1], '2 guests x 2 nights.');
        $this->assertSame(0, $total[6], 'The walk-in party is not in Pangasinan yet.');
    }

    /* OWNER 1 / MTO 2 — search and filter -------------------------------- */

    public function test_the_owner_can_search_and_filter_their_records(): void
    {
        $this->record(['contact_name' => 'Aurora Dalisay']);
        $sent = $this->record([
            'contact_name' => 'Benigno Cruz',
            'check_in_date' => '2030-07-05',
            'check_out_date' => '2030-07-07',
        ]);
        app(WalkInSubmissionService::class)->submit($sent, $this->owner);

        // By name.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.index', ['q' => 'Aurora']))
            ->assertOk()
            ->assertSee('Aurora Dalisay')
            ->assertDontSee('Benigno Cruz');

        // By reference.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.index', ['q' => $sent->booking_reference]))
            ->assertOk()
            ->assertSee('Benigno Cruz')
            ->assertDontSee('Aurora Dalisay');

        // By status.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('Aurora Dalisay')
            ->assertDontSee('Benigno Cruz');

        // By stay dates.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.index', ['from' => '2030-07-01', 'to' => '2030-07-31']))
            ->assertOk()
            ->assertSee('Benigno Cruz')
            ->assertDontSee('Aurora Dalisay');
    }

    public function test_the_office_can_search_and_filter_the_queue(): void
    {
        $office = app(WalkInSubmissionService::class);

        $mine = $this->record(['contact_name' => 'Carmen Lazaro']);
        $office->submit($mine, $this->owner);

        // A second resort, with its own owner and its own submitted record.
        $otherOwner = User::factory()->create(['role' => 'resort_owner']);
        $otherResort = Resort::factory()->active()->create([
            'owner_id' => $otherOwner->id,
            'name' => 'Coral Point Resort',
        ]);
        $otherRoom = Room::factory()->for($otherResort)->create(['capacity' => 10, 'total_rooms' => 4]);

        $theirs = app(WalkInBookingService::class)->create($otherResort, $otherRoom->id, [
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'adults' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Dionisio Perez',
            'contact_phone' => '09170000000',
            'guests_male' => 2,
            'guests_female' => 0,
            'origin' => 'ilocos',
        ]);
        $office->submit($theirs, $otherOwner);

        // By resort name in the search box.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.index', ['q' => 'Coral Point']))
            ->assertOk()
            ->assertSee('Dionisio Perez')
            ->assertDontSee('Carmen Lazaro');

        // By the resort filter.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.index', ['resort' => $this->resort->id]))
            ->assertOk()
            ->assertSee('Carmen Lazaro')
            ->assertDontSee('Dionisio Perez');

        // By tourist name.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.index', ['q' => 'Carmen']))
            ->assertOk()
            ->assertSee('Carmen Lazaro')
            ->assertDontSee('Dionisio Perez');

        // By submission date: both were sent today, so tomorrow finds neither.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.index', ['submitted_from' => '2030-06-16']))
            ->assertOk()
            ->assertDontSee('Carmen Lazaro')
            ->assertDontSee('Dionisio Perez');

        // By check-in date.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.index', ['from' => '2030-06-19', 'to' => '2030-06-21']))
            ->assertOk()
            ->assertSee('Carmen Lazaro');
    }

    /* Both detail pages in their FINAL state -----------------------------
     *
     * A coverage gap worth closing on its own: every other test leaves the
     * record waiting or returned, so the branch each page takes once the
     * office has accepted it had never been rendered by anything. A page
     * that only breaks after the happy ending is the worst kind.
     */
    public function test_both_detail_pages_render_once_a_record_is_accepted(): void
    {
        $office = app(WalkInSubmissionService::class);
        $record = $this->record();

        $office->submit($record, $this->owner);
        $office->accept($record->fresh(), $this->office);

        // The office's page: no decision buttons, and who accepted it.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('Accepted on')
            ->assertDontSee('Return for correction')
            ->assertDontSee('>Accept<', false);

        // The resort's page: accepted, and no longer theirs to change.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('counts towards the municipality')
            ->assertDontSee('Submit to MTO')
            ->assertDontSee('Resubmit to MTO');

        // And the list pages, with an accepted row on them.
        $this->actingAs($this->owner)->get(route('owner.walk-ins.index'))->assertOk()->assertSee('Accepted');
        $this->actingAs($this->office)->get(route('admin.walk-ins.index'))->assertOk()->assertSee('Accepted');
    }

    public function test_the_office_page_renders_for_a_returned_record(): void
    {
        $office = app(WalkInSubmissionService::class);
        $record = $this->record();

        $office->submit($record, $this->owner);
        $office->returnForCorrection($record->fresh(), $this->office, 'The sex split does not add up.');

        // Waiting on the resort: the office has nothing to decide, and says so.
        $this->actingAs($this->office)
            ->get(route('admin.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('Waiting on the resort')
            ->assertSee('The sex split does not add up.');
    }

    /* AVAILABILITY 3 — the figure the owner's own calendar reads ---------- */

    public function test_a_walk_in_shows_up_on_the_owners_availability_calendar(): void
    {
        // Eight rooms needs at least eight guests: the room's own minimum,
        // enforced by Room::partyProblem() and shared with the online form.
        $this->record([
            'rooms_booked' => 8,
            'adults' => 8,
            'guests_male' => 4,
            'guests_female' => 4,
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.availability', ['month' => '2030-06']))
            ->assertOk();

        $free = app(\App\Services\AvailabilityService::class)
            ->availableUnits($this->room, '2030-06-20', '2030-06-22');

        $this->assertSame(0, $free, 'All eight units are taken by the walk-in.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The numeric cells of the spreadsheet's last row.
     *
     * @return list<int>
     */
    private function grandTotalRow(): array
    {
        $response = $this->actingAs($this->office)->get(route('admin.reports.accommodation', [
            'from' => '2030-06-01',
            'to' => '2030-06-30',
        ]));

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'vd').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The download is not a readable workbook.');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        // Rows in document order; the writer puts GRAND TOTAL last.
        preg_match_all('/<row [^>]*>(.*?)<\/row>/s', $sheet, $rows);
        $last = end($rows[1]);

        $this->assertStringContainsString(
            'GRAND TOTAL',
            (string) $last,
            'The last row of the sheet is not the grand total.'
        );

        // Numbers are written bare in <v>; text goes in an inline string.
        preg_match_all('/<v>(-?\d+(?:\.\d+)?)<\/v>/', (string) $last, $values);

        return array_map('intval', $values[1]);
    }
}
