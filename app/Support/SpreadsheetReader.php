<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a .csv or .xlsx file into rows of plain strings.
 *
 * WHY THIS EXISTS RATHER THAN A LIBRARY. Support\Xlsx already writes
 * real workbooks with nothing but ZipArchive, on the stated grounds that
 * an .xlsx is a ZIP of XML parts and the job is narrow. Reading is the
 * harder direction and that argument does not stretch forever — so this
 * is drawn tightly around one job: the first worksheet of a file the
 * office exported from its own accreditation list, read as text.
 *
 * It handles the three ways a cell's text is actually stored (the shared
 * string table, inline strings, and plain numbers), gaps in a row, and
 * Excel's serial dates. It does NOT handle formulas, several sheets,
 * merged cells, or anything to do with formatting. If a file defeats it,
 * the import screen says so and asks for a CSV — which is always
 * available from Excel's own Save As, and never fails to parse.
 *
 * Everything comes back as a string. Deciding what a value means is the
 * importer's job, not the reader's.
 */
final class SpreadsheetReader
{
    /**
     * A ceiling on rows, so a mistaken upload cannot exhaust memory.
     *
     * Dasol has tens of accredited resorts. Ten thousand is far beyond
     * any real list and far below anything that would trouble PHP.
     */
    public const MAX_ROWS = 10000;

    /**
     * Rows of cells, in sheet order, including the heading row.
     *
     * Trailing blank rows are dropped — spreadsheets collect them — and
     * so is any row where every cell is empty.
     *
     * @return list<list<string>>
     *
     * @throws RuntimeException when the file cannot be read as a sheet
     */
    public static function rows(string $path, string $extension): array
    {
        $rows = match (strtolower($extension)) {
            'csv', 'txt' => self::fromCsv($path),
            'xlsx' => self::fromXlsx($path),
            default => throw new RuntimeException(
                'Only .xlsx and .csv files can be read.'
            ),
        };

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => implode('', array_map('trim', $row)) !== '',
        ));
    }

    /* CSV ---------------------------------------------------------------- */

    /**
     * @return list<list<string>>
     */
    private static function fromCsv(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The file could not be opened.');
        }

        $rows = [];

        try {
            $delimiter = self::csvDelimiter($path);
            $first = true;

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($rows) >= self::MAX_ROWS) {
                    break;
                }

                // fgetcsv gives [null] for a blank line.
                $cells = array_map(fn ($cell): string => (string) $cell, $cells);

                if ($first) {
                    // Excel writes a byte order mark; left in place it
                    // becomes part of the first heading and the column is
                    // never recognised.
                    $cells[0] = preg_replace('/^\x{FEFF}/u', '', $cells[0]) ?? $cells[0];
                    $first = false;
                }

                $rows[] = $cells;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Comma or semicolon.
     *
     * A spreadsheet saved as CSV on a machine with Philippine or European
     * regional settings uses semicolons, and reading it as commas gives
     * one enormous column with no recognisable headings.
     */
    private static function csvDelimiter(string $path): string
    {
        $line = (string) @file_get_contents($path, false, null, 0, 4096);
        $line = strtok($line, "\r\n") ?: '';

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /* XLSX --------------------------------------------------------------- */

    /**
     * @return list<list<string>>
     */
    private static function fromXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException(
                'That file could not be opened as a spreadsheet. If it was saved '
                .'by another program, try saving it again as CSV.'
            );
        }

        try {
            $sheet = self::firstSheetXml($zip);
            $shared = self::sharedStrings($zip);

            return self::sheetRows($sheet, $shared);
        } finally {
            $zip->close();
        }
    }

    private static function firstSheetXml(ZipArchive $zip): SimpleXMLElement
    {
        /*
         * sheet1.xml is not guaranteed to be the first sheet — the
         * workbook's own order is what counts, and a file edited over
         * time can have sheet3.xml sitting first. Read the workbook and
         * follow its relationship, falling back to the plain name.
         */
        $name = self::firstSheetPath($zip) ?? 'xl/worksheets/sheet1.xml';
        $xml = $zip->getFromName($name);

        if ($xml === false) {
            throw new RuntimeException('The workbook has no readable worksheet.');
        }

        return self::parseXml($xml);
    }

    private static function firstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            return null;
        }

        $sheets = self::parseXml($workbook)->sheets->sheet ?? null;

        if ($sheets === null || ! isset($sheets[0])) {
            return null;
        }

        $id = (string) $sheets[0]->attributes('r', true)->id;

        foreach (self::parseXml($rels)->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== $id) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return null;
    }

    /**
     * The workbook's shared string table.
     *
     * Excel stores most text once here and has cells point at it by
     * index, so without this a real Excel file reads as a grid of
     * numbers. Rich text splits one string across several runs, which is
     * why every <t> in an entry is joined rather than only the first.
     *
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach (self::parseXml($xml)->si as $entry) {
            $text = '';

            foreach ($entry->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private static function sheetRows(SimpleXMLElement $sheet, array $shared): array
    {
        $rows = [];

        foreach ($sheet->sheetData->row ?? [] as $row) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $cells = [];

            foreach ($row->c ?? [] as $cell) {
                /*
                 * Place each cell by its own column letter rather than in
                 * arrival order. An empty cell is simply absent from the
                 * XML, so a row with a blank middle column would
                 * otherwise shift every later value one place left and
                 * silently file barangays under accreditation numbers.
                 */
                $index = self::columnIndex((string) $cell['r']);
                $cells[$index] = self::cellText($cell, $shared);
            }

            if ($cells === []) {
                $rows[] = [];

                continue;
            }

            // Fill the gaps the sparse placement left behind.
            $width = max(array_keys($cells)) + 1;
            $complete = [];

            for ($i = 0; $i < $width; $i++) {
                $complete[] = $cells[$i] ?? '';
            }

            $rows[] = $complete;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $shared
     */
    private static function cellText(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $index = (int) $cell->v;

            return $shared[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            $text = '';

            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }

            return $text;
        }

        // A formula cell still carries its last computed value in <v>,
        // which is the answer wanted here; the formula itself is ignored.
        return isset($cell->v) ? (string) $cell->v : '';
    }

    /**
     * "C5" is column 2, counting from zero. "AB7" is column 27.
     */
    private static function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($reference), $match);

        $letters = $match[1] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Parses XML with entity loading left off.
     *
     * The file came from an upload box, and an XML parser that follows
     * external entities will read files off this server and post them
     * wherever the document says. LIBXML_NONET refuses the network and
     * NOENT is deliberately not passed.
     */
    private static function parseXml(string $xml): SimpleXMLElement
    {
        $parsed = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        if ($parsed === false) {
            throw new RuntimeException('The workbook could not be read.');
        }

        return $parsed;
    }
}
