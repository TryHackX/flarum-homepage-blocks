<?php

namespace TryHackX\HomepageBlocks\Search;

/**
 * Filters discussions by the time period in which they were rated.
 * Uses the `last_rated_at` column from tryhackx/flarum-topic-rating.
 */
class RatingFilter extends AbstractDateIntervalFilter
{
    public function getFilterKey(): string
    {
        return 'ratingInterval';
    }

    protected function getColumn(): string
    {
        return 'last_rated_at';
    }
}
