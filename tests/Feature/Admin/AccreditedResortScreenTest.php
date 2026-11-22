<?php

namespace Tests\Feature\Admin;

use App\Enums\AccreditationMatch;
use App\Enums\AccreditationStatus;
use App\Enums\ResortStatus;
use App\Models\AccreditedResort;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The accreditation register as the office meets it: the screen, the
 * upload, and the indicator on an application under review.
 *
 * The access-control tests are not a formality. This register is the
 * thing applications are judged against — an owner who could write to
 * it could add their own business and hand the office a green badge the
 * office never issued.
 */
class AccreditedResortScreenTest extends TestCase
{
    use RefreshDatabase;

    private function office(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
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

    private function csvUpload(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acc').'.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'MTO_Accredited_Resorts.csv', 'text/csv', null, true);
    }

    /* Who may get in --------------------------------------------------- */

    public function test_the_office_can_open_the_register(): void
    {
        $this->listed();

        $this->actingAs($this->office())
            ->get(route('admin.accredited-resorts.index'))
            ->assertOk()
            ->assertSee('Accredited resorts')
            ->assertSee('ABC Beach Resort')
            ->assertSee('ACC-001');
    }

    public function test_a_resort_owner_cannot_reach_the_register(): void
    {
        $owner = User::factory()->resortOwner()->create(['email_verified_at' => now()]);

        $this->actingAs($owner)
            ->get(route('admin.accredited-resorts.index'))
            ->assertForbidden();
    }

    /**
     * The one that matters: an owner must not be able to add their own
     * business to the list they are about to be judged against.
     */
    public function test_a_resort_owner_cannot_write_to_the_register(): void
    {
        $owner = User::factory()->resortOwner()->create(['email_verified_at' => now()]);
        $record = $this->listed();

        $this->actingAs($owner)
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => $this->csvUpload("Resort Name\nTheir Own Resort\n"),
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('admin.accredited-resorts.update', $record), [
                'resort_name' => 'Renamed By An Owner',
                'accreditation_status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete(route('admin.accredited-resorts.destroy', $record))
            ->assertForbidden();

        $this->assertSame('ABC Beach Resort', $record->fresh()->resort_name);
        $this->assertDatabaseMissing('accredited_resorts', ['resort_name' => 'Their Own Resort']);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.accredited-resorts.index'))->assertRedirect(route('login'));
    }

    /* Uploading -------------------------------------------------------- */

    public function test_the_preview_shows_what_would_change_without_saving(): void
    {
        $this->actingAs($this->office())
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => $this->csvUpload(
                    "Resort Name,Barangay,Accreditation No.\n"
                    ."ABC Resort,Tambobong,ACC-001\n"
                    ."XYZ Resort,Osmeña,ACC-002\n"
                ),
            ])
            ->assertOk()
            ->assertSee('Check the upload')
            ->assertSee('ABC Resort')
            ->assertSee('XYZ Resort');

        $this->assertSame(0, AccreditedResort::count());
    }

    /**
     * The preview screen with every kind of row on it at once.
     *
     * Each outcome draws a different badge and a different line under
     * it, and the unhappy branches are the ones a happy-path test never
     * reaches — which is exactly where a template error waits.
     */
    public function test_the_preview_shows_each_kind_of_row(): void
    {
        $this->listed(['resort_name' => 'ABC Resort', 'accreditation_no' => 'ACC-001']);

        $this->actingAs($this->office())
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => $this->csvUpload(
                    "Resort Name,Barangay,Accreditation No.,Status\n"
                    ."ABC Resort,Tambobong,ACC-001,Active\n"   // updates
                    ."New Resort,Osmeña,ACC-009,Active\n"      // adds
                    ."New Resort,Osmeña,ACC-009,Active\n"      // repeated
                    .",Tambobong,ACC-010,Active\n"             // no name
                    ."Bad Status Resort,Bobonot,ACC-011,renewed\n"
                ),
            ])
            ->assertOk()
            ->assertSee('Update')
            ->assertSee('Add')
            ->assertSee('Skip')
            ->assertSee('no resort name')
            ->assertSee('the same record appears earlier in this file')
            ->assertSee('Check the upload');

        $this->assertSame(1, AccreditedResort::count());
    }

    /**
     * A file where nothing can be imported must not offer a button that
     * would import nothing and report success.
     */
    public function test_a_file_with_nothing_usable_cannot_be_imported(): void
    {
        $this->actingAs($this->office())
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => $this->csvUpload("Resort Name,Barangay\n,Tambobong\n,Osmeña\n"),
            ])
            ->assertOk()
            ->assertSee('Nothing in this file can be imported')
            ->assertSee('disabled', escape: false);
    }

    public function test_confirming_the_preview_writes_the_records(): void
    {
        $office = $this->office();

        $preview = $this->actingAs($office)
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => $this->csvUpload("Resort Name,Barangay\nABC Resort,Tambobong\n"),
            ])
            ->assertOk();

        $token = $preview->viewData('token');

        $this->actingAs($office)
            ->post(route('admin.accredited-resorts.import'), [
                'token' => $token,
                'extension' => 'csv',
                'filename' => 'MTO_Accredited_Resorts.csv',
            ])
            ->assertRedirect(route('admin.accredited-resorts.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, AccreditedResort::count());
        $this->assertSame('ABC Resort', AccreditedResort::sole()->resort_name);
    }

    /**
     * The upload waits on the private disk between the two screens. A
     * confirmation for a file that is no longer there must say so
     * rather than import nothing and report success.
     */
    public function test_confirming_an_upload_that_is_gone_says_so(): void
    {
        $this->actingAs($this->office())
            ->post(route('admin.accredited-resorts.import'), [
                'token' => '11111111-1111-4111-8111-111111111111',
                'extension' => 'csv',
                'filename' => 'gone.csv',
            ])
            ->assertRedirect(route('admin.accredited-resorts.index'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, AccreditedResort::count());
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'acc').'.pdf';
        file_put_contents($path, '%PDF-1.4 not a spreadsheet');

        $this->actingAs($this->office())
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => new UploadedFile($path, 'list.pdf', 'application/pdf', null, true),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_a_spreadsheet_with_no_name_column_is_refused_with_a_reason(): void
    {
        $this->actingAs($this->office())
            ->post(route('admin.accredited-resorts.preview'), [
                'file' => $this->csvUpload("Owner,Barangay\nJuan Dela Cruz,Tambobong\n"),
            ])
            ->assertSessionHasErrors('file');
    }

    /* Editing one record ----------------------------------------------- */

    public function test_the_office_can_correct_a_record(): void
    {
        $record = $this->listed();

        $this->actingAs($this->office())
            ->put(route('admin.accredited-resorts.update', $record), [
                'resort_name' => 'ABC Beach Resort',
                'owner_name' => 'Maria Santos',
                'barangay' => 'Tambobong',
                'accreditation_no' => 'ACC-001',
                'accreditation_status' => 'suspended',
                'valid_until' => '2028-01-31',
            ])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame('Maria Santos', $record->owner_name);
        $this->assertSame(AccreditationStatus::Suspended, $record->accreditation_status);
    }

    public function test_two_records_cannot_share_an_accreditation_number(): void
    {
        $this->listed(['accreditation_no' => 'ACC-001']);
        $second = $this->listed(['resort_name' => 'XYZ Resort', 'accreditation_no' => 'ACC-002']);

        $this->actingAs($this->office())
            ->put(route('admin.accredited-resorts.update', $second), [
                'resort_name' => 'XYZ Resort',
                'accreditation_no' => 'ACC-001',
                'accreditation_status' => 'active',
            ])
            ->assertSessionHasErrors('accreditation_no');
    }

    /* The indicator on an application ---------------------------------- */

    private function application(array $attributes = []): Resort
    {
        $owner = User::factory()->resortOwner()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);

        return Resort::factory()->for($owner, 'owner')->create(array_merge([
            'name' => 'ABC Beach Resort',
            'barangay' => 'Tambobong',
            'status' => ResortStatus::Pending,
            'submitted_at' => now(),
        ], $attributes));
    }

    public function test_a_matched_application_shows_listed_with_its_number(): void
    {
        $this->listed();
        $resort = $this->application();
        app(\App\Services\AccreditationMatcher::class)->apply($resort);

        $this->actingAs($this->office())
            ->get(route('admin.resort-applications.show', $resort))
            ->assertOk()
            ->assertSee('MTO Accreditation List status')
            ->assertSee('LISTED')
            ->assertSee('ACC-001');
    }

    public function test_an_unmatched_application_says_not_listed(): void
    {
        $resort = $this->application(['name' => 'Nowhere Near Anything']);
        app(\App\Services\AccreditationMatcher::class)->apply($resort);

        $this->actingAs($this->office())
            ->get(route('admin.resort-applications.show', $resort))
            ->assertOk()
            ->assertSee('NOT LISTED');
    }

    /**
     * Listed is not approval, and the screen has to say so. A green
     * badge beside a resort name reads as a verdict to anyone moving
     * quickly through a queue.
     */
    public function test_the_screen_says_listed_is_not_an_approval(): void
    {
        $this->listed();
        $resort = $this->application();
        app(\App\Services\AccreditationMatcher::class)->apply($resort);

        $this->actingAs($this->office())
            ->get(route('admin.resort-applications.show', $resort))
            ->assertSee('not an approval', escape: false);
    }

    /**
     * An application nobody has checked must not be shown as "not
     * listed" — that is a finding nobody made.
     */
    public function test_an_unchecked_application_says_it_was_not_checked(): void
    {
        $resort = $this->application();

        $this->assertNull($resort->accreditation_match_status);

        $this->actingAs($this->office())
            ->get(route('admin.resort-applications.show', $resort))
            ->assertOk()
            ->assertSee('has not been checked')
            ->assertDontSee('NOT LISTED');
    }

    /* Submitting an application runs the check ------------------------- */

    public function test_resubmitting_a_rejected_listing_checks_it_again(): void
    {
        $this->listed();

        $owner = User::factory()->resortOwner()->create(['email_verified_at' => now()]);
        $resort = Resort::factory()->for($owner, 'owner')->create([
            'name' => 'ABC Beach Resort',
            'barangay' => 'Tambobong',
            'status' => ResortStatus::Rejected,
        ]);

        $this->actingAs($owner)->patch(route('owner.resort.resubmit'))->assertRedirect();

        $resort->refresh();

        // Back in the queue, and checked against the list as it stands now.
        $this->assertSame(ResortStatus::Pending, $resort->status);
        $this->assertSame(AccreditationMatch::Listed, $resort->accreditation_match_status);
    }

    /* The sidebar ------------------------------------------------------ */

    public function test_the_register_is_linked_from_the_office_sidebar(): void
    {
        $this->actingAs($this->office())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.accredited-resorts.index'), escape: false);
    }
}
