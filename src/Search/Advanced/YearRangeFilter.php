<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[yearRange]=2000..2010` — release year (index). A release spanning years (a series,
 * a trilogy) matches when its span overlaps the range; a running series ends in 9999.
 */
class YearRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'yearRange';
    }

    protected function id(): string
    {
        return 'year';
    }

    protected function column(): string
    {
        return 'year_start';
    }

    protected function bounds(): array
    {
        return [0, 9999];
    }

    protected function parseSide(string $side, bool $isMax): int|float|null
    {
        return preg_match('/^\d{1,4}$/', $side) ? (int) $side : null;
    }

    protected function constrainRange($query, Range $range): void
    {
        if ($range->min !== null) {
            $query->where('year_end', '>=', $range->min);
        }
        if ($range->max !== null) {
            $query->where('year_start', '<=', $range->max);
        }
    }
}
