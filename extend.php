<?php

use Flarum\Extend;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;
use TryHackX\HomepageBlocks\Api\FieldLengthModifier;
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
        ->serializeToForum('tryhackx-homepage-blocks.custom_links_title', 'tryhackx-homepage-blocks.custom_links_title')
        ->serializeToForum('tryhackx-homepage-blocks.custom_links_css', 'tryhackx-homepage-blocks.custom_links_css')
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
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_on_search', 'tryhackx-homepage-blocks.recaptcha_on_search', function ($value) {
            return $value === null ? true : (bool) $value;
        })
        ->serializeToForum('tryhackx-homepage-blocks.search_debounce_ms', 'tryhackx-homepage-blocks.search_debounce_ms')
        // Tylko sam fakt włączenia limitera trafia do frontendu (decyduje, czy robić
        // pre-flight). Ekonomia punktów (start/refill/koszty/blokada) jest WYŁĄCZNIE
        // serwerowa — nie ujawniamy jej klientom (audyt A4).
        ->serializeToForum('tryhackx-homepage-blocks.recaptcha_points_enabled', 'tryhackx-homepage-blocks.recaptcha_points_enabled', function ($value) {
            return (bool) $value;
        })
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

    // ── Filtry oparte na rdzeniowych kolumnach (zawsze dostępne) ──
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(DiscussionSearcher::class, DateIntervalFilter::class)
        ->addFilter(DiscussionSearcher::class, TitleFilter::class)
        ->addFilter(DiscussionSearcher::class, UserFilter::class),

    // ── Sortowania/filtry zależne od innych rozszerzeń ──
    // Rejestrujemy je TYLKO gdy dane rozszerzenie jest włączone, więc na forum bez
    // topic-rating / discussion-views użycie ich nie wywróci zapytania (audyt B5).
    // Wystawiamy też flagi do frontendu, by ukrywał niedostępne opcje sortowania.
    (new Extend\Conditional())
        ->whenExtensionEnabled('tryhackx-topic-rating', fn () => [
            (new Extend\ApiResource(DiscussionResource::class))
                ->sorts(fn () => [
                    SortColumn::make('rating_average'),
                    SortColumn::make('rating_count'),
                    SortColumn::make('last_rated_at'),
                ]),
            (new Extend\SearchDriver(DatabaseSearchDriver::class))
                ->addFilter(DiscussionSearcher::class, RatingFilter::class),
            (new Extend\Settings())
                ->serializeToForum('tryhackxHomepageHasRating', 'tryhackx-homepage-blocks.recaptcha_points_enabled', fn () => true),
        ])
        ->whenExtensionEnabled('fof-discussion-views', fn () => [
            (new Extend\Settings())
                ->serializeToForum('tryhackxHomepageHasViews', 'tryhackx-homepage-blocks.recaptcha_points_enabled', fn () => true),
        ]),

    // ── Nadpisanie długości tytułu/treści (dyskusje) ──
    (new Extend\ApiResource(DiscussionResource::class))
        ->field('title', fn ($field) => resolve(FieldLengthModifier::class)->applyTitle($field))
        ->field('content', fn ($field) => resolve(FieldLengthModifier::class)->applyContent($field)),

    // ── Nadpisanie długości treści (posty) ──
    (new Extend\ApiResource(PostResource::class))
        ->field('content', fn ($field) => resolve(FieldLengthModifier::class)->applyContent($field)),
];
