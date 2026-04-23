<?php

use Flarum\Extend;
use Flarum\Api\Controller\ListDiscussionsController;
use Flarum\Discussion\Filter\DiscussionFilterer;
use Flarum\Settings\SettingsRepositoryInterface;
use TryHackX\HomepageBlocks\Api\Controller\CheckPointsController;
use TryHackX\HomepageBlocks\Api\Controller\RandomDiscussionController;
use TryHackX\HomepageBlocks\Api\Controller\TrackerStatsController;
use TryHackX\HomepageBlocks\Search\RatingFilter;
use TryHackX\HomepageBlocks\Search\DateIntervalFilter;
use TryHackX\HomepageBlocks\Search\TitleFilter;
use TryHackX\HomepageBlocks\Search\UserFilter;

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

    (new Extend\Filter(DiscussionFilterer::class))
        ->addFilter(RatingFilter::class)
        ->addFilter(DateIntervalFilter::class)
        ->addFilter(TitleFilter::class)
        ->addFilter(UserFilter::class),

    // ── Sort columns for discussion list ──
    (new Extend\ApiController(ListDiscussionsController::class))
        ->addSortField('rating_average')
        ->addSortField('rating_count')
        ->addSortField('last_rated_at'),

    // ── Discussion title length override ──
    (new Extend\Validator(\Flarum\Discussion\DiscussionValidator::class))
        ->configure(function ($flarumValidator, $validator) {
            $settings = resolve(SettingsRepositoryInterface::class);

            // Title length override
            if ((bool) $settings->get('tryhackx-homepage-blocks.title_length_enabled')) {
                $min = max(1, (int) ($settings->get('tryhackx-homepage-blocks.title_min_length') ?: 3));
                $max = min(200, max($min, (int) ($settings->get('tryhackx-homepage-blocks.title_max_length') ?: 200)));

                $rules = $validator->getRules();
                if (isset($rules['title'])) {
                    $rules['title'] = array_values(array_filter($rules['title'], function ($rule) {
                        return strpos($rule, 'min:') !== 0 && strpos($rule, 'max:') !== 0;
                    }));
                    $rules['title'][] = 'min:' . $min;
                    $rules['title'][] = 'max:' . $max;
                    $validator->setRules($rules);
                }
            }

            // Content length override (discussion creation includes content)
            if ((bool) $settings->get('tryhackx-homepage-blocks.content_length_enabled')) {
                $min = max(0, (int) ($settings->get('tryhackx-homepage-blocks.content_min_length') ?: 1));
                $max = min(16000000, max($min, (int) ($settings->get('tryhackx-homepage-blocks.content_max_length') ?: 500000)));

                $rules = $validator->getRules();
                if (isset($rules['content'])) {
                    $rules['content'] = array_values(array_filter($rules['content'], function ($rule) {
                        return strpos($rule, 'min:') !== 0 && strpos($rule, 'max:') !== 0;
                    }));
                    $rules['content'][] = 'min:' . $min;
                    $rules['content'][] = 'max:' . $max;
                    $validator->setRules($rules);
                }
            }
        }),

    // ── Post content length override ──
    (new Extend\Validator(\Flarum\Post\PostValidator::class))
        ->configure(function ($flarumValidator, $validator) {
            $settings = resolve(SettingsRepositoryInterface::class);

            if ((bool) $settings->get('tryhackx-homepage-blocks.content_length_enabled')) {
                $min = max(0, (int) ($settings->get('tryhackx-homepage-blocks.content_min_length') ?: 1));
                $max = min(16000000, max($min, (int) ($settings->get('tryhackx-homepage-blocks.content_max_length') ?: 500000)));

                $rules = $validator->getRules();
                if (isset($rules['content'])) {
                    $rules['content'] = array_values(array_filter($rules['content'], function ($rule) {
                        return strpos($rule, 'min:') !== 0 && strpos($rule, 'max:') !== 0;
                    }));
                    $rules['content'][] = 'min:' . $min;
                    $rules['content'][] = 'max:' . $max;
                    $validator->setRules($rules);
                }
            }
        }),
];
