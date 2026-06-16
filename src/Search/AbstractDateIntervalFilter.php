<?php

namespace TryHackX\HomepageBlocks\Search;

use Carbon\Carbon;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * Wspólna logika filtra „przedział czasu" na kolumnie datetime dyskusji.
 *
 * Podklasy podają tylko klucz filtra i kolumnę — wcześniej {@see DateIntervalFilter}
 * (created_at) i {@see RatingFilter} (last_rated_at) były bliźniaczo identyczne
 * poza tymi dwoma rzeczami, więc każda zmiana opcji/operatora groziła dryfem
 * między nimi (audyt H4).
 */
abstract class AbstractDateIntervalFilter implements FilterInterface
{
    use ValidateFilterTrait;

    abstract public function getFilterKey(): string;

    /** Kolumna datetime, po której filtrujemy. */
    abstract protected function getColumn(): string;

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $date = match ($this->asString($value)) {
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
            $state->getQuery()->where($this->getColumn(), $negate ? '<' : '>=', $date);
        }
    }
}
