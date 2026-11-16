<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * SQL fragments that differ between database engines.
 *
 * The site runs on PostgreSQL, but the test suite runs on the MySQL that
 * ships with XAMPP, so anything written in raw SQL has to be spelled for
 * whichever engine is answering. Rather than scatter driver checks through
 * the services, every fragment that differs lives here.
 *
 * The column names passed in are always written by us, never by a visitor;
 * they are placed into the query as-is.
 */
class Sql
{
    /**
     * A date column as a sortable 'YYYY-MM' month key.
     */
    public static function monthKey(string $column): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "TO_CHAR({$column}, 'YYYY-MM')",
        };
    }

    /**
     * Everything in a column before the first $delimiter, or the whole
     * value when the delimiter is not in it.
     */
    public static function firstSegment(string $column, string $delimiter): string
    {
        $quoted = "'".str_replace("'", "''", $delimiter)."'";

        return match (self::driver()) {
            'mysql', 'mariadb' => "SUBSTRING_INDEX({$column}, {$quoted}, 1)",
            'sqlite' => "CASE WHEN INSTR({$column}, {$quoted}) = 0 THEN {$column} ELSE SUBSTR({$column}, 1, INSTR({$column}, {$quoted}) - 1) END",
            default => "SPLIT_PART({$column}, {$quoted}, 1)",
        };
    }

    /**
     * The whole number of days from $start to $end, both date expressions.
     *
     * PostgreSQL subtracts two dates into a count of days. MySQL instead
     * reads each date as the number YYYYMMDD and subtracts those, so a span
     * across the end of a month comes out wildly too big (1 June minus 29
     * May reads as 72, not 3). DATEDIFF is the one that means days.
     */
    public static function dateDiffDays(string $end, string $start): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "DATEDIFF({$end}, {$start})",
            'sqlite' => "CAST(JULIANDAY({$end}) - JULIANDAY({$start}) AS integer)",
            default => "({$end} - {$start})",
        };
    }

    /**
     * A placeholder or expression read as a date.
     */
    public static function asDate(string $expression): string
    {
        return self::driver() === 'sqlite' ? "DATE({$expression})" : "CAST({$expression} AS date)";
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
