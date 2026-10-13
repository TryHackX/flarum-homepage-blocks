<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;

/**
 * Base of the Advanced Filters API filters (`filter[<key>]=<value>`).
 *
 *  - Ignored unless the admin enabled this filter (also for direct API calls).
 *  - A malformed value is ignored rather than turned into an error.
 *  - Negation (`-key:value` in a search) selects everything the filter would NOT.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
abstract class AbstractAdvancedFilter implements FilterInterface
{
    public function __construct(protected AdvancedSettings $adv)
    {
    }

    abstract public function getFilterKey(): string;

    /** Setting id (AdvancedSettings::FILTERS). */
    abstract protected function id(): string;

    /** Parse the raw value; null = unusable (the filter is then skipped). */
    abstract protected function prepare(string $value): mixed;

    /** Constrain the (nested) discussions query with a prepared value. */
    abstract protected function apply(Builder $query, mixed $prepared): void;

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        if (is_array($value) || ! $this->adv->filterEnabled($this->id())) {
            return;
        }
        $value = trim($value, " \"");
        if ($value === '' || strlen($value) > 100) {
            return;
        }
        $prepared = $this->prepare($value);
        if ($prepared === null) {
            return;
        }

        $state->getQuery()->where(fn (Builder $q) => $this->apply($q, $prepared), null, null, $negate ? 'and not' : 'and');
    }
}
