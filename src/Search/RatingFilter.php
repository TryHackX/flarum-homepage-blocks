<?php

namespace TryHackX\HomepageBlocks\Search;

use Carbon\Carbon;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * Filters discussions by the time period in which they were rated.
 * Uses the `last_rated_at` column from tryhackx/flarum-topic-rating.
 */
class RatingFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'ratingInterval';
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
                $state->getQuery()->where('last_rated_at', '<', $date);
            } else {
                $state->getQuery()->where('last_rated_at', '>=', $date);
            }
        }
    }
}
