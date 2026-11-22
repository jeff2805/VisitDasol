<?php

namespace Tests\Feature\Admin;

use App\Enums\AccreditationStatus;
use App\Models\AccreditedResort;
use App\Models\User;
use App\Services\AccreditationImportService;
use App\Support\SpreadsheetReader;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading the office's spreadsheet into the accreditation register.
 *
 * Two things are being tested: that the file is read correctly, and
 * that nothing is written until the office has seen what would happen.
 * The second matters because this is the register every later
 * application is judged against — an import that silently dropped eight
 * malformed rows would leave eight accredited resorts being told they
 * are not on the list, with nothing anywhere to explain it.
 */
class AccreditationImportTest extends TestCase
{
    use RefreshDatabase;

    private AccreditationImportService $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = app(AccreditationImportService::class);
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'acc').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        fclose($handle);

        return $path;
    }

    /** @param list<list<string>> $rows */
    private function read(array $rows): array
    {
        return $this->importer->read($this->csv($rows), 'csv');
    }

    private function headings(): array
    {
        return ['Resort Name', 'Owner', 'Barangay', 'Accreditation No.', 'Status', 'Valid Until'];
    }

    /* Reading the file ------------------------------------------------- */

    public function test_a_plain_file_is_read_into_rows(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', 'Juan Dela Cruz', 'Tambobong', 'ACC-001', 'Active', '2027-06-30'],
            ['XYZ Beach Resort', 'Pedro Santos', 'Osmeña', 'ACC-002', 'Active', '2027-06-30'],
        ]);

        $this->assertSame(2, $preview['counts']['create']);
        $this->assertSame(0, $preview['counts']['invalid']);
        $this->assertSame('ABC Resort', $preview['rows'][0]['data']['resort_name']);
        $this->assertSame('Osmeña', $preview['rows'][1]['data']['barangay']);
    }

    /**
     * Offices do not agree on column names and should not have to edit
     * their own file to suit this system.
     */
    public function test_headings_are_recognised_however_they_are_written(): void
    {
        $preview = $this->read([
            ['BUSINESS NAME', 'Proprietor', 'Brgy', 'Accreditation Number', 'Status', 'Expiry Date'],
            ['ABC Resort', 'Juan Dela Cruz', 'Tambobong', 'ACC-001', 'active', '2027-06-30'],
        ]);

        $this->assertSame([], $preview['rows'][0]['errors']);
        $this->assertSame('Juan Dela Cruz', $preview['rows'][0]['data']['owner_name']);
        $this->assertSame('ACC-001', $preview['rows'][0]['data']['accreditation_no']);
    }

    public function test_only_the_resort_name_column_is_required(): void
    {
        $preview = $this->read([
            ['Resort Name'],
            ['ABC Resort'],
        ]);

        $this->assertSame(1, $preview['counts']['create']);
        $this->assertContains('owner_name', $preview['missing']);

        // Absent status is taken as active, which is what a list of
        // accredited resorts means by default.
        $this->assertSame('active', $preview['rows'][0]['data']['accreditation_status']);
    }

    public function test_a_file_with_no_resort_name_column_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->read([
            ['Owner', 'Barangay'],
            ['Juan Dela Cruz', 'Tambobong'],
        ]);
    }

    public function test_blank_rows_are_ignored(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', '', '', '', '', ''],
            ['', '', '', '', '', ''],
        ]);

        $this->assertCount(1, $preview['rows']);
    }

    /* Rows that cannot be used ----------------------------------------- */

    public function test_a_row_with_no_name_is_marked_invalid_and_the_rest_still_import(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['', 'Juan Dela Cruz', 'Tambobong', 'ACC-001', 'Active', ''],
            ['XYZ Resort', 'Pedro Santos', 'Osmeña', 'ACC-002', 'Active', ''],
        ]);

        $this->assertSame(1, $preview['counts']['invalid']);
        $this->assertSame(1, $preview['counts']['create']);
        $this->assertSame(['no resort name'], $preview['rows'][0]['errors']);
    }

    public function test_an_unknown_status_is_reported_rather_than_guessed(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'renewed', ''],
        ]);

        $this->assertSame('invalid', $preview['rows'][0]['action']);
        $this->assertStringContainsString('renewed', $preview['rows'][0]['errors'][0]);
    }

    public function test_an_unreadable_date_is_reported(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'Active', 'sometime next year'],
        ]);

        $this->assertSame('invalid', $preview['rows'][0]['action']);
    }

    /**
     * Excel keeps dates as a count of days and only shows them as dates
     * through formatting, which the reader does not look at. A file
     * saved with a real date column arrives here as a bare number.
     */
    public function test_an_excel_serial_date_is_understood(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'Active', '46569'],
        ]);

        $this->assertSame([], $preview['rows'][0]['errors']);

        // Checked against a known anchor rather than against the code
        // under test: Excel serial 45292 is 2024-01-01, and 46569 is
        // 1277 days later.
        $this->assertSame('2027-07-01', $preview['rows'][0]['data']['valid_until']);
    }

    /* Duplicates -------------------------------------------------------- */

    public function test_the_same_record_twice_in_one_file_is_imported_once(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'Active', ''],
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'Active', ''],
        ]);

        $this->assertSame(1, $preview['counts']['create']);
        $this->assertSame(1, $preview['counts']['duplicate']);
    }

    /**
     * Re-uploading a corrected list is the ordinary case, and it must
     * refresh the register rather than double it.
     */
    public function test_re_uploading_updates_instead_of_duplicating(): void
    {
        $office = User::factory()->create();

        $first = $this->read([
            $this->headings(),
            ['ABC Resort', 'Juan Dela Cruz', 'Tambobong', 'ACC-001', 'Active', ''],
        ]);
        $this->importer->commit($first, $office, 'list.csv');

        $second = $this->read([
            $this->headings(),
            ['ABC Resort', 'Maria Santos', 'Tambobong', 'ACC-001', 'Suspended', ''],
        ]);
        $result = $this->importer->commit($second, $office, 'list.csv');

        $this->assertSame(1, AccreditedResort::count());
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['created']);

        $row = AccreditedResort::sole();
        $this->assertSame('Maria Santos', $row->owner_name);
        $this->assertSame(AccreditationStatus::Suspended, $row->accreditation_status);
    }

    /**
     * The accreditation number is the identifier the office issues, so a
     * renamed resort keeping its number is the same record.
     */
    public function test_a_renamed_resort_keeping_its_number_is_the_same_record(): void
    {
        $office = User::factory()->create();

        $this->importer->commit($this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'Active', ''],
        ]), $office, 'list.csv');

        $this->importer->commit($this->read([
            $this->headings(),
            ['Sunset Cove Resort', '', 'Tambobong', 'ACC-001', 'Active', ''],
        ]), $office, 'list.csv');

        $this->assertSame(1, AccreditedResort::count());
        $this->assertSame('Sunset Cove Resort', AccreditedResort::sole()->resort_name);
    }

    /**
     * Two resorts in Dasol sharing a name is ordinary. Without a number
     * to tell them apart, the barangay has to.
     */
    public function test_the_same_name_in_two_barangays_makes_two_records(): void
    {
        $office = User::factory()->create();

        $this->importer->commit($this->read([
            $this->headings(),
            ['Seaside Resort', '', 'Tambobong', '', 'Active', ''],
            ['Seaside Resort', '', 'Osmeña', '', 'Active', ''],
        ]), $office, 'list.csv');

        $this->assertSame(2, AccreditedResort::count());
    }

    /* Nothing is written before it is confirmed ------------------------ */

    public function test_reading_a_file_writes_nothing(): void
    {
        $this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', 'ACC-001', 'Active', ''],
        ]);

        $this->assertSame(0, AccreditedResort::count());
    }

    public function test_skipped_rows_are_not_written_on_commit(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['', '', 'Tambobong', '', 'Active', ''],
            ['Good Resort', '', 'Tambobong', '', 'Active', ''],
        ]);

        $result = $this->importer->commit($preview, User::factory()->create(), 'list.csv');

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(['Good Resort'], AccreditedResort::pluck('resort_name')->all());
    }

    public function test_the_import_is_written_to_the_activity_log(): void
    {
        $preview = $this->read([
            $this->headings(),
            ['ABC Resort', '', 'Tambobong', '', 'Active', ''],
        ]);

        $this->importer->commit($preview, User::factory()->create(), 'MTO_List.csv');

        $this->assertDatabaseHas('activity_logs', ['action' => 'accreditation.imported']);
    }

    /* The file formats themselves -------------------------------------- */

    /**
     * A CSV saved on a machine with European or Philippine regional
     * settings uses semicolons. Read as commas it becomes one enormous
     * column and no heading is ever recognised.
     */
    public function test_a_semicolon_separated_csv_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'acc').'.csv';
        file_put_contents($path, "Resort Name;Barangay\nABC Resort;Tambobong\n");

        $preview = $this->importer->read($path, 'csv');

        $this->assertSame('ABC Resort', $preview['rows'][0]['data']['resort_name']);
        $this->assertSame('Tambobong', $preview['rows'][0]['data']['barangay']);
    }

    /** Excel writes a byte order mark, and it lands in the first heading. */
    public function test_a_csv_with_a_byte_order_mark_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'acc').'.csv';
        file_put_contents($path, "\xEF\xBB\xBFResort Name,Barangay\nABC Resort,Tambobong\n");

        $preview = $this->importer->read($path, 'csv');

        $this->assertSame('ABC Resort', $preview['rows'][0]['data']['resort_name']);
    }

    /**
     * An .xlsx written by this project's own writer, which stores text
     * inline rather than in the shared string table.
     */
    public function test_an_xlsx_with_inline_strings_is_read(): void
    {
        $writer = new Xlsx('Accredited', ['Resort Name', 'Barangay', 'Accreditation No.']);
        $path = $writer->write([
            ['ABC Resort', 'Tambobong', 'ACC-001'],
            ['XYZ Resort', 'Osmeña', 'ACC-002'],
        ]);

        $preview = $this->importer->read($path, 'xlsx');

        $this->assertSame(2, $preview['counts']['create']);
        $this->assertSame('ABC Resort', $preview['rows'][0]['data']['resort_name']);
        $this->assertSame('ACC-002', $preview['rows'][1]['data']['accreditation_no']);
    }

    /**
     * What real Excel produces: text in a shared table, cells pointing
     * at it by index. Without support for this, a genuine office upload
     * reads as a grid of numbers.
     */
    public function test_an_xlsx_with_a_shared_string_table_is_read(): void
    {
        $path = $this->excelStyleWorkbook([
            ['Resort Name', 'Barangay'],
            ['ABC Resort', 'Tambobong'],
        ]);

        $rows = SpreadsheetReader::rows($path, 'xlsx');

        $this->assertSame([['Resort Name', 'Barangay'], ['ABC Resort', 'Tambobong']], $rows);
    }

    /**
     * An empty cell is simply absent from the XML. Placed in arrival
     * order rather than by column letter, a row with a blank middle
     * column shifts every later value one place left — and barangays
     * get filed under accreditation numbers.
     */
    public function test_a_gap_in_a_row_does_not_shift_the_later_columns(): void
    {
        $path = $this->excelStyleWorkbook([
            ['Resort Name', 'Owner', 'Barangay'],
            ['ABC Resort', null, 'Tambobong'],
        ]);

        $preview = $this->importer->read($path, 'xlsx');

        $this->assertSame('ABC Resort', $preview['rows'][0]['data']['resort_name']);
        $this->assertNull($preview['rows'][0]['data']['owner_name']);
        $this->assertSame('Tambobong', $preview['rows'][0]['data']['barangay']);
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused_clearly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'acc').'.xlsx';
        file_put_contents($path, 'this is not a workbook');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not be opened|CSV/i');

        $this->importer->read($path, 'xlsx');
    }

    /**
     * Builds a workbook the way Excel does — shared strings, and cells
     * carrying their own column reference so gaps are possible.
     *
     * @param  list<list<string|null>>  $rows
     */
    private function excelStyleWorkbook(array $rows): string
    {
        $strings = [];

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell !== null && ! in_array($cell, $strings, true)) {
                    $strings[] = $cell;
                }
            }
        }

        $sheet = '';

        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $cells = '';

            foreach ($row as $columnIndex => $cell) {
                if ($cell === null) {
                    continue; // The gap: Excel omits the cell entirely.
                }

                $reference = chr(65 + $columnIndex).$number;
                $index = array_search($cell, $strings, true);
                $cells .= '<c r="'.$reference.'" t="s"><v>'.$index.'</v></c>';
            }

            $sheet .= '<row r="'.$number.'">'.$cells.'</row>';
        }

        $sharedXml = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            .count($strings).'" uniqueCount="'.count($strings).'">';

        foreach ($strings as $string) {
            $sharedXml .= '<si><t>'.htmlspecialchars($string, ENT_QUOTES | ENT_XML1).'</t></si>';
        }

        $sharedXml .= '</sst>';

        $path = tempnam(sys_get_temp_dir(), 'acc').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheet.'</sheetData></worksheet>'
        );

        $zip->close();

        return $path;
    }
}
