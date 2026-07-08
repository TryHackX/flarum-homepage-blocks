<?php

use Illuminate\Database\Schema\Builder;

/*
 * Sprzątanie po wycięciu funkcji "random" i "Custom Links" (v2.4.0).
 *
 * Ustawienia tych funkcji żyją w rdzeniowej tabeli klucz-wartość `settings`,
 * więc samo usunięcie kodu ich nie kasuje — zostają jako osierocone wiersze.
 * Ta migracja usuwa je przy `php flarum migrate`, więc aktualizacja na docelowej
 * stronie posprząta też bazę. Uruchamiana raz; brak wartości = nic do usunięcia.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'tryhackx-homepage-blocks.random_buttons',
            'tryhackx-homepage-blocks.recaptcha_on_random',
            'tryhackx-homepage-blocks.recaptcha_points_cost_random',
            'tryhackx-homepage-blocks.custom_links',
            'tryhackx-homepage-blocks.custom_links_title',
            'tryhackx-homepage-blocks.custom_links_css',
        ])->delete();
    },

    // Nieodwracalne: usuniętych wartości ustawień nie odtwarzamy (były ustawiane
    // przez administratora, nie mamy ich kopii). Rollback to no-op.
    'down' => function (Builder $schema) {
    },
];
