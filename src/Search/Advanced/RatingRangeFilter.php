<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\Eloquent\Builder;

/**
 * `filter[ratingRange]=3.5..5` — average star rating, 0–5 (tryhackx/flarum-topic-rating).
 * Unrated discussions (their average is stored as 0) never match.
 */
class RatingRangeFilter extends AbstractRangeFilter
{
    public function getFilterKey(): string
    {
        return 'ratingRange';
    }

    protected function id(): string
    {
        return 'rating';
    }

    protected function column(): string
    {
        return 'discussions.rating_average';
    }

    protected function bounds(): array
    {
        return [0, 5];
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $query->where('discussions.rating_count', '>', 0);
        parent::apply($query, $prepared);
    }
}
