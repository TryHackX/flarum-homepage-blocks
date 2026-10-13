<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[filmwebRange]=7..` — Filmweb rating read from the post, 0–10 (index).
 */
class FilmwebRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'filmwebRange';
    }

    protected function id(): string
    {
        return 'filmweb';
    }

    protected function column(): string
    {
        return 'filmweb_rating';
    }

    protected function bounds(): array
    {
        return [0, 10];
    }
}
