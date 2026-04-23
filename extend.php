<?php

use Flarum\Extend;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Settings\SettingsRepositoryInterface;
use TryHackX\HomepageBlocks\Api\Controller\CheckPointsController;
use TryHackX\HomepageBlocks\Api\Controller\RandomDiscussionController;
use TryHackX\HomepageBlocks\Api\Controller\TrackerStatsController;
use TryHackX\HomepageBlocks\Search\RatingFilter;
use TryHackX\HomepageBlocks\Search\DateIntervalFilter;
use TryHackX\HomepageBlocks\Search\TitleFilter;
use TryHackX\HomepageBlocks\Search\UserFilter;

/**
 * Helper: replace min/max validation rules on a field using Reflection.
 * Flarum's Schema fields accumulate rules via rule(), and there's no public way
 * to remove existing rules. We use Reflection to filter and replace them safely.
 */
function replaceMinMax($field, ?int $min, ?int $max): mixed
{
    try {
        // Access the protected $rules array
        $ref = new ReflectionProperty($field, 'rules');
        $rules = $ref->getValue($field);

        // Filter out existing min: and max: rules
        $rules = array_filter($rules, function ($rule) {
            $r = $rule['rule'] ?? '';
            return !str_starts_with($r, 'min:') && !str_starts_with($r, 'max:');
        });

        $ref->setValue($field, array_values($rules));

        // Add new rules
        if ($min !== null) {
            $field->minLength($min);
        }
        if ($max !== null) {
            $field->maxLength($max);
        }
    } catch (\Throwable $e) {
        // If reflection fails, don't break anything — just return the original field
    }

    return $field;
}

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/resources/less/admin.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    (new Extend\Routes('api'))
        ->get('/tryhackx/homepage/random', 'tryhackx.homepage.random', RandomDiscussionController::class)
        ->get('/tryhackx/homepage/stats', 'tryhackx.homepage.stats', TrackerStatsController::class)
        ->get('/tryhackx/homepage/points/check', 'tryhackx.homepage.points.check', CheckPointsController::class),

    (new Extend\Settings())
        ->serializeToForum('tryhackx-homepage-blocks.section1_enabled', 'tryhackx-homepage-blocks.section1_enabled', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.section2_enabled', 'tryhackx-homepage-blocks.section2_enabled', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.section1_title', 'tryhackx-homepage-blocks.section1_title')
        ->serializeToForum('tryhackx-homepage-blocks.section2_title', 'tryhackx-homepage-blocks.section2_title')
        ->serializeToForum('tryhackx-homepage-blocks.section1_collapsed', 'tryhackx-homepage-blocks.section1_collapsed', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.random_buttons', 'tryhackx-homepage-blocks.random_buttons')
        ->serializeToForum('tryhackx-homepage-blocks.tracker_message', 'tryhackx-homepage-blocks.tracker_message')
        ->serializeToForum('tryhackx-homepage-blocks.tracker_sub_message', 'tryhackx-homepage-blocks.tracker_sub_message')
        ->serializeToForum('tryhackx-homepage-blocks.tracker_urls', 'tryhackx-homepage-blocks.tracker_urls')
        ->serializeToForum('tryhackx-homepage-blocks.stats_enabled', 'tryhackx-homepage-blocks.stats_enabled', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.stats_title', 'tryhackx-homepage-blocks.stats_title')
        ->serializeToForum('tryhackx-homepage-blocks.external_stats_enabled', 'tryhackx-homepage-blocks.external_stats_enabled', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.external_stats_url', 'tryhackx-homepage-blocks.external_stats_url')
        ->serializeToForum('tryhackx-homepage-blocks.custom_links', 'tryhackx-homepage-blocks.custom_links')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_enabled', 'tryhackx-homepage-blocks.recaptcha_enabled', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_site_key', 'tryhackx-homepage-blocks.recaptcha_site_key')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_version', 'tryhackx-homepage-blocks.recaptcha_version')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_on_random', 'tryhackx-homepage-blocks.recaptcha_on_random', function ($value) {
            // Default ON when unset; explicitly false only when saved as false
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_on_stats', 'tryhackx-homepage-blocks.recaptcha_on_stats', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_on_external_stats', 'tryhackx-homepage-blocks.recaptcha_on_external_stats', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_skip_authenticated', 'tryhackx-homepage-blocks.recaptcha_skip_authenticated', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_v3_threshold', 'tryhackx-homepage-blocks.recaptcha_v3_threshold')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_on_search', 'tryhackx-homepage-blocks.recaptcha_on_search', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.search_debounce_ms', 'tryhackx-homepage-blocks.search_debounce_ms')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_enabled', 'tryhackx-homepage-blocks.recaptcha_points_enabled', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_start', 'tryhackx-homepage-blocks.recaptcha_points_start')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_refill_seconds', 'tryhackx-homepage-blocks.recaptcha_points_refill_seconds')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_refill_amount', 'tryhackx-homepage-blocks.recaptcha_points_refill_amount')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_guest_extra', 'tryhackx-homepage-blocks.recaptcha_points_guest_extra')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_cost_random', 'tryhackx-homepage-blocks.recaptcha_points_cost_random')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_cost_search', 'tryhackx-homepage-blocks.recaptcha_points_cost_search')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_cost_stats', 'tryhackx-homepage-blocks.recaptcha_points_cost_stats')
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_cost_external_stats', 'tryhackx-homepage-blocks.recaptcha_points_cost_external_stats')
        ->serializeToForum('tryhackx-homepage-blocks.hide_hero', 'tryhackx-homepage-blocks.hide_hero', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.show_only_used_tags', 'tryhackx-homepage-blocks.show_only_used_tags', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.show_tag_count', 'tryhackx-homepage-blocks.show_tag_count', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.external_stats_refresh', 'tryhackx-homepage-blocks.external_stats_refresh')
        ->serializeToForum('tryhackx-homepage-blocks.theme_mode', 'tryhackx-homepage-blocks.theme_mode')
        ->serializeToForum('tryhackx-homepage-blocks.category_label', 'tryhackx-homepage-blocks.category_label')
        ->serializeToForum('tryhackx-homepage-blocks.resolution_label', 'tryhackx-homepage-blocks.resolution_label'),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(DiscussionSearcher::class, RatingFilter::class)
        ->addFilter(DiscussionSearcher::class, DateIntervalFilter::class)
        ->addFilter(DiscussionSearcher::class, TitleFilter::class)
        ->addFilter(DiscussionSearcher::class, UserFilter::class),

    // ── Discussion title length override ──
    (new Extend\ApiResource(DiscussionResource::class))
        ->sorts(fn () => [
            SortColumn::make('rating_average')
                ->ascendingAlias('least_rated')
                ->descendingAlias('most_rated'),
            SortColumn::make('rating_count')
                ->descendingAlias('most_rating_count')
                ->ascendingAlias('least_rating_count'),
            SortColumn::make('last_rated_at')
                ->descendingAlias('recently_rated')
                ->ascendingAlias('oldest_rated'),
        ])
        ->field('title', function ($field) {
            $settings = resolve(SettingsRepositoryInterface::class);
            if (!(bool) $settings->get('tryhackx-homepage-blocks.title_length_enabled')) {
                return $field;
            }
            // Clamp: min >= 1, max <= 200 (varchar(200) column)
            $min = max(1, (int) ($settings->get('tryhackx-homepage-blocks.title_min_length') ?: 3));
            $max = min(200, max($min, (int) ($settings->get('tryhackx-homepage-blocks.title_max_length') ?: 200)));
            return replaceMinMax($field, $min, $max);
        })
        ->field('content', function ($field) {
            // Discussion content field (used when creating a discussion)
            $settings = resolve(SettingsRepositoryInterface::class);
            if (!(bool) $settings->get('tryhackx-homepage-blocks.content_length_enabled')) {
                return $field;
            }
            // Clamp: min >= 0, max <= 16000000 (mediumtext column)
            $min = max(0, (int) ($settings->get('tryhackx-homepage-blocks.content_min_length') ?: 1));
            $max = min(16000000, max($min, (int) ($settings->get('tryhackx-homepage-blocks.content_max_length') ?: 500000)));
            return replaceMinMax($field, $min, $max);
        }),

    // ── Post content length override ──
    (new Extend\ApiResource(PostResource::class))
        ->field('content', function ($field) {
            $settings = resolve(SettingsRepositoryInterface::class);
            if (!(bool) $settings->get('tryhackx-homepage-blocks.content_length_enabled')) {
                return $field;
            }
            $min = max(0, (int) ($settings->get('tryhackx-homepage-blocks.content_min_length') ?: 1));
            $max = min(16000000, max($min, (int) ($settings->get('tryhackx-homepage-blocks.content_max_length') ?: 500000)));
            return replaceMinMax($field, $min, $max);
        }),
];
