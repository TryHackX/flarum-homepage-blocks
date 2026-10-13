<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

use TryHackX\HomepageBlocks\Meta\MetaExtractor;

/**
 * `filter[sizeRange]=10GB..2TB` — release size (index). Each bound is a number with an
 * optional unit (B, KB/KiB … TB/TiB; none = GB), converted exactly like the index was
 * (binary or decimal units, admin setting).
 */
class SizeRangeFilter extends AbstractMetaRangeFilter
{
    public function getFilterKey(): string
    {
        return 'sizeRange';
    }

    protected function id(): string
    {
        return 'size';
    }

    protected function column(): string
    {
        return 'size_bytes';
    }

    protected function bounds(): array
    {
        return [0, MetaExtractor::MAX_SIZE_BYTES];
    }

    protected function parseSide(string $side, bool $isMax): int|float|null
    {
        if (! preg_match('/^(\d{1,12}(?:[.,]\d{1,6})?)\s*([KMGT]i?B|B)?$/i', $side, $m)) {
            return null;
        }
        $factor = MetaExtractor::unitFactor(($m[2] ?? '') !== '' ? $m[2] : 'GB', $this->adv->binaryUnits());
        $value = (float) str_replace(',', '.', $m[1]) * (float) $factor;

        return $value >= MetaExtractor::MAX_SIZE_BYTES ? MetaExtractor::MAX_SIZE_BYTES : (int) round($value);
    }
}
