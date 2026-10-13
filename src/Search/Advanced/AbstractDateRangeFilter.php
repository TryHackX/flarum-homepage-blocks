<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Carbon\Carbon;

/**
 * Date range on a datetime column of `discussions`. Each bound is either
 *   - a unix timestamp — the panel sends the visitor's own local day bounds this way,
 *     because Flarum stores and compares datetimes in UTC; or
 *   - a date "YYYY-MM-DD" (a UTC day; the "to" day is included in full).
 */
abstract class AbstractDateRangeFilter extends AbstractRangeFilter
{
    protected function bounds(): array
    {
        return [0, 253_402_300_799]; // 9999-12-31 23:59:59
    }

    protected function parseSide(string $side, bool $isMax): int|float|null
    {
        if (preg_match('/^\d{1,12}$/', $side)) {
            return (int) $side;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $side, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $day = Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC');

            return $isMax ? $day->endOfDay()->getTimestamp() : $day->getTimestamp();
        }

        return null;
    }

    protected function constrainRange($query, Range $range): void
    {
        if ($range->min !== null) {
            $query->where($this->column(), '>=', Carbon::createFromTimestamp((int) $range->min, 'UTC'));
        }
        if ($range->max !== null) {
            $query->where($this->column(), '<=', Carbon::createFromTimestamp((int) $range->max, 'UTC'));
        }
    }
}
