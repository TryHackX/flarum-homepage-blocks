<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchCriteria;
use TryHackX\HomepageBlocks\Sort\SteamRatingSort;

/**
 * Makes the "Steam DB" rating sort actually order the discussion list.
 *
 * Flarum lists discussions through its database Search, whose `applySort()`
 * orders by `Str::snake($field)` as a *column* — there is no `steam_rating`
 * column, so the bare query would fail. This mutator runs *after* `applySort()`
 * (see AbstractSearcher::search), detects the virtual `steamRating` sort field,
 * drops the bogus column order and replaces it with the SteamDB expression from
 * {@see SteamRatingSort::expression()}, plus a stable id tie-breaker.
 *
 * The `SteamRatingSort` registered on the DiscussionResource still provides the
 * field validity / friendly aliases; this mutator only supplies the ordering for
 * the Search path. Mirrors magnet-link's MagnetClicksSortMutator.
 */
class SteamRatingSortMutator
{
    public function __invoke(DatabaseSearchState $state, SearchCriteria $criteria): void
    {
        $sort = $criteria->sort;

        if (! is_array($sort)) {
            return;
        }

        foreach ($sort as $field => $order) {
            if ($field !== 'steamRating') {
                continue;
            }

            $direction = (is_string($order) && strtolower($order) === 'asc') ? 'asc' : 'desc';

            $state->getQuery()
                ->reorder()
                ->orderByRaw(SteamRatingSort::expression().' '.$direction)
                ->orderBy('discussions.id', 'desc');

            return;
        }
    }
}
