<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `filter[clicksTopRange]=10..` — clicks of the discussion's most clicked magnet.
 */
class MagnetTopClicksRangeFilter extends AbstractMagnetClicksFilter
{
    public function getFilterKey(): string
    {
        return 'clicksTopRange';
    }

    protected function id(): string
    {
        return 'clicks_top';
    }

    protected function metric(): string
    {
        return 'max(thx_c)';
    }

    protected function grouped(callable $having): QueryBuilder
    {
        $perMagnet = $this->clicks()
            ->select('posts.discussion_id as thx_did')
            ->selectRaw('count(*) as thx_c')
            ->groupBy('posts.discussion_id', 'magnet_clicks.magnet_link_id');

        $q = $this->db->query()->fromSub($perMagnet, 'thx_pm')->select('thx_did')->groupBy('thx_did');
        $having($q);

        return $q;
    }
}
