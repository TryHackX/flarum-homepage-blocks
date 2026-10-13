<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * `filter[mediaType]=movie|series` — what the metadata index recognised from the post
 * (a kind field such as "Serial: TV Series", seasons / episodes values, S01E01 markers).
 * Discussions that aren't releases at all (no size / year / runtime / series data) are
 * neither.
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
        $query->whereIn(
            'discussions.id',
            $this->db->table(MetaIndexer::TABLE)->select('discussion_id')->where('is_series', $prepared === 'series' ? 1 : 0)
        );
    }
}
