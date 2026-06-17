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

        // Audyt H2: pomiń bardzo krótkie zapytania. `LIKE '%x%'` ma wiodący wildcard →
        // nie użyje indeksu B-tree (pełny skan tabeli users w podzapytaniu whereExists),
        // a 1–2 znaki i tak nie zawężają sensownie. Próg 3 znaków ucina najgorszy
        // przypadek skanu — autorytatywny limit po stronie serwera (front też nie
        // wysyła krótszych, ale tu jest twarda bramka dla curl/bota).
        if (mb_strlen($value) < 3) {
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
