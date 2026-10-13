<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchCriteria;
use TryHackX\HomepageBlocks\Sort\MetaSort;

/**
 * Orders the discussion list by an Advanced Filters index column ({@see MetaSort}):
 * runs after core's applySort(), drops its order on the (non-existent) `hb_size` /
 * `hb_year` column and puts the index ordering in its place. Always active — see
 * MetaSort for why the admin switch must not turn it off.
 */
class MetaSortMutator
{
    public function __invoke(DatabaseSearchState $state, SearchCriteria $criteria): void
    {
        if (! is_array($criteria->sort)) {
            return;
        }

        foreach ($criteria->sort as $field => $order) {
            if (! isset(MetaSort::COLUMNS[$field])) {
                continue;
            }
            $query = $state->getQuery();
            $query->reorder();
            MetaSort::order($query, $query->getConnection(), $field, is_string($order) ? $order : 'desc');

            return;
        }
    }
}
