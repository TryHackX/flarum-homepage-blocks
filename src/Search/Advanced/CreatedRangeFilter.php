<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[createdRange]=from..to` — when the discussion was started.
 */
class CreatedRangeFilter extends AbstractDateRangeFilter
{
    public function getFilterKey(): string
    {
        return 'createdRange';
    }

    protected function id(): string
    {
        return 'created';
    }

    protected function column(): string
    {
        return 'discussions.created_at';
    }
}
