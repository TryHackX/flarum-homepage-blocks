<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extension\ExtensionManager;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterManager;
use Flarum\Search\SearchCriteria;
use Flarum\Tags\Search\HideHiddenTagsFromAllDiscussionsPage;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;

/**
 * Faceted tag counts for the Advanced Filters "Category" and "Resolution" lists.
 *
 * The lists used to show each tag's global `discussion_count`, which ignores every
 * other active filter — pick a resolution, a time interval or a title and a
 * category still claimed "(12)" while the list came back empty. Here each option's
 * number is exactly what the discussion list returns when that option is picked
 * together with all the OTHER active filters:
 *
 *   - category counts = discussions matching every filter except the category,
 *     grouped by tag;
 *   - resolution counts = the same, except the resolution.
 *
 * The filters are applied through the very same {@see FilterManager} set-up as
 * core's DiscussionSearcher (core + Tags + this extension's title/user/interval
 * filters, + anything other extensions registered), on the same
 * `Discussion::whereVisibleTo($actor)` base query, so counts can't drift from the
 * list and never include discussions the actor can't see.
 */
class FacetCounter
{
    /** Filters the Advanced Filters panel may send (besides the two tag facets). */
    public const FILTER_KEYS = ['title', 'user', 'dateInterval', 'ratingInterval'];

    /** Seconds a result is cached (per guest-or-user + filter set). */
    public const CACHE_TTL = 60;

    public function __construct(
        protected Container $container,
        protected ConnectionInterface $db,
        protected ExtensionManager $extensions,
        protected Cache $cache
    ) {
    }

    /**
     * @param array<string, string> $filters  normalised by {@see normaliseFilters()}
     * @return array{category: array{total: int, counts: array<string, int>}, resolution: array{total: int, counts: array<string, int>}}
     */
    public function count(User $actor, array $filters, ?string $category, ?string $resolution): array
    {
        $compute = function () use ($actor, $filters, $category, $resolution) {
            $visibleTags = Tag::query()->whereVisibleTo($actor)->pluck('id')->all();

            $categoryFacet = $this->facet($actor, $this->withTags($filters, $resolution), $visibleTags);
            $resolutionFacet = $category === $resolution
                ? $categoryFacet
                : $this->facet($actor, $this->withTags($filters, $category), $visibleTags);

            return ['category' => $categoryFacet, 'resolution' => $resolutionFacet];
        };

        // Text searches are near-unique (and rate limited): caching them would only
        // litter the cache with one-off entries. The tag / interval combinations
        // every visitor of the homepage repeats are what's worth caching.
        if (self::isHeavy($filters)) {
            return $compute();
        }

        $key = 'tryhackx-homepage-blocks.facets.' . sha1(json_encode([
            $actor->id ? (int) $actor->id : 'guest',
            $filters,
            $category,
            $resolution,
        ]));

        return $this->cache->remember($key, self::CACHE_TTL, $compute);
    }

    /**
     * Keep only the known filters, as trimmed, length-capped strings; drop empties.
     *
     * @return array<string, string>
     */
    public static function normaliseFilters(mixed $raw): array
    {
        $out = [];
        if (! is_array($raw)) {
            return $out;
        }

        foreach (self::FILTER_KEYS as $key) {
            $value = $raw[$key] ?? null;
            if (is_string($value) && ($value = trim($value)) !== '' && $value !== '0') {
                $out[$key] = mb_substr($value, 0, 100);
            }
        }

        return $out;
    }

    /**
     * A tag slug from the request, or null for "all" / anything malformed.
     */
    public static function normaliseSlug(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '' || $raw === 'all' || mb_strlen($raw) > 100) {
            return null;
        }

        return $raw;
    }

    /**
     * Does this filter set run the expensive LIKE '%…%' filters (same 3-character
     * threshold as TitleFilter / UserFilter)? Those go through the rate limiter.
     */
    public static function isHeavy(array $filters): bool
    {
        return mb_strlen($filters['title'] ?? '') >= 3 || mb_strlen($filters['user'] ?? '') >= 3;
    }

    /**
     * @return array{total: int, counts: array<string, int>}
     */
    protected function facet(User $actor, array $filters, array $visibleTagIds): array
    {
        $state = $this->filteredState($actor, $filters);

        // A clone: total() below still needs the filtered query as it is.
        $ids = (clone $state->getQuery()->toBase())->select('discussions.id');

        $counts = $this->db->table('discussion_tag')
            ->whereIn('discussion_id', $ids)
            ->whereIn('tag_id', $visibleTagIds ?: [0])
            ->groupBy('tag_id')
            ->selectRaw('tag_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'tag_id')
            ->mapWithKeys(fn ($count, $tagId) => [(string) $tagId => (int) $count])
            ->all();

        return ['total' => $this->total($actor, $filters, $state), 'counts' => $counts];
    }

    /**
     * The "All" figure — the list total with no tag picked on this facet.
     */
    protected function total(User $actor, array $filters, DatabaseSearchState $state): int
    {
        // With no filter at all, Tags leaves discussions that are only in hidden
        // tags out of the "All discussions" list — mirror that. (The per-tag counts
        // above don't: picking a tag switches that rule off for the list too.)
        if (! $state->getActiveFilters() && class_exists(HideHiddenTagsFromAllDiscussionsPage::class)
            && $this->extensions->isEnabled('flarum-tags')) {
            $state = $this->filteredState($actor, $filters);
            (new HideHiddenTagsFromAllDiscussionsPage())($state, new SearchCriteria($actor, $filters));
        }

        return (int) $state->getQuery()->toBase()->getCountForPagination();
    }

    protected function filteredState(User $actor, array $filters): DatabaseSearchState
    {
        $state = new DatabaseSearchState($actor, false);
        $state->setQuery($this->container->make(DiscussionSearcher::class)->getQuery($actor));

        $this->filterManager()->apply($state, $filters);

        return $state;
    }

    /**
     * The discussion FilterManager exactly as core builds it for DiscussionSearcher
     * (SearchServiceProvider::boot) — every filter registered through
     * Extend\SearchDriver included — since that instance itself isn't reachable.
     */
    protected function filterManager(): FilterManager
    {
        $fulltextClass = Arr::get($this->container->make('flarum.search.fulltext'), DiscussionSearcher::class);
        $manager = new FilterManager($fulltextClass ? $this->container->make($fulltextClass) : null);

        foreach (Arr::get($this->container->make('flarum.search.filters'), DiscussionSearcher::class, []) as $filter) {
            $manager->add($this->container->make($filter));
        }

        return $manager;
    }

    /**
     * Add one picked tag (the other facet's selection) to the filters, the way the
     * frontend does for the list (`filter[tag][]=slug`).
     */
    protected function withTags(array $filters, ?string $slug): array
    {
        if ($slug !== null) {
            $filters['tag'] = [$slug];
        }

        return $filters;
    }
}
