<?php

namespace TryHackX\HomepageBlocks\Advanced;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The Advanced Filters switches, in one place (filters, listeners, facets, the forum
 * payload and the admin endpoints all ask here).
 *
 *   adv_enabled            master switch — off = nothing changes for the forum (default)
 *   adv_f_<id>             each filter on its own (ids below)
 *   adv_type_placement     'button' (switch next to "More filters", default) | 'main'
 *                          (select between Resolution and Direction) | 'panel'
 *   adv_type_label         custom name of the Type field (empty = translation)
 *   adv_series_tags        tag ids that always mean "series" (comma separated)
 *   adv_sort_size / _year  extra sort options
 *   adv_open_default       "More filters" starts expanded
 *
 * A filter that needs another extension is only "enabled" while that extension is.
 */
final class AdvancedSettings
{
    public const P = 'tryhackx-homepage-blocks.';

    /** Every filter id, in the order the panel shows them. */
    public const FILTERS = [
        'type', 'created', 'active', 'views', 'rating', 'rating_count', 'replies',
        'size', 'seasons', 'episodes', 'year', 'duration', 'imdb', 'filmweb', 'magnet',
        'steam', 'rated', 'clicks', 'clicks_top', 'clicked',
    ];

    /** Filters / sorts that read the metadata index (they need indexing). */
    public const META_FILTERS = ['type', 'size', 'seasons', 'episodes', 'year', 'duration', 'imdb', 'filmweb'];

    /** Filter id → extension it needs. */
    public const REQUIRES = [
        'views' => 'fof-discussion-views',
        'rating' => 'tryhackx-topic-rating',
        'rating_count' => 'tryhackx-topic-rating',
        'steam' => 'tryhackx-topic-rating',
        'rated' => 'tryhackx-topic-rating',
        'magnet' => 'tryhackx-magnet-link',
        'clicks' => 'tryhackx-magnet-link',
        'clicks_top' => 'tryhackx-magnet-link',
        'clicked' => 'tryhackx-magnet-link',
    ];

    /** Where the Type (movies / series) control goes. */
    public const TYPE_PLACEMENTS = ['button', 'main', 'panel'];

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions,
    ) {
    }

    public function enabled(): bool
    {
        return $this->flag('adv_enabled');
    }

    public function filterEnabled(string $id): bool
    {
        return $this->enabled() && in_array($id, self::FILTERS, true) && $this->flag('adv_f_' . $id) && $this->available($id);
    }

    /** Is the extension this filter needs (if any) enabled? */
    public function available(string $id): bool
    {
        $needs = self::REQUIRES[$id] ?? null;

        return $needs === null || $this->extensions->isEnabled($needs);
    }

    public function sortEnabled(string $id): bool
    {
        return $this->enabled() && $this->flag('adv_sort_' . $id);
    }

    /** Should the index be kept up to date (some filter or sort reads it)? */
    public function indexingEnabled(): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        foreach (self::META_FILTERS as $id) {
            if ($this->flag('adv_f_' . $id)) {
                return true;
            }
        }

        return $this->flag('adv_sort_size') || $this->flag('adv_sort_year');
    }

    /** KB/MB/GB/TB as powers of 1024 (default) or of 1000 — same as the index parser. */
    public function binaryUnits(): bool
    {
        return $this->settings->get(self::P . 'meta_size_units') !== 'decimal';
    }

    /** @return int[] */
    public function seriesTagIds(): array
    {
        $raw = (string) $this->settings->get(self::P . 'adv_series_tags', '');
        $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw) ?: []), fn (int $id) => $id > 0);

        return array_values(array_unique(array_slice($ids, 0, 200)));
    }

    /**
     * What the forum needs (serialized as `tryhackxHomepageAdv`) — switches and labels
     * only; parser rules stay server-side. Null while the feature is off.
     */
    public function forumPayload(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $filters = [];
        foreach (self::FILTERS as $id) {
            if ($this->filterEnabled($id)) {
                $filters[] = $id;
            }
        }

        return [
            'filters' => $filters,
            'typePlacement' => in_array($placement = $this->settings->get(self::P . 'adv_type_placement'), self::TYPE_PLACEMENTS, true) ? $placement : 'button',
            'typeLabel' => mb_substr(trim((string) $this->settings->get(self::P . 'adv_type_label', '')), 0, 60),
            'openDefault' => $this->flag('adv_open_default'),
            'sorts' => array_values(array_filter(['size', 'year'], fn (string $id) => $this->sortEnabled($id))),
            'binaryUnits' => $this->binaryUnits(),
        ];
    }

    private function flag(string $key): bool
    {
        $value = $this->settings->get(self::P . $key);

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
