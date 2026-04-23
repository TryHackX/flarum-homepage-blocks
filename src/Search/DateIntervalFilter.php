<?php

namespace TryHackX\HomepageBlocks\Search;

use Carbon\Carbon;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * Filters discussions by creation date interval.
 */
class DateIntervalFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'dateInterval';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $date = match ((string) $value) {
            'today' => Carbon::today(),
            '1d' => Carbon::now()->subDay(),
            '1w' => Carbon::now()->subWeek(),
            '2w' => Carbon::now()->subWeeks(2),
            '1m' => Carbon::now()->subMonth(),
            '3m' => Carbon::now()->subMonths(3),
            '6m' => Carbon::now()->subMonths(6),
            '1y' => Carbon::now()->subYear(),
            default => null,
        };

        if ($date) {
            if ($negate) {
                $state->getQuery()->where('created_at', '<', $date);
            } else {
                $state->getQuery()->where('created_at', '>=', $date);
            }
        }
    }
}
