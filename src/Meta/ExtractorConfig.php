<?php

namespace TryHackX\HomepageBlocks\Meta;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * What {@see MetaExtractor} looks for — every rule is an admin setting, because the
 * extension is public and every forum writes its release posts differently.
 *
 * Label lists are comma / newline separated, compared case-insensitively and always
 * regex-quoted (never interpreted as patterns). An empty setting means "default".
 */
final class ExtractorConfig
{
    public const SIZE_SOURCES = ['magnet', 'post', 'title'];

    /** Defaults, keyed by the setting suffix (`tryhackx-homepage-blocks.meta_<key>`). */
    public const DEFAULTS = [
        'size_sources' => 'magnet,post,title',
        'size_units' => 'binary',
        'size_labels' => 'Rozmiar, Size, Total size, Wielkość, Rozmiar całkowity',
        'kind_labels' => 'Serial, Typ, Type, Rodzaj',
        'series_values' => 'Series, Serial, Miniserial, Miniseries, Sitcom',
        'seasons_labels' => 'Sezony, Seasons, Liczba sezonów',
        'episodes_labels' => 'Epizody, Odcinki, Episodes, Ilość odcinków, Liczba odcinków',
        'year_labels' => 'Rok premiery, Rok produkcji, Rok wydania, Year, Released',
        'duration_labels' => 'Czas trwania, Duration, Runtime, Długość',
        'series_from_counts' => '1',
        'series_from_pattern' => '1',
        'seasons_from_pattern' => '1',
    ];

    /** Caps for admin input (defence against huge setting values). */
    private const MAX_LABELS = 30;
    private const MAX_LABEL_LENGTH = 60;

    /**
     * @param string[] $sizeSources ordered subset of SIZE_SOURCES
     * @param string[] $sizeLabels
     * @param string[] $kindLabels
     * @param string[] $seriesValues
     * @param string[] $seasonsLabels
     * @param string[] $episodesLabels
     * @param string[] $yearLabels
     * @param string[] $durationLabels
     */
    public function __construct(
        public readonly array $sizeSources,
        public readonly bool $binaryUnits,
        public readonly array $sizeLabels,
        public readonly array $kindLabels,
        public readonly array $seriesValues,
        public readonly array $seasonsLabels,
        public readonly array $episodesLabels,
        public readonly array $yearLabels,
        public readonly array $durationLabels,
        public readonly bool $seriesFromCounts,
        public readonly bool $seriesFromPattern,
        public readonly bool $seasonsFromPattern,
    ) {
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    public static function fromSettings(SettingsRepositoryInterface $settings): self
    {
        $raw = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $raw[$key] = $settings->get('tryhackx-homepage-blocks.meta_' . $key);
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<string, mixed> $raw setting values by suffix; null / '' fall back to the default
     */
    public static function fromArray(array $raw): self
    {
        $get = function (string $key) use ($raw): string {
            $value = $raw[$key] ?? null;
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : self::DEFAULTS[$key];
        };

        $sources = [];
        foreach (preg_split('/[\s,;]+/', strtolower($get('size_sources'))) ?: [] as $source) {
            if (in_array($source, self::SIZE_SOURCES, true) && ! in_array($source, $sources, true)) {
                $sources[] = $source;
            }
        }
        // "none" (or anything without a known source) switches size detection off.

        return new self(
            $sources,
            $get('size_units') !== 'decimal',
            self::list($get('size_labels')),
            self::list($get('kind_labels')),
            self::list($get('series_values')),
            self::list($get('seasons_labels')),
            self::list($get('episodes_labels')),
            self::list($get('year_labels')),
            self::list($get('duration_labels')),
            $get('series_from_counts') === '1',
            $get('series_from_pattern') === '1',
            $get('seasons_from_pattern') === '1',
        );
    }

    /**
     * Short fingerprint of everything that changes extraction results. Stored with each
     * indexed row, so the admin can tell which rows were built with older settings.
     * {@see MetaExtractor::VERSION} covers changes to the parsing code itself.
     */
    public function signature(): string
    {
        return substr(sha1(json_encode([MetaExtractor::VERSION, get_object_vars($this)]) ?: ''), 0, 8);
    }

    /** @return string[] */
    public static function list(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\n\r;]+/u', $raw) ?: [] as $item) {
            $item = trim(preg_replace('/\s+/u', ' ', $item) ?? '');
            $item = rtrim($item, ': ');
            if ($item === '' || mb_strlen($item) > self::MAX_LABEL_LENGTH) {
                continue;
            }
            $key = mb_strtolower($item);
            if (! isset($out[$key])) {
                $out[$key] = $item;
            }
            if (count($out) >= self::MAX_LABELS) {
                break;
            }
        }

        return array_values($out);
    }
}
