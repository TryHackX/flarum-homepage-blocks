<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[seasonsRange]=3..` — number of seasons (index).
 */
class SeasonsRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'seasonsRange';
    }

    protected function id(): string
    {
        return 'seasons';
    }

    protected function column(): string
    {
        return 'seasons';
    }

    protected function bounds(): array
    {
        return [0, 500];
    }
}
