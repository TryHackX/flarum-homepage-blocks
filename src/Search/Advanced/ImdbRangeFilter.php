<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[imdbRange]=7..` — IMDb rating read from the post, 0–10 (index).
 */
class ImdbRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'imdbRange';
    }

    protected function id(): string
    {
        return 'imdb';
    }

    protected function column(): string
    {
        return 'imdb_rating';
    }

    protected function bounds(): array
    {
        return [0, 10];
    }
}
