<?php

namespace TryHackX\HomepageBlocks\Search;

/**
 * Filters discussions by creation date interval.
 */
class DateIntervalFilter extends AbstractDateIntervalFilter
{
    public function getFilterKey(): string
    {
        return 'dateInterval';
    }

    protected function getColumn(): string
    {
        return 'created_at';
    }
}
