<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Filter\FilterInterface;
use Flarum\Filter\FilterState;
use Flarum\Filter\ValidateFilterTrait;

/**
 * LIKE-based title filter for partial title matching.
 * Usage: filter[title]=Cap -> finds "Captain Phillips"
 */
class TitleFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'title';
    }

    public function filter(FilterState $state, $value, bool $negate)
    {
        $value = $this->asString($value);

        if (empty($value)) {
            return;
        }

        // Escape LIKE wildcards in user input
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);

        $state->getQuery()->where(
            'discussions.title',
            $negate ? 'not like' : 'like',
            '%' . $escaped . '%'
        );
    }
}
