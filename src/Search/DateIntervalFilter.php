<?php

namespace TryHackX\HomepageBlocks\Search;

use Carbon\Carbon;
use Flarum\Filter\FilterInterface;
use Flarum\Filter\FilterState;

/**
 * Filters discussions by creation date interval.
 */
class DateIntervalFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'dateInterval';
    }

    public function filter(FilterState $state, $value, bool $negate)
    {
        switch ((string) $value) {
            case 'today':
                $date = Carbon::today();
                break;
            case '1d':
                $date = Carbon::now()->subDay();
                break;
            case '1w':
                $date = Carbon::now()->subWeek();
                break;
            case '2w':
                $date = Carbon::now()->subWeeks(2);
                break;
            case '1m':
                $date = Carbon::now()->subMonth();
                break;
            case '3m':
                $date = Carbon::now()->subMonths(3);
                break;
            case '6m':
                $date = Carbon::now()->subMonths(6);
                break;
            case '1y':
                $date = Carbon::now()->subYear();
                break;
            default:
                $date = null;
                break;
        }

        if ($date) {
            if ($negate) {
                $state->getQuery()->where('created_at', '<', $date);
            } else {
                $state->getQuery()->where('created_at', '>=', $date);
            }
        }
    }
}
