<?php

namespace TryHackX\HomepageBlocks\Search;

use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * LIKE-based title filter for partial title matching.
 * Usage: filter[title]=Cap → finds "Captain Phillips"
 */
class TitleFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'title';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $value = $this->asString($value);

        if (empty($value)) {
            return;
        }

        // Audyt H2: pomiń bardzo krótkie zapytania. `LIKE '%x%'` ma wiodący wildcard →
        // nie użyje indeksu B-tree (pełny skan tabeli), a 1–2 znaki i tak nie zawężają
        // sensownie. Próg 3 znaków ucina najgorszy przypadek skanu na każdym
        // debounce'owanym znaku — autorytatywny limit po stronie serwera (front też
        // nie wysyła krótszych, ale tu jest twarda bramka dla curl/bota).
        if (mb_strlen($value) < 3) {
            return;
        }

        // Escape LIKE wildcards in user input
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);

        $state->getQuery()->where(
            'discussions.title',
            $negate ? 'not like' : 'like',
            '%' . $escaped . '%'
        );
    }
}
