<?php

use Illuminate\Database\Schema\Builder;

/*
 * Sprzątanie po usunięciu reCAPTCHA. Ochronę stanowi teraz WYŁĄCZNIE limiter
 * punktowy per-IP (akcja „search") z egzekwowaniem = tymczasowa blokada IP.
 *
 * Usunięto klasyczny tryb reCAPTCHA, reCAPTCHA v3 oraz tryb captcha przy wyczerpaniu
 * punktów. Poniższe klucze żyją w rdzeniowej tabeli `settings`, więc samo wycięcie
 * kodu ich nie kasuje — ta migracja usuwa je przy `php flarum migrate`.
 *
 * Zostają (limiter dalej ich używa): recaptcha_points_enabled +
 * recaptcha_points_* (start/refill/koszty/blokada), search_debounce_ms.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'tryhackx-homepage-blocks.recaptcha_enabled',
            'tryhackx-homepage-blocks.recaptcha_version',
            'tryhackx-homepage-blocks.recaptcha_site_key',
            'tryhackx-homepage-blocks.recaptcha_secret_key',
            'tryhackx-homepage-blocks.recaptcha_on_stats',
            'tryhackx-homepage-blocks.recaptcha_on_external_stats',
            'tryhackx-homepage-blocks.recaptcha_on_search',
            'tryhackx-homepage-blocks.recaptcha_v3_threshold',
            'tryhackx-homepage-blocks.recaptcha_points_enforcement',
        ])->delete();
    },

    // Nieodwracalne: usuniętych wartości administratora nie odtwarzamy. Rollback = no-op.
    'down' => function (Builder $schema) {
    },
];
