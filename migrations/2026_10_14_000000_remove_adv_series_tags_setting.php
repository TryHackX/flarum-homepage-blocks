<?php

use Illuminate\Database\Schema\Builder;

/*
 * 2.8.1: the "tags that always mean series" option is gone — the Type filter reads the
 * post itself (kind field, seasons / episodes, S01E01 markers), tags play no part.
 * Drop its stored value.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'tryhackx-homepage-blocks.adv_series_tags')->delete();
    },

    // Nothing to restore: the option no longer exists.
    'down' => function (Builder $schema) {
    },
];
