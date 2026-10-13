<?php

namespace TryHackX\HomepageBlocks\Sort;

use Illuminate\Database\ConnectionInterface;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Sort;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * Advanced Filters sorts by a column of the metadata index:
 *
 *   hbSize — release size (size_bytes)
 *   hbYear — release year (year_start)
 *
 * Discussions without the value come last in both directions; ties fall back to the
 * newest discussion. Like {@see SteamRatingSort}, the discussion list's actual ordering
 * comes from {@see \TryHackX\HomepageBlocks\Search\MetaSortMutator} (Flarum's search
 * orders by a column name, and these are no columns of `discussions`); apply() is the
 * equivalent for a plain json-api-server listing.
 *
 * Always registered (the admin switch only shows / hides the options): a registered sort
 * whose ordering could be skipped would fall through to a non-existent column → HTTP 500.
 */
class MetaSort extends Sort
{
    /** Sort name → index column. */
    public const COLUMNS = ['hbSize' => 'size_bytes', 'hbYear' => 'year_start'];

    // Required by core's HasSortMap (see SteamRatingSort) — do not remove.
    protected array $alias = ['asc' => null, 'desc' => null];

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function sortMap(): array
    {
        $map = [];
        foreach ($this->alias as $direction => $alias) {
            if ($alias) {
                $map[$alias] = ($direction === 'asc' ? '' : '-') . $this->name;
            }
        }

        return $map;
    }

    public function apply(object $query, string $direction, Context $context): void
    {
        $connection = method_exists($query, 'getConnection') ? $query->getConnection() : resolve(ConnectionInterface::class);
        self::order($query, $connection, $this->name, $direction);
    }

    /**
     * ORDER BY (value IS NULL), value <dir>, discussions.id DESC — with the value read
     * through a correlated sub-select on the index's primary key (no JOIN, so nothing
     * else in the query can collide with the index table's columns).
     */
    public static function order(object $query, ConnectionInterface $connection, string $name, string $direction): void
    {
        $column = self::COLUMNS[$name] ?? null;
        if ($column === null) {
            return;
        }
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $sub = $connection->table(MetaIndexer::TABLE)
            ->select($column)
            ->whereColumn(MetaIndexer::TABLE . '.discussion_id', 'discussions.id')
            ->limit(1)
            ->toSql();

        $query->orderByRaw("case when ($sub) is null then 1 else 0 end")
            ->orderByRaw("($sub) $direction")
            ->orderBy('discussions.id', 'desc');
    }
}
