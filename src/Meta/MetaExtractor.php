<?php

namespace TryHackX\HomepageBlocks\Meta;

use TryHackX\HomepageBlocks\Service\MagnetExtractor;

/**
 * Reads release data out of a discussion's title and first post, for the Advanced
 * Filters index ({@see MetaIndexer}):
 *
 *   size_bytes / size_source  — magnet `xl=` total, a "Size:" line, or a "[12.5 GB]" in the title
 *   is_series                 — 1 series, 0 movie, null = not a release at all
 *   seasons / episodes        — "Seasons: 1-8", "Episodes: 176" (or the highest SxxEyy)
 *   year_start / year_end     — "Year: 2004 - 2012" and/or "(2019)" / "(2024-)" in the title (end 9999 = running)
 *   duration_min              — "Runtime: 1 h 58 min" (average when several are listed)
 *   imdb_rating / filmweb_rating — "IMDb 7.1/10", "Filmweb - 6,4/10"
 *
 * The post is read as plain text: s9e's removeFormatting() drops every BBCode /
 * Markdown marker ("[b]Size:[/b] 10 GB", "**Size:** 10 GB" and "Size: 10 GB" all
 * become the same line, and an escaped "\~" becomes "~").
 *
 * A "label" only counts where a field starts: at the start of a line or after a
 * comma / semicolon / bullet — so "Stream size: 6 GiB" in a MediaInfo dump is not a
 * "Size:" field. Its value runs to the end of the line or to the next ", Label:".
 *
 * Pure and side-effect free (unit tested).
 */
final class MetaExtractor
{
    /** Bump when parsing behaviour changes, so stale index rows show up in the admin. */
    public const VERSION = 1;

    public const MAX_SIZE_BYTES = 9_000_000_000_000_000; // ~8 EiB, fits a signed BIGINT

    private const MAX_TEXT = 200_000;

    /** Number with optional thousands groups: "1 234,56", "609,87", "1.234,5", "13.49". */
    private const NUMBER = '(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)*)';

    private const UNITS = 'TiB|GiB|MiB|KiB|TB|GB|MB|KB|bytes|B';

    public function __construct(private ExtractorConfig $config)
    {
    }

    /**
     * @return array{size_bytes: ?int, size_source: ?string, is_series: ?int, seasons: ?int, episodes: ?int,
     *               year_start: ?int, year_end: ?int, duration_min: ?int, imdb_rating: ?float, filmweb_rating: ?float}
     */
    public function extract(string $title, ?string $xml): array
    {
        $text = self::plainText($xml);
        $c = $this->config;

        // ── size ──
        $size = null;
        $source = null;
        foreach ($c->sizeSources as $candidate) {
            $size = match ($candidate) {
                'magnet' => $this->sizeFromMagnets($xml),
                'post' => $this->sizeFromPost($text),
                'title' => $this->sizeFromTitle($title),
                default => null,
            };
            if ($size !== null) {
                $source = $candidate;
                break;
            }
        }

        // ── series counters ──
        $seasons = null;
        $value = $this->labelValue($text, $c->seasonsLabels);
        if ($value !== null) {
            $seasons = self::seasonsCount($value);
        }
        $episodes = null;
        $value = $this->labelValue($text, $c->episodesLabels);
        if ($value !== null) {
            $episodes = self::countValue($value);
        }

        $patternSeason = self::highestSeasonFromPattern($title . "\n" . $text);
        if ($seasons === null && $c->seasonsFromPattern && $patternSeason !== null) {
            $seasons = $patternSeason;
        }

        // ── kind (series / movie) ──
        $kindValue = $this->labelValue($text, $c->kindLabels);
        $kindSays = null; // true = series, false = explicitly something else
        if ($kindValue !== null) {
            $kindSays = $this->matchesAny($kindValue, $c->seriesValues);
        }

        [$yearStart, $yearEnd] = $this->years($title, $text);
        $duration = $this->duration($text);

        $isSeries = null;
        if ($kindSays === true
            || ($c->seriesFromCounts && ($this->labelledSeasons($text) || ($episodes !== null && $episodes >= 2)))
            || ($c->seriesFromPattern && $patternSeason !== null)) {
            $isSeries = 1;
        } elseif ($kindSays === false || $size !== null || $yearStart !== null || $duration !== null) {
            $isSeries = 0;
        }

        return [
            'size_bytes' => $size,
            'size_source' => $source,
            'is_series' => $isSeries,
            'seasons' => $seasons,
            'episodes' => $episodes,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
            'duration_min' => $duration,
            'imdb_rating' => self::externalRating($text, 'IMDb'),
            'filmweb_rating' => self::externalRating($text, 'Filmweb'),
        ];
    }

    // ───────────────────────── text ─────────────────────────

    /** s9e XML (or plain text) → plain text without markup, capped. */
    public static function plainText(?string $xml): string
    {
        if ($xml === null || $xml === '') {
            return '';
        }
        // Invalid UTF-8 would make every /u regex below fail — scrub it first.
        $xml = mb_scrub($xml, 'UTF-8');
        $text = preg_match('/^\s*<[rt][ >\/]/', $xml) ? self::removeFormatting($xml) : $xml;
        $text = str_replace(["\r\n", "\r"], "\n", mb_substr($text, 0, self::MAX_TEXT));
        // Markup that survived as literal text (a forum without the BBCode / Markdown
        // extension, or imported posts): "[b]Size:[/b] 1 GB", "**Size:** 1 GB".
        $text = preg_replace('~\[/?(?:b|i|u|s|em|strong|color|colour|size|font|center|left|right|justify|quote|spoiler|h[1-6]|list|\*)(?:=[^\]\n]{0,40})?\]~i', '', $text) ?? $text;

        return preg_replace('~(\*\*|__)~', '', $text) ?? $text;
    }

    /**
     * s9e's Utils::removeFormatting() (drop the <s>/<e> markup markers, keep the text),
     * but quiet and total: broken XML (an imported or hand-edited row) never raises a
     * warning — it falls back to tag stripping. No network / entity loading.
     */
    private static function removeFormatting(string $xml): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if ($dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) && $dom->documentElement !== null) {
                foreach (iterator_to_array((new \DOMXPath($dom))->query('//e | //s') ?: []) as $node) {
                    $node->parentNode?->removeChild($node);
                }

                return $dom->documentElement->textContent;
            }
        } catch (\Throwable) {
            // fall through
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Value of the first field labelled with one of $labels, or null. See the class doc
     * for where a label may start and where its value ends.
     *
     * @param string[] $labels
     */
    public function labelValue(string $text, array $labels): ?string
    {
        if ($text === '' || ! $labels) {
            return null;
        }
        $alternatives = implode('|', array_map(fn (string $l) => preg_quote($l, '/'), $labels));
        $pattern = '/(?:^|[,;|•·▪►*]|\s-\s)[ \t\x{00A0}]*(?:' . $alternatives . ')[ \t\x{00A0}]*(?::|：|\s[-–]\s)[ \t\x{00A0}]*([^\n]{0,300})/miu';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }
        $value = $m[1];
        // Cut at the next ", Label:" on the same line (not "http://").
        if (preg_match('/,\s*[\p{L}][\p{L}\p{N} ()\/._\'-]{0,40}?\s*[:：](?!\/\/)/u', $value, $next, PREG_OFFSET_CAPTURE)) {
            $value = substr($value, 0, $next[0][1]);
        }
        $value = preg_replace('/^[\s\x{00A0},.;]+|[\s\x{00A0},.;]+$/u', '', $value) ?? '';

        return $value === '' ? null : $value;
    }

    /** @param string[] $words */
    private function matchesAny(string $value, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', $value)) {
                return true;
            }
        }

        return false;
    }

    private function labelledSeasons(string $text): bool
    {
        $value = $this->labelValue($text, $this->config->seasonsLabels);

        return $value !== null && (bool) preg_match('/[1-9]/', $value);
    }

    // ───────────────────────── size ─────────────────────────

    private function sizeFromPost(string $text): ?int
    {
        $value = $this->labelValue($text, $this->config->sizeLabels);
        if ($value === null) {
            return null;
        }
        // Inside a size field "Gb" / "gb" still means bytes; "/s" (a bit rate) never does.
        if (! preg_match('/' . self::NUMBER . '\s*(' . self::UNITS . ')(?![\p{L}\/])/iu', $value, $m)) {
            return null;
        }

        return $this->bytes($m[1], $m[2]);
    }

    private function sizeFromTitle(string $title): ?int
    {
        // Case-sensitive units with a capital B: "Mb/s" / "kb/s" are bit rates. A size
        // in brackets ("[12.5 GB]") wins over a loose one ("… 16 GB RAM …").
        $token = self::NUMBER . '\s*(TiB|GiB|MiB|KiB|TB|GB|MB|KB)(?![\p{L}\/])';
        if (! preg_match('/[\[(]\s*' . $token . '\s*[\])]/u', $title, $m)
            && ! preg_match('/(?<![\d.,])' . $token . '/u', $title, $m)) {
            return null;
        }

        return $this->bytes($m[1], $m[2]);
    }

    /** Sum of `xl=` over the post's magnets — only when EVERY magnet carries one. */
    private function sizeFromMagnets(?string $xml): ?int
    {
        if ($xml === null || $xml === '' || stripos($xml, 'magnet:') === false) {
            return null;
        }
        $total = 0;
        $seen = 0;
        foreach (MagnetExtractor::extract($xml) as $entry) {
            $uri = $entry['magnet'] ?? null;
            if (! is_string($uri) || ! preg_match('/[?&]xl=(\d{1,19})(?!\d)/i', $uri, $m)) {
                return null;
            }
            $total += (int) $m[1];
            $seen++;
            if ($total > self::MAX_SIZE_BYTES) {
                return null;
            }
        }

        return $seen > 0 && $total > 0 ? $total : null;
    }

    /** "33,70" + "GB" → bytes (binary or decimal units, see ExtractorConfig). */
    public function bytes(string $number, string $unit): ?int
    {
        $value = self::parseNumber($number);
        $factor = self::unitFactor($unit, $this->config->binaryUnits);
        if ($value === null || $factor === null || $value <= 0) {
            return null;
        }
        $bytes = $value * $factor;
        if ($bytes > self::MAX_SIZE_BYTES) {
            return null;
        }

        return max(1, (int) round($bytes));
    }

    public static function unitFactor(string $unit, bool $binary): ?float
    {
        $u = strtolower(trim($unit));
        if ($u === 'b' || $u === 'bytes') {
            return 1.0;
        }
        if (! preg_match('/^([kmgt])(i?)b$/', $u, $m)) {
            return null;
        }
        $power = strpos('kmgt', $m[1]) + 1;
        $base = ($m[2] === 'i' || $binary) ? 1024 : 1000;

        return (float) ($base ** $power);
    }

    /**
     * Locale-tolerant number: spaces / NBSP group thousands; with both "." and "," the
     * LAST one is the decimal mark; a single "," or "." is a decimal mark; a repeated
     * one groups thousands.
     */
    public static function parseNumber(string $raw): ?float
    {
        $s = preg_replace('/[ \x{00A0}\x{202F}\']/u', '', trim($raw)) ?? '';
        if ($s === '' || ! preg_match('/^\d[\d.,]*$/', $s)) {
            return null;
        }
        $dot = strrpos($s, '.');
        $comma = strrpos($s, ',');
        if ($dot !== false && $comma !== false) {
            $decimal = $dot > $comma ? '.' : ',';
            $group = $decimal === '.' ? ',' : '.';
            $s = str_replace($group, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($comma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($dot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    // ───────────────────────── series ─────────────────────────

    /** "176", "1.2K", "1 200" → int (1…100 000), else null. */
    public static function countValue(string $value): ?int
    {
        if (! preg_match('/' . self::NUMBER . '\s*([kK])?(?![\p{L}])/u', $value, $m)) {
            return null;
        }
        $n = self::parseNumber($m[1]);
        if ($n === null) {
            return null;
        }
        if (! empty($m[2])) {
            $n *= 1000;
        }

        return self::bounded((int) round($n), 1, 100_000);
    }

    /** Highest season number of any "S01E02" / "S1 E2" marker, or null. */
    public static function highestSeasonFromPattern(string $text): ?int
    {
        if (! preg_match_all('/(?<![\p{L}\p{N}])S(\d{1,2})[ ._-]?E\d{1,4}(?![\p{N}])/iu', $text, $m)) {
            return null;
        }

        return self::bounded(max(array_map('intval', $m[1])), 1, 99);
    }

    /**
     * Number of seasons in a "Seasons:" value: "1-8" → 8, "3-5" → 3, "S01–S06" → 6,
     * "1, 2, 5" → 3, a single "8" → 8. Anything in parentheses is ignored.
     */
    public static function seasonsCount(string $value): ?int
    {
        $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;
        $count = 0;
        $rest = preg_replace_callback('/(?<![\p{L}\d])S?(\d{1,3})\s*[-–—]\s*S?(\d{1,3})(?!\d)/iu', function (array $m) use (&$count): string {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            if ($b >= $a) {
                $count += $b - $a + 1;
            }

            return ' ';
        }, $value) ?? $value;
        preg_match_all('/(?<!\d)\d{1,3}(?!\d)/', $rest, $m);
        $singles = array_map('intval', $m[0] ?? []);

        if ($count === 0 && count($singles) === 1) {
            return self::bounded($singles[0], 1, 500);
        }

        return self::bounded($count + count($singles), 1, 500);
    }

    // ───────────────────────── year ─────────────────────────

    /** Stored as year_end for a release that is still running ("2022–"). */
    public const ONGOING = 9999;

    /**
     * Union of the "Year:" field and the "(2005 - 2012)" in the title — the label
     * may list one year of a trilogy, the title the whole span (or vice versa).
     *
     * @return array{0: ?int, 1: ?int} [start, end]; end = ONGOING while running, = start when unknown
     */
    private function years(string $title, string $text): array
    {
        $ranges = [];
        $value = $this->labelValue($text, $this->config->yearLabels);
        if ($value !== null) {
            $ranges[] = self::yearRange($value);
        }
        // "(2019)", "(2005 - 2012)", "(2024-)", "(1985–1991)" in the title.
        if (preg_match('/\(\s*(\d{4})\s*(?:([-–—])\s*(\d{4})?\s*)?\)/u', $title, $m)) {
            $ranges[] = self::yearRange($m[0]);
        }

        $ranges = array_filter($ranges, fn (array $r) => $r[0] !== null);
        if (! $ranges) {
            return [null, null];
        }

        return [min(array_column($ranges, 0)), max(array_column($ranges, 1))];
    }

    /** "2005, 2008, 2012" → [2005, 2012]; "2022–" → [2022, ONGOING]; "2013" → [2013, 2013]; none → [null, null]. */
    public static function yearRange(string $value): array
    {
        if (! preg_match_all('/(?<!\d)(\d{4})(?!\d)/', $value, $m)) {
            return [null, null];
        }
        $years = array_values(array_filter(array_map(fn ($y) => self::plausibleYear((int) $y), $m[1])));
        if (! $years) {
            return [null, null];
        }
        $start = min($years);
        $end = max($years);
        if (count($years) === 1 && preg_match('/\d{4}\s*[-–—]\s*(?:$|\)|…|\.\.\.|obecnie|present|now)/iu', $value)) {
            $end = self::ONGOING;
        }

        return [$start, $end];
    }

    private static function plausibleYear(int $year): ?int
    {
        return $year >= 1870 && $year <= (int) date('Y') + 2 ? $year : null;
    }

    // ───────────────────────── duration ─────────────────────────

    private function duration(string $text): ?int
    {
        $value = $this->labelValue($text, $this->config->durationLabels);

        return $value === null ? null : self::durationMinutes($value);
    }

    /** "2 h 1 min", "~51 min 50 sec", "1:45:00", "105 min", "1 godzina"; several → average. */
    public static function durationMinutes(string $value): ?int
    {
        $value = str_replace(['\\', '~', '≈', '±'], ' ', $value); // also a raw "\~" in imported posts
        $parts = preg_split('/\s+[-–—\/]\s+|\s*\|\s*/u', $value) ?: [];
        $sum = 0.0;
        $count = 0;
        foreach (array_slice($parts, 0, 20) as $part) {
            $minutes = self::partMinutes($part);
            if ($minutes !== null) {
                $sum += $minutes;
                $count++;
            }
        }
        if ($count === 0) {
            return null;
        }

        return self::bounded((int) round($sum / $count), 1, 6000);
    }

    private static function partMinutes(string $part): ?float
    {
        $part = mb_strtolower(trim($part));
        if ($part === '') {
            return null;
        }
        if (preg_match('/(?<!\d)(\d{1,3}):(\d{2})(?::(\d{2}))?(?!\d)/', $part, $m)) {
            return (int) $m[1] * 60 + (int) $m[2] + (isset($m[3]) ? (int) $m[3] / 60 : 0);
        }
        $total = 0.0;
        $found = false;
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:h|hr|hrs|hours?|godz\.?|godzin[ay]?|godzina)(?![\p{L}])/u', $part, $m)) {
            $total += (float) str_replace(',', '.', $m[1]) * 60;
            $found = true;
        }
        if (preg_match('/(\d+)\s*(?:m|min|mins|minut[ay]?|minutes?)(?![\p{L}])\.?/u', $part, $m)) {
            $total += (int) $m[1];
            $found = true;
        }
        if (preg_match('/(\d+)\s*(?:s|sec|secs|sek|seconds?|sekund[ay]?)(?![\p{L}])\.?/u', $part, $m)) {
            $total += (int) $m[1] / 60;
            $found = true;
        }
        if (! $found && preg_match('/^(\d{1,4})$/', $part, $m)) {
            $total = (int) $m[1]; // bare number = minutes
            $found = true;
        }

        return $found && $total > 0 ? $total : null;
    }

    // ───────────────────────── ratings ─────────────────────────

    /** "IMDB - 7.1/10", "IMDb: 7,1 / 10" → 7.1 (0–10), else null. */
    public static function externalRating(string $text, string $site): ?float
    {
        if ($text === '' || ! preg_match('/(?<![\p{L}])' . preg_quote($site, '/') . '(?![\p{L}])[^\d\n]{0,20}(\d{1,2}(?:[.,]\d{1,2})?)\s*\/\s*10(?!\d)/iu', $text, $m)) {
            return null;
        }
        $value = (float) str_replace(',', '.', $m[1]);

        return $value >= 0 && $value <= 10 ? round($value, 1) : null;
    }

    private static function bounded(int $value, int $min, int $max): ?int
    {
        return $value >= $min && $value <= $max ? $value : null;
    }
}
