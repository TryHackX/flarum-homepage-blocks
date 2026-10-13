<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[episodesRange]=..20` — number of episodes (index).
 */
class EpisodesRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'episodesRange';
    }

    protected function id(): string
    {
        return 'episodes';
    }

    protected function column(): string
    {
        return 'episodes';
    }

    protected function bounds(): array
    {
        return [0, 100_000];
    }
}
