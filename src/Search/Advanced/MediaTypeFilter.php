<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * `filter[mediaType]=movie|series`.
 *
 *  - series: the index says series, OR the discussion has one of the admin's
 *    "series tags";
 *  - movie:  the index says movie (a release that is not a series) AND it has none
 *    of those tags.
 *
 * Discussions that aren't releases at all (no size / year / runtime / series data)
 * are neither.
 */
class MediaTypeFilter extends AbstractAdvancedFilter
{
    public function __construct(AdvancedSettings $adv, protected ConnectionInterface $db)
    {
        parent::__construct($adv);
    }

    public function getFilterKey(): string
    {
        return 'mediaType';
    }

    protected function id(): string
    {
        return 'type';
    }

    protected function prepare(string $value): ?string
    {
        $value = strtolower($value);

        return in_array($value, ['movie', 'series'], true) ? $value : null;
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $series = $prepared === 'series';
        $meta = $this->db->table(MetaIndexer::TABLE)->select('discussion_id')->where('is_series', $series ? 1 : 0);
        $tags = $this->adv->seriesTagIds();
        $tagged = $tags ? $this->db->table('discussion_tag')->select('discussion_id')->whereIn('tag_id', $tags) : null;

        if ($series) {
            $query->where(function (Builder $q) use ($meta, $tagged) {
                $q->whereIn('discussions.id', $meta);
                if ($tagged) {
                    $q->orWhereIn('discussions.id', $tagged);
                }
            });
        } else {
            $query->whereIn('discussions.id', $meta);
            if ($tagged) {
                $query->whereNotIn('discussions.id', $tagged);
            }
        }
    }
}
