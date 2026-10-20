<?php

namespace TryHackX\HomepageBlocks\Search;

/**
 * `<column> [not] like '%…%'` for user input, with the input's `%` / `_` taken literally.
 *
 * The escape character is spelled out (`escape '!'`) instead of relying on a default:
 * MySQL/MariaDB and PostgreSQL escape with a backslash by default (so a backslash the
 * user typed changed the pattern — "k\s" searched for "ks"), SQLite has no default
 * escape at all (a typed "%" stayed a wildcard). `!` needs no quoting in any of them.
 */
final class LikeContains
{
    public const ESCAPE = '!';

    /** The "%…%" pattern for $value, its `!`, `%` and `_` escaped. */
    public static function pattern(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param string $column qualified ("discussions.title"); wrap() adds the table prefix and quoting
     */
    public static function where($query, string $column, string $value, bool $negate = false): void
    {
        $query->whereRaw(
            $query->getGrammar()->wrap($column) . ($negate ? ' not like ' : ' like ') . "? escape '" . self::ESCAPE . "'",
            [self::pattern($value)]
        );
    }
}
