<?php

use Flarum\Extend;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;
use TryHackX\HomepageBlocks\Api\FieldLengthModifier;
use TryHackX\HomepageBlocks\Api\Controller\CheckPointsController;
use TryHackX\HomepageBlocks\Api\Controller\TrackerStatsController;
use TryHackX\HomepageBlocks\Api\Middleware\SearchRateLimitMiddleware;
use TryHackX\HomepageBlocks\Search\RatingFilter;
use TryHackX\HomepageBlocks\Search\DateIntervalFilter;
use TryHackX\HomepageBlocks\Search\TitleFilter;
use TryHackX\HomepageBlocks\Search\UserFilter;
use TryHackX\HomepageBlocks\Provider\StoreProvider;
use TryHackX\HomepageBlocks\Search\SteamRatingSortMutator;
use TryHackX\HomepageBlocks\Sort\SteamRatingSort;

// `FieldLengthModifier` jest związany jako SINGLETON w StoreProvider (jedna instancja
// na żądanie, zarządzana przez kontener — testowalna/podmienialna, audyt H6/H7).
// Callbacki field() nie dostają kontenera, więc pobieramy go tu resolve()em — dzięki
// singletonowi to tani lookup, a NIE budowa instancji per pole dyskusji/posta.
$fieldLength = fn () => resolve(FieldLengthModifier::class);

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/resources/less/admin.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    // Wiąże magazyn limitera punktów i cache statystyk ({@see Store}): natywny
    // plikowy domyślnie, a automatycznie cross-node, gdy Flarum ma współdzielony,
    // lockowalny cache (Redis…). Patrz StoreProvider (audyt #2/#3).
    (new Extend\ServiceProvider())->register(StoreProvider::class),

    // /points/check MUTUJE stan (dekrementuje kubełek punktów per-IP), więc jest
    // POST — GET ma być bezpieczny/idempotentny (RFC 9110), a prefetchery linków /
    // cache / probery CDN mogłyby po cichu drenować budżet użytkownika (audyt H2).
    // /stats to czysty odczyt (cache statystyk) → zostaje GET.
    (new Extend\Routes('api'))
        ->get('/tryhackx/homepage/stats', 'tryhackx.homepage.stats', TrackerStatsController::class)
        ->post('/tryhackx/homepage/points/check', 'tryhackx.homepage.points.check', CheckPointsController::class),

    // Twarda bramka serwerowa limitera na rdzeniowym GET /api/discussions z ciężkim
    // filtrem title/user (LIKE). Pre-flight /points/check to tylko UX — to middleware
    // realnie chroni bazę przed botami/scraperami/floderami (patrz jego docblock).
    // Extend\Middleware('api')->add() wstawia je PO ResolveRoute/PopulateWithActor,
    // więc widzi routeName, aktora i ipAddress.
    (new Extend\Middleware('api'))
        ->add(SearchRateLimitMiddleware::class),

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
        // UWAGA: NIE serializujemy external_stats_native_url do forum — to wewnętrzny
        // URL trackera (może zdradzać prywatny IP:port). Czyta go wyłącznie kontroler
        // po stronie serwera; frontend woła endpoint ?source=external i nigdy nie
        // potrzebuje samego URL-a (audyt: privacy).
        // Frontend potrzebuje tylko opóźnienia debounce wyszukiwania (poza samą
        // flagą włączenia limitera niżej).
        ->serializeToForum('tryhackx-homepage-blocks.search_debounce_ms', 'tryhackx-homepage-blocks.search_debounce_ms')
        // Tylko sam fakt włączenia limitera trafia do frontendu (decyduje, czy robić
        // pre-flight). Ekonomia punktów (start/refill/koszty/blokada) jest WYŁĄCZNIE
        // serwerowa — nie ujawniamy jej klientom (audyt A4).
        ->serializeToForum('tryhackx-homepage-blocks.ratelimit_enabled', 'tryhackx-homepage-blocks.ratelimit_enabled', function ($value) {
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
        // Tylko max_time trafia do frontendu — steruje, jak długo UI trzyma spinner
        // „ładowanie", zanim uzna tracker za niedostępny. Cache-TTL i URL-e zostają
        // serwerowe (URL-e mogą zdradzać prywatny IP:port).
        ->serializeToForum('tryhackx-homepage-blocks.external_stats_max_time', 'tryhackx-homepage-blocks.external_stats_max_time')
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
                    // "Steam DB" confidence-weighted rating (avg + count).
                    SteamRatingSort::make('steamRating'),
                ]),
            (new Extend\SearchDriver(DatabaseSearchDriver::class))
                ->addFilter(DiscussionSearcher::class, RatingFilter::class)
                ->addMutator(DiscussionSearcher::class, SteamRatingSortMutator::class),
            // Sam fakt obecności atrybutu (frontend sprawdza `typeof !== undefined`)
            // sygnalizuje dostępność funkcji. Klucz dedykowany i samodokumentujący —
            // wartość w bazie nie istnieje, callback zawsze zwraca true (audyt D1/#6).
            (new Extend\Settings())
                ->serializeToForum('tryhackxHomepageHasRating', 'tryhackx-homepage-blocks.has_rating', fn () => true),
        ])
        ->whenExtensionEnabled('fof-discussion-views', fn () => [
            (new Extend\Settings())
                ->serializeToForum('tryhackxHomepageHasViews', 'tryhackx-homepage-blocks.has_views', fn () => true),
        ]),

    // ── Nadpisanie długości tytułu/treści (dyskusje) ──
    (new Extend\ApiResource(DiscussionResource::class))
        ->field('title', fn ($field) => $fieldLength()->applyTitle($field))
        ->field('content', fn ($field) => $fieldLength()->applyContent($field)),

    // ── Nadpisanie długości treści (posty) ──
    (new Extend\ApiResource(PostResource::class))
        ->field('content', fn ($field) => $fieldLength()->applyContent($field)),
];
