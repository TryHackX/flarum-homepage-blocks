<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[activeRange]=from..to` — last activity (newest post).
 */
class ActiveRangeFilter extends AbstractDateRangeFilter
{
    public function getFilterKey(): string
    {
        return 'activeRange';
    }

    protected function id(): string
    {
        return 'active';
    }

    protected function column(): string
    {
        return 'discussions.last_posted_at';
    }
}
