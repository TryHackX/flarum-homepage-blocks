<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[viewsRange]=100..` — view count (fof/discussion-views; registered only while it is enabled).
 */
class ViewsRangeFilter extends AbstractRangeFilter
{
    public function getFilterKey(): string
    {
        return 'viewsRange';
    }

    protected function id(): string
    {
        return 'views';
    }

    protected function column(): string
    {
        return 'discussions.view_count';
    }
}
