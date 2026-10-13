<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\Eloquent\Builder;
use TryHackX\HomepageBlocks\Sort\SteamRatingSort;

/**
 * `filter[steamRange]=70..` — the "Steam DB" confidence-weighted rating as a percentage
 * (0–100), the same score the "Steam DB rating" sort orders by (tryhackx/flarum-topic-rating).
 * Unrated discussions never match.
 */
class SteamRangeFilter extends AbstractRangeFilter
{
    public function getFilterKey(): string
    {
        return 'steamRange';
    }

    protected function id(): string
    {
        return 'steam';
    }

    protected function column(): string
    {
        return 'discussions.rating_average'; // unused: constrainRange() works on the score
    }

    protected function bounds(): array
    {
        return [0, 100];
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $query->where('discussions.rating_count', '>', 0);
        // Raw SQL needs the prefixed table name (the builder only prefixes its own refs).
        $score = SteamRatingSort::expression($query->getModel()->getConnection()->getTablePrefix() . 'discussions');
        // The bound goes in as a numeric literal, not a parameter: PDO binds floats as
        // strings, and SQLite compares an expression (no column affinity) with a string
        // as text. sprintf('%F') of a clamped float is injection-safe.
        if ($prepared->min !== null) {
            $query->whereRaw($score . ' >= ' . sprintf('%.6F', $prepared->min / 100));
        }
        if ($prepared->max !== null) {
            $query->whereRaw($score . ' <= ' . sprintf('%.6F', $prepared->max / 100));
        }
    }
}
