<?php

use Illuminate\Database\Schema\Builder;

/*
 * Usunięcie ustawienia „pomiń limit dla zalogowanych" (recaptcha_skip_authenticated).
 * Zbędne: dopłata dla gości (recaptcha_points_guest_extra) i tak różnicuje koszt,
 * a limitowani są teraz WSZYSCY (goście drożej). Klucz żyje w rdzeniowej tabeli
 * `settings`, więc usuwamy go tą migracją przy `php flarum migrate`.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'tryhackx-homepage-blocks.recaptcha_skip_authenticated',
        ])->delete();
    },

    // Nieodwracalne: usuniętej wartości administratora nie odtwarzamy. Rollback = no-op.
    'down' => function (Builder $schema) {
    },
];
