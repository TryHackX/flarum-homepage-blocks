<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * LIKE-based user filter for partial username matching.
 * Usage: filter[user]=dom → finds discussions by "Dominik"
 *
 * Uses a different filter key ('user') to avoid conflict with
 * Flarum's built-in AuthorFilter ('author') which requires exact usernames.
 */
class UserFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'user';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $value = $this->asString($value);

        if (empty($value)) {
            return;
        }

        // Escape LIKE wildcards in user input
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);

        // whereExists zamiast join('users') — unika błędu „Not unique table 'users'",
        // gdyby inny filtr/searcher też dołączył tabelę users (audyt C7).
        $op = $negate ? 'not like' : 'like';
        $state->getQuery()->whereExists(function ($q) use ($op, $escaped) {
            $q->from('users')
              ->whereColumn('users.id', 'discussions.user_id')
              ->where('users.username', $op, '%' . $escaped . '%');
        });
    }
}
