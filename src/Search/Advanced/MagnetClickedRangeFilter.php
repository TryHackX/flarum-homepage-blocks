<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;

/**
 * `filter[clickedRange]=from..to` — when a magnet of the discussion was last clicked
 * (bounds as in createdRange). Never-clicked discussions don't match. Registered only
 * while tryhackx/flarum-magnet-link is enabled.
 */
class MagnetClickedRangeFilter extends AbstractDateRangeFilter
{
    public function __construct(AdvancedSettings $adv, protected ConnectionInterface $db)
    {
        parent::__construct($adv);
    }

    public function getFilterKey(): string
    {
        return 'clickedRange';
    }

    protected function id(): string
    {
        return 'clicked';
    }

    protected function column(): string
    {
        return 'magnet_clicks.click_time';
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $sub = $this->db->table('magnet_clicks')
            ->join('posts', 'posts.id', '=', 'magnet_clicks.post_id')
            ->select('posts.discussion_id')
            ->groupBy('posts.discussion_id');
        // Raw HAVING: wrap() adds the table prefix and the driver's quoting.
        $column = $this->db->getQueryGrammar()->wrap('magnet_clicks.click_time');
        if ($prepared->min !== null) {
            $sub->havingRaw("max($column) >= ?", [Carbon::createFromTimestamp((int) $prepared->min, 'UTC')->format('Y-m-d H:i:s')]);
        }
        if ($prepared->max !== null) {
            $sub->havingRaw("max($column) <= ?", [Carbon::createFromTimestamp((int) $prepared->max, 'UTC')->format('Y-m-d H:i:s')]);
        }
        $query->whereIn('discussions.id', $sub);
    }
}
