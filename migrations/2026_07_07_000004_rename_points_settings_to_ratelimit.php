<?php

use Illuminate\Database\Schema\Builder;

/*
 * Rename ustawień limitera do czystego namespace `ratelimit_*` — porzucamy mylący
 * prefiks `recaptcha_points_` (relikt po usuniętej reCAPTCHA). WARTOŚCI są przenoszone,
 * więc istniejąca konfiguracja budżetu/blokady zostaje zachowana.
 */
$map = [
    'recaptcha_points_enabled'         => 'ratelimit_enabled',
    'recaptcha_points_start'           => 'ratelimit_start',
    'recaptcha_points_refill_seconds'  => 'ratelimit_refill_seconds',
    'recaptcha_points_refill_amount'   => 'ratelimit_refill_amount',
    'recaptcha_points_guest_extra'     => 'ratelimit_guest_extra',
    'recaptcha_points_cost_search'     => 'ratelimit_cost_search',
    'recaptcha_points_block_seconds'   => 'ratelimit_block_seconds',
    'recaptcha_points_block_reset'     => 'ratelimit_block_reset',
];

$rename = function (Builder $schema, array $pairs): void {
    $conn = $schema->getConnection();
    foreach ($pairs as $old => $new) {
        $oldKey = 'tryhackx-homepage-blocks.' . $old;
        $newKey = 'tryhackx-homepage-blocks.' . $new;

        // ŚWIEŻY builder na KAŻDĄ operację. Laravelowy ->where() MUTUJE builder i
        // zwraca ten sam obiekt, więc współdzielenie jednego akumulowałoby warunki
        // między iteracjami (i wcześniej psuło rename).
        $row = $conn->table('settings')->where('key', $oldKey)->first();
        if ($row === null) {
            continue;
        }
        // Skopiuj wartość pod nowy klucz (nie nadpisuj, jeśli nowy już istnieje), skasuj stary.
        if (! $conn->table('settings')->where('key', $newKey)->exists()) {
            $conn->table('settings')->insert(['key' => $newKey, 'value' => $row->value]);
        }
        $conn->table('settings')->where('key', $oldKey)->delete();
    }
};

return [
    'up' => function (Builder $schema) use ($rename, $map) {
        $rename($schema, $map);
    },
    'down' => function (Builder $schema) use ($rename, $map) {
        $rename($schema, array_flip($map));
    },
];
