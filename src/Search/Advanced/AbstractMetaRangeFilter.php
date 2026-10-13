<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * "min..max" on a column of the Advanced Filters index: `discussions.id IN (SELECT
 * discussion_id FROM homepage_discussion_meta WHERE …)` — a semi-join that starts from
 * the index's column index. Discussions without data (or not indexed) never match.
 * column() is unqualified here (a column of the index table).
 */
abstract class AbstractMetaRangeFilter extends AbstractRangeFilter
{
    public function __construct(AdvancedSettings $adv, protected ConnectionInterface $db)
    {
        parent::__construct($adv);
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $sub = $this->db->table(MetaIndexer::TABLE)->select('discussion_id');
        $this->constrainRange($sub, $prepared);
        $query->whereIn('discussions.id', $sub);
    }
}
