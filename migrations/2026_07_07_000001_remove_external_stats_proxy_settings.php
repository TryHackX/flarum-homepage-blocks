<?php

use Illuminate\Database\Schema\Builder;

/*
 * Sprzątanie po przejściu statystyk zewnętrznych na TYLKO tryb natywny (OpenTracker XML).
 *
 * Usunięto wybór źródła (`external_stats_mode`) oraz URL proxy JSON (`external_stats_url`).
 * Klucze żyją w rdzeniowej tabeli `settings`, więc samo wycięcie kodu ich nie kasuje —
 * ta migracja usuwa je przy `php flarum migrate` (aktualizacja sprząta też bazę).
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'tryhackx-homepage-blocks.external_stats_mode',
            'tryhackx-homepage-blocks.external_stats_url',
        ])->delete();
    },

    // Nieodwracalne: usuniętych wartości administratora nie odtwarzamy. Rollback = no-op.
    'down' => function (Builder $schema) {
    },
];
