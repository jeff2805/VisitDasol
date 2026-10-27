<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Which column a list is sorted by, and which way.
 *
 * The office lists had no sorting at all: every one of them arrived newest
 * first and there was no way to ask "who registered earliest" or "which resort
 * is most expensive" without reading every page. This reads ?sort= and ?dir=
 * off the query string, checks them against a list the page itself declares,
 * and applies them.
 *
 * Nothing from the request reaches the query. An unknown column or direction
 * falls back to the page's default rather than being rejected, so a
 * hand-edited URL can never break a page or reach the database.
 *
 * A column spec is the column to order by, a list of columns for a tie-break
 * chain, or either with a leading "-" to mean "the big end first" — dates and
 * money read better newest and highest first, names read better A to Z:
 *
 *   TableSort::fromRequest($request, [
 *       'name' => ['last_name', 'first_name'],
 *       'registered' => '-created_at',
 *   ], default: 'registered');
 */
final class TableSort
{
    private const DIRECTIONS = ['asc', 'desc'];

    /**
     * @param  array<string, string|list<string>>  $columns
     */
    private function __construct(
        public readonly string $key,
        public readonly string $direction,
        private readonly array $columns,
        private readonly string $default,
    ) {}

    /**
     * True while the list is in the order the page chose for itself.
     *
     * The filter bars use this to decide whether to offer "Clear". Several of
     * these lists default to a curated order — featured resorts first, the
     * longest-waiting application first — and without this there was no way
     * back to it once a column had been clicked.
     */
    public function isDefault(): bool
    {
        return $this->key === $this->default
            && $this->direction === self::naturalFirst($this->columns[$this->default]);
    }

    /**
     * @param  array<string, string|list<string>>  $columns  sort key => column spec
     */
    public static function fromRequest(Request $request, array $columns, string $default): self
    {
        $key = (string) $request->query('sort', $default);

        if (! array_key_exists($key, $columns)) {
            $key = $default;
        }

        $direction = strtolower((string) $request->query('dir'));

        if (! in_array($direction, self::DIRECTIONS, true)) {
            $direction = self::naturalFirst($columns[$key]);
        }

        return new self($key, $direction, $columns, $default);
    }

    /**
     * Orders the query, then breaks ties on the primary key.
     *
     * Without that tie-break, two rows with the same date can swap places
     * between one page and the next, and a row is shown twice while another is
     * never shown at all.
     *
     * Columns are used exactly as the page declared them, never qualified with
     * the table name here. A page that joins must say "resorts.name" itself,
     * because only it knows about the join — and a withCount() alias such as
     * "rooms_count" is not a column on any table, so qualifying it would build
     * SQL for a column that does not exist. Only the tie-break is qualified,
     * where the table is known for certain.
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->columnsFor($this->key) as $column) {
            $query->orderBy($column, $this->direction);
        }

        return $query->orderBy($query->qualifyColumn($query->getModel()->getKeyName()), 'desc');
    }

    public function isOn(string $key): bool
    {
        return $this->key === $key;
    }

    /** What a link on this column should ask for: the opposite, or its natural start. */
    public function nextDirection(string $key): string
    {
        if (! $this->isOn($key)) {
            return self::naturalFirst($this->columns[$key] ?? '');
        }

        return $this->direction === 'asc' ? 'desc' : 'asc';
    }

    /** The aria-sort value for this column's header, or null when it is not the one in use. */
    public function ariaSort(string $key): ?string
    {
        if (! $this->isOn($key)) {
            return null;
        }

        return $this->direction === 'asc' ? 'ascending' : 'descending';
    }

    /**
     * The columns to order by, with any "-" marker stripped.
     *
     * @return list<string>
     */
    private function columnsFor(string $key): array
    {
        $spec = $this->columns[$key];

        return array_map(
            fn (string $column): string => ltrim($column, '-'),
            is_array($spec) ? array_values($spec) : [$spec]
        );
    }

    /** @param  string|list<string>  $spec */
    private static function naturalFirst(string|array $spec): string
    {
        $first = is_array($spec) ? ($spec[0] ?? '') : $spec;

        return str_starts_with($first, '-') ? 'desc' : 'asc';
    }
}
