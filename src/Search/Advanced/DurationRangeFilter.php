<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[durationRange]=..90` — running time in minutes (index; an average episode for a series).
 */
class DurationRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'durationRange';
    }

    protected function id(): string
    {
        return 'duration';
    }

    protected function column(): string
    {
        return 'duration_min';
    }

    protected function bounds(): array
    {
        return [0, 6000];
    }
}
