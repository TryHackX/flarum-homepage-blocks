<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `filter[clicksRange]=10..` — magnet clicks in the discussion, all magnets together.
 */
class MagnetClicksRangeFilter extends AbstractMagnetClicksFilter
{
    public function getFilterKey(): string
    {
        return 'clicksRange';
    }

    protected function id(): string
    {
        return 'clicks';
    }

    protected function metric(): string
    {
        return 'count(*)';
    }

    protected function grouped(callable $having): QueryBuilder
    {
        $q = $this->clicks()->select('posts.discussion_id')->groupBy('posts.discussion_id');
        $having($q);

        return $q;
    }
}
