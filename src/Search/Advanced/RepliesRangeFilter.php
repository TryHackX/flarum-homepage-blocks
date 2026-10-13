<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * `filter[repliesRange]=0..10` — number of replies (comment_count also counts the first post).
 */
class RepliesRangeFilter extends AbstractRangeFilter
{
    public function getFilterKey(): string
    {
        return 'repliesRange';
    }

    protected function id(): string
    {
        return 'replies';
    }

    protected function column(): string
    {
        return 'discussions.comment_count';
    }

    protected function constrainRange($query, Range $range): void
    {
        parent::constrainRange($query, new Range(
            $range->min === null ? null : $range->min + 1,
            $range->max === null ? null : $range->max + 1,
        ));
    }
}
