<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\Eloquent\Builder;

/**
 * "min..max" on a numeric column of `discussions`.
 */
abstract class AbstractRangeFilter extends AbstractAdvancedFilter
{
    /** Column, qualified ("discussions.view_count"). */
    abstract protected function column(): string;

    /** @return array{0: int|float, 1: int|float} the column's sane range (bounds are clamped into it) */
    protected function bounds(): array
    {
        return [0, 4_294_967_295];
    }

    protected function parseSide(string $side, bool $isMax): int|float|null
    {
        return Range::number($side);
    }

    protected function prepare(string $value): ?Range
    {
        $range = Range::parse($value, fn (string $s, bool $isMax) => $this->parseSide($s, $isMax));

        return $range?->clamp(...$this->bounds());
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $this->constrainRange($query, $prepared);
    }

    /** @param Builder|\Illuminate\Database\Query\Builder $query */
    protected function constrainRange($query, Range $range): void
    {
        if ($range->min !== null) {
            $query->where($this->column(), '>=', $range->min);
        }
        if ($range->max !== null) {
            $query->where($this->column(), '<=', $range->max);
        }
    }
}
