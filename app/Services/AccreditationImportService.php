<?php

namespace App\Services;

use App\Enums\AccreditationStatus;
use App\Models\AccreditedResort;
use App\Models\User;
use App\Support\AccreditationKey;
use App\Support\SpreadsheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Turns the office's spreadsheet into rows of the accreditation list.
 *
 * Two steps on purpose. read() says what WOULD happen and changes
 * nothing; commit() does it. The office is uploading the register that
 * every later application is judged against, and a silent import that
 * quietly skipped eight malformed rows would leave eight accredited
 * resorts being told they are not on the list, with nothing on any
 * screen to explain why.
 */
class AccreditationImportService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Heading spellings this accepts, by field.
     *
     * Offices do not agree on column names and should not have to edit
     * their own file to suit this one. Compared after the same levelling
     * the values get, so "Accreditation No." and "accreditation no"
     * are one heading.
     *
     * @var array<string, list<string>>
     */
    private const HEADINGS = [
        'resort_name' => ['resort name', 'resort', 'name', 'business name', 'establishment', 'establishment name'],
        'owner_name' => ['owner', 'owner name', 'proprietor', 'operator', 'owner operator'],
        'barangay' => ['barangay', 'brgy', 'location', 'address'],
        'accreditation_no' => ['accreditation no', 'accreditation number', 'acc no', 'acc number', 'accreditation', 'accreditation code'],
        'accreditation_status' => ['status', 'accreditation status'],
        'valid_until' => ['valid until', 'validity', 'valid thru', 'valid through', 'expiry', 'expiry date', 'expiration', 'expiration date', 'date of expiry'],
    ];

    /**
     * What the file would do to the list, row by row, without doing it.
     *
     * @return array{
     *     rows: list<array{line: int, action: string, data: array<string, mixed>, existing: ?AccreditedResort, errors: list<string>}>,
     *     counts: array{create: int, update: int, duplicate: int, invalid: int},
     *     headings: array<string, int>,
     *     missing: list<string>
     * }
     *
     * @throws RuntimeException when the file cannot be read at all
     */
    public function read(string $path, string $extension): array
    {
        $raw = SpreadsheetReader::rows($path, $extension);

        if ($raw === []) {
            throw new RuntimeException('That file has no rows in it.');
        }

        $headings = $this->mapHeadings(array_shift($raw));

        if (! isset($headings['resort_name'])) {
            throw new RuntimeException(
                'No resort name column was found. The first row must name the '
                .'columns, and one of them must be "Resort Name".'
            );
        }

        $missing = array_values(array_diff(
            array_keys(self::HEADINGS),
            array_keys($headings),
        ));

        $rows = [];
        $counts = ['create' => 0, 'update' => 0, 'duplicate' => 0, 'invalid' => 0];

        // Keys already met IN THIS FILE. An office list that names the
        // same resort twice should import it once and say so, rather
        // than letting the second row quietly overwrite the first.
        $seen = [];

        foreach ($raw as $offset => $cells) {
            // +2: the heading row was shifted off, and people count from one.
            $row = $this->readRow($cells, $headings, $offset + 2, $seen);

            $counts[$row['action']]++;
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'headings' => $headings,
            'missing' => $missing,
        ];
    }

    /**
     * Writes the rows read above, and says what it did.
     *
     * @param  array{rows: list<array<string, mixed>>, counts: array<string, int>}  $preview
     * @return array{created: int, updated: int, skipped: int}
     */
    public function commit(array $preview, User $actor, string $filename): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($preview, $actor, &$created, &$updated): void {
            foreach ($preview['rows'] as $row) {
                if (! in_array($row['action'], ['create', 'update'], true)) {
                    continue;
                }

                /*
                 * Found again here rather than trusting the model the
                 * preview held: the preview may have been drawn minutes
                 * ago, and a second officer may have changed the list in
                 * between. The key is what is stable, not the object.
                 */
                $existing = $this->findExisting($row['data']);

                if ($existing === null) {
                    $row['data']['uploaded_by'] = $actor->id;
                    AccreditedResort::create($row['data']);
                    $created++;

                    continue;
                }

                /*
                 * An upload refreshes the list; it does not re-attribute
                 * what was already there, so uploaded_by is left as the
                 * officer who first entered the record.
                 */
                $existing->fill($row['data'])->save();
                $updated++;
            }
        });

        $skipped = count($preview['rows']) - $created - $updated;

        $this->logger->log(
            'accreditation.imported',
            null,
            "Imported the accredited resorts list from {$filename}: "
            ."{$created} added, {$updated} updated, {$skipped} skipped",
            ['file' => $filename, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped],
            $actor,
        );

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /* Reading one row ---------------------------------------------------- */

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $headings
     * @param  array<string, true>  $seen
     * @return array{line: int, action: string, data: array<string, mixed>, existing: ?AccreditedResort, errors: list<string>}
     */
    private function readRow(array $cells, array $headings, int $line, array &$seen): array
    {
        $value = function (string $field) use ($cells, $headings): string {
            $index = $headings[$field] ?? null;

            return $index === null ? '' : trim($cells[$index] ?? '');
        };

        $errors = [];

        $name = $value('resort_name');
        $accreditationNo = $value('accreditation_no');

        if ($name === '') {
            $errors[] = 'no resort name';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'the resort name is longer than 200 characters';
        }

        if (mb_strlen($accreditationNo) > 60) {
            $errors[] = 'the accreditation number is longer than 60 characters';
        }

        $status = $this->readStatus($value('accreditation_status'), $errors);
        $validUntil = $this->readDate($value('valid_until'), $errors);

        $data = [
            'resort_name' => $name,
            'owner_name' => $value('owner_name') ?: null,
            'barangay' => $value('barangay') ?: null,
            'accreditation_no' => $accreditationNo ?: null,
            'accreditation_status' => $status->value,
            'valid_until' => $validUntil?->toDateString(),
        ];

        if ($errors !== []) {
            return ['line' => $line, 'action' => 'invalid', 'data' => $data, 'existing' => null, 'errors' => $errors];
        }

        $key = $this->rowKey($data);

        if (isset($seen[$key])) {
            return [
                'line' => $line,
                'action' => 'duplicate',
                'data' => $data,
                'existing' => null,
                'errors' => ['the same record appears earlier in this file'],
            ];
        }

        $seen[$key] = true;

        $existing = $this->findExisting($data);

        return [
            'line' => $line,
            'action' => $existing === null ? 'create' : 'update',
            'data' => $data,
            'existing' => $existing,
            'errors' => [],
        ];
    }

    /**
     * The row this one would replace, if any.
     *
     * The accreditation number wins when there is one: it is the thing
     * the office issues and the only identifier that is meant to be
     * unique. Without one, a resort is identified by its name and
     * barangay together — two resorts in Dasol share a name often
     * enough that the name alone would merge them.
     *
     * @param  array<string, mixed>  $data
     */
    private function findExisting(array $data): ?AccreditedResort
    {
        $number = AccreditationKey::accreditationNumber($data['accreditation_no'] ?? null);

        if ($number !== '') {
            $match = AccreditedResort::query()
                ->whereNotNull('accreditation_no')
                ->get()
                ->first(fn (AccreditedResort $row): bool => AccreditationKey::accreditationNumber($row->accreditation_no) === $number);

            if ($match !== null) {
                return $match;
            }
        }

        $nameKey = AccreditationKey::name($data['resort_name'] ?? null);
        $barangayKey = AccreditationKey::barangay($data['barangay'] ?? null) ?: null;

        return AccreditedResort::query()
            ->where('name_key', $nameKey)
            ->where(fn ($q) => $barangayKey === null
                ? $q->whereNull('barangay_key')
                : $q->where('barangay_key', $barangayKey))
            ->first();
    }

    /** @param array<string, mixed> $data */
    private function rowKey(array $data): string
    {
        $number = AccreditationKey::accreditationNumber($data['accreditation_no'] ?? null);

        if ($number !== '') {
            return 'no:'.$number;
        }

        return 'nb:'.AccreditationKey::name($data['resort_name'] ?? null)
            .'|'.AccreditationKey::barangay($data['barangay'] ?? null);
    }

    /** @param list<string> $errors */
    private function readStatus(string $raw, array &$errors): AccreditationStatus
    {
        if (trim($raw) === '') {
            return AccreditationStatus::Active;
        }

        $status = AccreditationStatus::tryFrom(strtolower(trim($raw)));

        if ($status !== null) {
            return $status;
        }

        $errors[] = 'the status "'.$raw.'" is not one of active, expired or suspended';

        return AccreditationStatus::Active;
    }

    /**
     * A date from a spreadsheet, which may not be written as one.
     *
     * @param  list<string>  $errors
     */
    private function readDate(string $raw, array &$errors): ?CarbonImmutable
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        /*
         * Excel keeps dates as a count of days from 1900 and only shows
         * them as dates through formatting, which this reader does not
         * look at. So a bare number in the plausible range is read as
         * one — 25569 is 1970, 60000 is 2064 — and anything outside that
         * is left to be parsed as text, where it will fail honestly.
         *
         * The two-day offset is Excel's own: it treats 1900 as a leap
         * year, which it was not, and every date after February 1900
         * carries the error.
         */
        if (ctype_digit($raw) && (int) $raw >= 20000 && (int) $raw <= 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $raw);
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (Throwable) {
            $errors[] = 'the date "'.$raw.'" could not be read';

            return null;
        }
    }

    /**
     * Which column holds which field.
     *
     * @param  list<string>  $headingRow
     * @return array<string, int>
     */
    private function mapHeadings(array $headingRow): array
    {
        $found = [];

        foreach ($headingRow as $index => $heading) {
            $clean = AccreditationKey::person($heading);

            if ($clean === '') {
                continue;
            }

            foreach (self::HEADINGS as $field => $spellings) {
                // First column wins. A sheet with "Name" early and
                // "Resort Name" later would otherwise take the later one,
                // and the office's preview would show the wrong column.
                if (! isset($found[$field]) && in_array($clean, $spellings, true)) {
                    $found[$field] = $index;
                    break;
                }
            }
        }

        return $found;
    }
}
