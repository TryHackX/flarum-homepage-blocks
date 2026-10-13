<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;

/**
 * Ranges over tryhackx/flarum-magnet-link's click log (`magnet_clicks` joined to the
 * clicked post) — the same figures its magnet-click sorts use. Registered only while
 * magnet-link is enabled.
 *
 * `discussions.id IN (SELECT discussion_id … GROUP BY … HAVING …)`. A discussion
 * without clicks has no group, so a range that admits 0 clicks is turned around:
 * `NOT IN (… HAVING metric > max)`.
 */
abstract class AbstractMagnetClicksFilter extends AbstractRangeFilter
{
    public function __construct(AdvancedSettings $adv, protected ConnectionInterface $db)
    {
        parent::__construct($adv);
    }

    protected function column(): string
    {
        return 'magnet_clicks.click_time'; // unused: see apply()
    }

    /** Discussions grouped with their metric; $having adds the HAVING clause(s). */
    abstract protected function grouped(callable $having): QueryBuilder;

    /** SQL of the metric inside HAVING. */
    abstract protected function metric(): string;

    protected function apply(Builder $query, mixed $prepared): void
    {
        $metric = $this->metric();
        // Whole numbers, bound as integers: an aggregate has no column affinity, so
        // SQLite would compare count(*) with a '3' / 3.0 string as text (always false).
        $min = $prepared->min === null ? null : (int) ceil($prepared->min);
        $max = $prepared->max === null ? null : (int) floor($prepared->max);

        if (($min === null || $min <= 0) && $max === null) {
            return; // "0.." admits every discussion
        }
        if (($min === null || $min <= 0) && $max !== null) {
            // 0 clicks allowed: exclude the discussions above the maximum.
            $query->whereNotIn('discussions.id', $this->grouped(fn ($q) => $q->havingRaw("$metric > ?", [$max])));

            return;
        }
        $query->whereIn('discussions.id', $this->grouped(function ($q) use ($metric, $min, $max) {
            if ($min !== null) {
                $q->havingRaw("$metric >= ?", [$min]);
            }
            if ($max !== null) {
                $q->havingRaw("$metric <= ?", [$max]);
            }
        }));
    }

    /** magnet_clicks ⋈ posts (prefix-safe: plain table names, no aliases). */
    protected function clicks(): QueryBuilder
    {
        return $this->db->table('magnet_clicks')->join('posts', 'posts.id', '=', 'magnet_clicks.post_id');
    }
}
