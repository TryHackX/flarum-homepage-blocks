<?php

namespace TryHackX\HomepageBlocks\Search\Advanced;

/**
 * A filter range: "min..max" with either side optional ("5..", "..20", "5..20"), or a
 * single value ("7" = 7..7). Strict: anything else ("1...5", "a..b", "..") is null, so a
 * malformed value is simply ignored. min > max is swapped.
 */
final class Range
{
    public function __construct(
        public readonly int|float|null $min,
        public readonly int|float|null $max,
    ) {
    }

    /**
     * @param callable(string $side, bool $isMax): (int|float|null) $side parses one bound (null = invalid)
     */
    public static function parse(string $raw, callable $side): ?self
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 100) {
            return null;
        }
        $parts = explode('..', $raw);
        if (count($parts) > 2) {
            return null;
        }
        if (count($parts) === 1) {
            $min = $side($parts[0], false);
            $max = $side($parts[0], true);

            return $min === null || $max === null ? null : new self($min, $max);
        }

        $bounds = [];
        foreach ([0 => false, 1 => true] as $i => $isMax) {
            $text = trim($parts[$i]);
            if ($text === '') {
                $bounds[$i] = null;
                continue;
            }
            $bounds[$i] = $side($text, $isMax);
            if ($bounds[$i] === null) {
                return null;
            }
        }
        [$min, $max] = $bounds;
        if ($min === null && $max === null) {
            return null;
        }
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return new self($min, $max);
    }

    /** Plain number, "," or "." as the decimal mark. */
    public static function number(string $text): ?float
    {
        $text = str_replace(',', '.', trim($text));

        return preg_match('/^\d{1,15}(\.\d{1,6})?$/', $text) ? (float) $text : null;
    }

    /** Both bounds clamped into [$lo, $hi]. */
    public function clamp(int|float $lo, int|float $hi): self
    {
        $c = fn ($v) => $v === null ? null : max($lo, min($hi, $v));

        return new self($c($this->min), $c($this->max));
    }
}
