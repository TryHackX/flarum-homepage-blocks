<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Filter\FilterInterface;
use Flarum\Filter\FilterState;
use Flarum\Filter\ValidateFilterTrait;

/**
 * LIKE-based user filter for partial username matching.
 * Usage: filter[user]=dom -> finds discussions by "Dominik"
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

    public function filter(FilterState $state, $value, bool $negate)
    {
        $value = $this->asString($value);

        if (empty($value)) {
            return;
        }

        // Escape LIKE wildcards in user input
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);

        $query = $state->getQuery();

        // Join users table to search by username
        $query->join('users', 'users.id', '=', 'discussions.user_id')
              ->where(
                  'users.username',
                  $negate ? 'not like' : 'like',
                  '%' . $escaped . '%'
              );
    }
}
