<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[ratedRange]=from..to` — when the discussion was last rated
 * (tryhackx/flarum-topic-rating's `last_rated_at`).
 */
class RatedRangeFilter extends AbstractDateRangeFilter
{
    public function getFilterKey(): string
    {
        return 'ratedRange';
    }

    protected function id(): string
    {
        return 'rated';
    }

    protected function column(): string
    {
        return 'discussions.last_rated_at';
    }
}
