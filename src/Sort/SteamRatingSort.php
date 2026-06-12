<?php

namespace TryHackX\HomepageBlocks\Sort;

use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Sort;

/**
 * "Steam DB" rating sort for the discussion list — adapts SteamDB's review-score
 * formula to topic-rating's star system.
 *
 * SteamDB (binary up/down reviews):
 *   ReviewScore = Positive / Total
 *   Rating      = ReviewScore − (ReviewScore − 0.5) · 2^(−log10(Total + 1))
 *
 * Stars have no up/down split, so we map the average star rating onto a 0–1
 * "review score" (5★ ⇒ 1.0) and use the number of ratings as Total:
 *   ReviewScore = rating_average / 5
 *   Total       = rating_count
 * The confidence term pulls thinly-rated topics toward 0.5 and lets heavily-rated
 * ones approach their true average — so a 5★ topic with 100 ratings outranks a
 * 5★ topic with 1 rating. Unrated topics (rating_count = 0) sort last.
 *
 * `2^(−log10(n+1))` is rewritten as `(n+1)^(−log10(2))` = `(n+1)^(−0.30103)` so the
 * SQL needs only POWER() (portable across MySQL/MariaDB/PostgreSQL) — no LOG10().
 * The expression is a fixed literal reading two trusted columns; no user input is
 * interpolated (only the validated asc/desc direction), so it is injection-safe.
 *
 * Both `rating_average` and `rating_count` columns come from
 * `tryhackx/flarum-topic-rating`; this sort is therefore registered only when that
 * extension is enabled (see extend.php `Extend\Conditional`).
 *
 * Like magnet-link's sort, the real ordering for the discussion list is supplied
 * by {@see \TryHackX\HomepageBlocks\Search\SteamRatingSortMutator} (Flarum lists
 * discussions through its database Search, which orders by column name); apply()
 * is a correct fallback for any plain json-api-server listing.
 */
class SteamRatingSort extends Sort
{
    // UWAGA: NIE usuwać $alias / ascendingAlias / descendingAlias / sortMap.
    // Flarum core wywołuje sortMap() przez Api\Resource\Concerns\HasSortMap przy
    // budowaniu mapy sortowań zasobu — brak metody = fatal i HTTP 500 na całym
    // forum. (Audyt floxum błędnie uznał je za martwy kod; patrz HasSortMap.php.)
    protected array $alias = [
        'asc' => null,
        'desc' => null,
    ];

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function ascendingAlias(?string $alias): static
    {
        $this->alias['asc'] = $alias;

        return $this;
    }

    public function descendingAlias(?string $alias): static
    {
        $this->alias['desc'] = $alias;

        return $this;
    }

    public function sortMap(): array
    {
        $map = [];

        foreach ($this->alias as $direction => $alias) {
            if ($alias) {
                $map[$alias] = ($direction === 'asc' ? '' : '-').$this->name;
            }
        }

        return $map;
    }

    public function apply(object $query, string $direction, Context $context): void
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw(self::expression().' '.$direction);
    }

    /**
     * SteamDB-style confidence-adjusted rating, as an ORDER BY expression.
     * Shared with the Search mutator so both code paths agree.
     */
    public static function expression(): string
    {
        return '(case when discussions.rating_count > 0 then '
             . '(discussions.rating_average / 5.0) '
             . '- ((discussions.rating_average / 5.0) - 0.5) '
             . '* power(discussions.rating_count + 1, -0.30103) '
             . 'else -1 end)';
    }
}
