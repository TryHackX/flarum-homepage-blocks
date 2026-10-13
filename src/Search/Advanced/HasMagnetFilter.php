<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\Eloquent\Builder;

/**
 * `filter[hasMagnet]=1` — only discussions with a magnet link (`0` = only without).
 * Reads tryhackx/flarum-magnet-link's `has_magnet_links` column; registered only while
 * that extension is enabled.
 */
class HasMagnetFilter extends AbstractAdvancedFilter
{
    public function getFilterKey(): string
    {
        return 'hasMagnet';
    }

    protected function id(): string
    {
        return 'magnet';
    }

    protected function prepare(string $value): ?int
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes' => 1,
            '0', 'false', 'no' => 0,
            default => null,
        };
    }

    protected function apply(Builder $query, mixed $prepared): void
    {
        $query->where('discussions.has_magnet_links', $prepared);
    }
}
