<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[ratingCountRange]=3..` — number of ratings (tryhackx/flarum-topic-rating).
 */
class RatingCountRangeFilter extends AbstractRangeFilter
{
    public function getFilterKey(): string
    {
        return 'ratingCountRange';
    }

    protected function id(): string
    {
        return 'rating_count';
    }

    protected function column(): string
    {
        return 'discussions.rating_count';
    }
}
