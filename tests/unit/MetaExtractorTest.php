<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\HomepageBlocks\Meta\ExtractorConfig;
use TryHackX\HomepageBlocks\Meta\MetaExtractor;

/**
 * The Advanced Filters index parser. Fixtures are trimmed copies of real release posts
 * (movie-generator template + MediaInfo dumps + older hand-written posts).
 */
class MetaExtractorTest extends TestCase
{
    private const GIB = 1024 ** 3;

    private const SERIES_POST = "Outer Banks (2020 - 2026) [88.23 GiB] [AVC 3 087 kb/s - 20.0 Mb/s]\n\n"
        . "Szybki podgląd:\nPolski tytuł: Outer Banks\n"
        . "Rok premiery: 2020 - 2026, Kategoria wiekowa: TV-MA, Serial: TV Series\n"
        . "Rozmiar: 88.23 GiB, Czas trwania: ~51 min 50 sec, Typ wydania: WEB-DL\n"
        . "Sezony: 1-5, Epizody: 50\n"
        . "Ocena: IMDB - 7.5/10 (190K), Filmweb - 7,3/10 (60 112)\n"
        . "Video: #5, Format: AVC, Bit rate: 4 137 kb/s, Stream size: 2.80 GiB (93%)\n"
        . "Outer Banks_S01E01_Pilot\nOuter Banks [S05E01] - Przeprawa\n";

    private const MOVIE_POST = "Szybki podgląd:\nPolski tytuł: Ocalony\nAngielski tytuł: Lone Survivor\nRok premiery: 2013\n"
        . "Rozmiar: 13.49 GB, Czas trwania: 2 h 1 min, Typ wydania: BluRay\n"
        . "Format: AVC, Codec ID: V_MPEG4/ISO/AVC, Bit rate: 15.0 Mb/s\n"
        . "Width: 1 920 pixels, Height: 800 pixels, Display aspect ratio: 2.40:1 Stream size: 12.5 GiB (92%)\n";

    private function extractor(array $settings = []): MetaExtractor
    {
        return new MetaExtractor(ExtractorConfig::fromArray($settings));
    }

    #[Test]
    public function a_generator_series_post_yields_every_field(): void
    {
        $meta = $this->extractor()->extract('Outer Banks (2020 - 2026) [88.23 GiB] [AVC 3 087 kb/s]', self::SERIES_POST);

        $this->assertSame((int) round(88.23 * self::GIB), $meta['size_bytes']);
        $this->assertSame('post', $meta['size_source']);
        $this->assertSame(1, $meta['is_series']);
        $this->assertSame(5, $meta['seasons']);
        $this->assertSame(50, $meta['episodes']);
        $this->assertSame([2020, 2026], [$meta['year_start'], $meta['year_end']]);
        $this->assertSame(52, $meta['duration_min']);
        $this->assertSame(7.5, $meta['imdb_rating']);
        $this->assertSame(7.3, $meta['filmweb_rating']);
    }

    #[Test]
    public function a_movie_post_is_a_movie(): void
    {
        $meta = $this->extractor()->extract('Ocalony / Lone Survivor (2013) [13.49 GB] [AVC 15Mb/s] [AC-3 448kb/s]', self::MOVIE_POST);

        $this->assertSame(0, $meta['is_series']);
        $this->assertSame((int) round(13.49 * self::GIB), $meta['size_bytes']);
        $this->assertSame([2013, 2013], [$meta['year_start'], $meta['year_end']]);
        $this->assertSame(121, $meta['duration_min']);
        $this->assertNull($meta['seasons']);
        $this->assertNull($meta['episodes']);
        $this->assertNull($meta['imdb_rating']);
    }

    #[Test]
    public function the_formatter_xml_is_read_as_plain_text(): void
    {
        // [b]Rozmiar:[/b] 609,87 GiB, [b]Czas trwania:[/b] \~44 min — as stored by Flarum.
        $xml = '<r><B><s>[b]</s>Rok premiery:<e>[/b]</e></B> 2004 - 2012, <B><s>[b]</s>Serial:<e>[/b]</e></B> TV Series<br/>' . "\n"
            . '<B><s>[b]</s>Rozmiar:<e>[/b]</e></B> 609,87 GiB, <B><s>[b]</s>Czas trwania:<e>[/b]</e></B> <ESC><s>\</s>~</ESC>44 min 6 s</r>';

        $meta = $this->extractor()->extract('Dr House / House M.D. (2004 - 2012)', $xml);

        $this->assertSame((int) round(609.87 * self::GIB), $meta['size_bytes']);
        $this->assertSame(1, $meta['is_series']);
        $this->assertSame([2004, 2012], [$meta['year_start'], $meta['year_end']]);
        $this->assertSame(44, $meta['duration_min']);
    }

    #[Test]
    public function literal_markup_is_ignored(): void
    {
        // A forum without the BBCode / Markdown extensions keeps the markers as text.
        $post = "[b]Serial:[/b] TV Series\n**Rozmiar:** 26 GiB, [b]Czas trwania:[/b] \\~51 min\n[color=red]Sezony:[/color] 1-2";

        $meta = $this->extractor(['size_sources' => 'post'])->extract('Utopia', $post);

        $this->assertSame([1, 26 * self::GIB, 51, 2], [$meta['is_series'], $meta['size_bytes'], $meta['duration_min'], $meta['seasons']]);
    }

    #[Test]
    public function broken_or_empty_content_never_throws(): void
    {
        $x = $this->extractor();

        foreach (['', '<r><B>unclosed', "<t>\xff\xfe invalid utf-8</t>", str_repeat('Rozmiar: 1 GB, ', 20000)] as $content) {
            $meta = $x->extract('Title', $content);
            $this->assertIsArray($meta);
        }
        $this->assertNull($x->extract('', null)['is_series']);
    }

    #[Test]
    public function a_mediainfo_stream_size_is_not_the_release_size(): void
    {
        $post = "Format: AVC, Bit rate: 15.0 Mb/s, Stream size: 12.5 GiB (92%)\nFile size: 13 GiB\n";

        $meta = $this->extractor(['size_sources' => 'post'])->extract('Something', $post);

        $this->assertNull($meta['size_bytes']);
    }

    #[Test]
    public function a_bit_rate_in_the_title_is_not_a_size(): void
    {
        $meta = $this->extractor(['size_sources' => 'title'])->extract('Film (2013) [AVC 15Mb/s] [AC-3 448kb/s]', '');
        $this->assertNull($meta['size_bytes']);

        $meta = $this->extractor(['size_sources' => 'title'])->extract('Film (2013) [AVC 15 Mb/s] [5,02 GB]', '');
        $this->assertSame('title', $meta['size_source']);
        $this->assertSame((int) round(5.02 * self::GIB), $meta['size_bytes']);
    }

    #[Test]
    public function the_size_sources_are_tried_in_the_configured_order(): void
    {
        $magnet = 'magnet:?xt=urn:btih:3972a132483bb36981d148a3b8bf2b451a459a58&amp;dn=x&amp;xl=284515691376&amp;tr=udp%3A%2F%2Fa';
        $xml = '<r>Rozmiar: 264.97 GiB<br/>' . "\n" . $magnet . '</r>';
        $title = 'The Looney Tunes Show (1930 - 2023) [265 GiB]';

        $meta = $this->extractor()->extract($title, $xml); // default: magnet, post, title
        $this->assertSame(['magnet', 284515691376], [$meta['size_source'], $meta['size_bytes']]);

        $meta = $this->extractor(['size_sources' => 'title,post'])->extract($title, $xml);
        $this->assertSame(['title', 265 * self::GIB], [$meta['size_source'], $meta['size_bytes']]);

        $meta = $this->extractor(['size_sources' => 'post'])->extract($title, $xml);
        $this->assertSame('post', $meta['size_source']);
    }

    #[Test]
    public function magnets_count_only_when_every_one_has_a_length(): void
    {
        $a = 'magnet:?xt=urn:btih:' . str_repeat('a', 40) . '&xl=1000';
        $b = 'magnet:?xt=urn:btih:' . str_repeat('b', 40) . '&xl=2000';
        $c = 'magnet:?xt=urn:btih:' . str_repeat('c', 40) . '&dn=no-length';

        $only = $this->extractor(['size_sources' => 'magnet']);
        $this->assertSame(3000, $only->extract('x', "$a\n$b\n$a")['size_bytes'], 'summed per unique hash');
        $this->assertNull($only->extract('x', "$a\n$c")['size_bytes'], 'one magnet without xl → unknown');
    }

    #[Test]
    public function decimal_units_follow_si(): void
    {
        $binary = $this->extractor(['size_sources' => 'title']);
        $decimal = $this->extractor(['size_sources' => 'title', 'size_units' => 'decimal']);

        $this->assertSame(2 * self::GIB, $binary->extract('[2 GB]', '')['size_bytes']);
        $this->assertSame(2_000_000_000, $decimal->extract('[2 GB]', '')['size_bytes']);
        $this->assertSame(2 * self::GIB, $decimal->extract('[2 GiB]', '')['size_bytes'], 'GiB is always binary');
    }

    #[Test]
    #[DataProvider('numbers')]
    public function numbers_are_read_in_any_locale(string $raw, ?float $expected): void
    {
        $this->assertSame($expected, MetaExtractor::parseNumber($raw));
    }

    public static function numbers(): array
    {
        return [
            ['33,70', 33.7], ['13.49', 13.49], ['1 234,5', 1234.5], ["1\u{00A0}234", 1234.0],
            ['1.234,5', 1234.5], ['1,234.5', 1234.5], ['1.234.567', 1234567.0], ['26', 26.0],
            ['abc', null], ['', null],
        ];
    }

    #[Test]
    public function seasons_are_counted_from_ranges_and_lists(): void
    {
        $this->assertSame(8, MetaExtractor::seasonsCount('1-8'));
        $this->assertSame(1, MetaExtractor::seasonsCount('1-1'));
        $this->assertSame(3, MetaExtractor::seasonsCount('3-5'));
        $this->assertSame(6, MetaExtractor::seasonsCount('S01–S06'));
        $this->assertSame(3, MetaExtractor::seasonsCount('1, 2, 5'));
        $this->assertSame(8, MetaExtractor::seasonsCount('8 (176 odcinków)'));
        $this->assertNull(MetaExtractor::seasonsCount('brak'));
    }

    #[Test]
    public function a_bracketed_title_size_beats_a_loose_one(): void
    {
        $meta = $this->extractor(['size_sources' => 'title'])->extract('Laptop z 16 GB RAM — poradnik [1,5 GB]', '');
        $this->assertSame((int) round(1.5 * self::GIB), $meta['size_bytes']);
    }

    #[Test]
    public function counts_accept_a_thousands_suffix(): void
    {
        $this->assertSame(1200, MetaExtractor::countValue('1.2K'));
        $this->assertSame(176, MetaExtractor::countValue('176'));
        $this->assertSame(1200, MetaExtractor::countValue('1 200'));
        $this->assertNull(MetaExtractor::countValue('brak'));
    }

    #[Test]
    public function series_detection_rules(): void
    {
        $x = $this->extractor();

        $this->assertSame(0, $x->extract('Ukryta kamera (2014) [3.75 GiB]', "Rok premiery: 2014, Serial: TV Movie\nRozmiar: 3.75 GiB")['is_series'], 'TV Movie is a movie');
        $this->assertSame(1, $x->extract('Rick i Morty (2013 - 2026) [95,73 GiB]', "Rozmiar: 95,73 GiB\nRick.and.Morty.S07E01.mkv")['is_series'], 'SxxEyy');
        $this->assertSame(7, $x->extract('Rick i Morty', "Rick.and.Morty.S07E01.mkv\nRick.and.Morty.S01E01.mkv")['seasons']);
        $this->assertSame(1, $x->extract('Beast Games (2024-)', "Rozmiar: 5.48 GiB\nEpizody: 10")['is_series'], 'episodes ≥ 2');
        $this->assertSame(1, $x->extract('Mini', "Typ: Miniserial\nRozmiar: 1 GB")['is_series']);
        $this->assertSame(0, $x->extract('Matrix Trylogia (1999-2003) [54,90 GB]', 'Rok premiery: 1999, 2003, 2003')['is_series']);
        $this->assertNull($x->extract('Witamy na forum', 'Regulamin: bądźcie mili.')['is_series'], 'not a release');

        $noPattern = $this->extractor(['series_from_pattern' => '0', 'seasons_from_pattern' => '0']);
        $meta = $noPattern->extract('Rick i Morty', "Rozmiar: 1 GB\nRick.and.Morty.S07E01.mkv");
        $this->assertSame([0, null], [$meta['is_series'], $meta['seasons']]);
    }

    #[Test]
    public function custom_labels_are_quoted_not_interpreted(): void
    {
        $x = $this->extractor(['size_labels' => 'Rozm.*, Größe (gesamt)', 'size_sources' => 'post']);

        $this->assertNull($x->extract('t', 'Rozmiar: 10 GB')['size_bytes'], '".*" is literal');
        $this->assertSame(10 * self::GIB, $x->extract('t', 'Größe (gesamt): 10 GB')['size_bytes']);
        $this->assertSame(10 * self::GIB, $x->extract('t', 'GRÖßE (gesamt): 10 GB')['size_bytes'], 'case-insensitive');
    }

    #[Test]
    public function years(): void
    {
        $x = $this->extractor();

        $meta = $x->extract('Trylogia (2005 - 2012)', 'Rok premiery: 2005, 2008, 2012');
        $this->assertSame([2005, 2012], [$meta['year_start'], $meta['year_end']]);
        $this->assertSame([2024, MetaExtractor::ONGOING], MetaExtractor::yearRange('2024–'));
        $this->assertSame([1985, 1991], MetaExtractor::yearRange('1985–1991'));
        $this->assertSame([null, null], MetaExtractor::yearRange('Nie znaleziono daty premiery.'));

        // Junk label → the title's "(2024-)" (still running).
        $meta = $x->extract('Beast Games (2024-) [5.48 GiB]', 'Rok premiery: Nie znaleziono daty premiery., Kategoria wiekowa: 2024–');
        $this->assertSame([2024, MetaExtractor::ONGOING], [$meta['year_start'], $meta['year_end']]);

        // The label names one year of a trilogy, the title the whole span → union.
        $meta = $x->extract('The Naked Gun Trylogia (1988 - 1994) [7,84 GiB]', 'Rok premiery: 1988');
        $this->assertSame([1988, 1994], [$meta['year_start'], $meta['year_end']]);

        $meta = $x->extract('Gumisie (1985–1991) [31,72 GiB]', '');
        $this->assertSame([1985, 1991], [$meta['year_start'], $meta['year_end']]);
        $this->assertNull($x->extract('Rok 1984 (książka)', '')['year_start'], 'no year in brackets');
    }

    #[Test]
    #[DataProvider('durationCases')]
    public function durations(string $value, ?int $minutes): void
    {
        $this->assertSame($minutes, MetaExtractor::durationMinutes($value));
    }

    public static function durationCases(): array
    {
        return [
            ['2 h 1 min', 121], ['~51 min 50 sec', 52], ['44 min 6 s', 44], ['1:45:00', 105],
            ['105 min', 105], ['1 godzina', 60], ['1 godz. 30 min', 90],
            ['2 h 20 min - 2 h 32 min - 2 h 44 min', 152], ['brak', null], ['', null],
        ];
    }

    #[Test]
    public function the_signature_changes_with_the_settings(): void
    {
        $this->assertSame(ExtractorConfig::defaults()->signature(), ExtractorConfig::fromArray(['size_units' => ''])->signature());
        $this->assertNotSame(ExtractorConfig::defaults()->signature(), ExtractorConfig::fromArray(['size_units' => 'decimal'])->signature());
        $this->assertSame(8, strlen(ExtractorConfig::defaults()->signature()));
    }

    #[Test]
    public function label_lists_are_trimmed_deduplicated_and_capped(): void
    {
        $this->assertSame(['Rozmiar', 'size'], ExtractorConfig::list(" Rozmiar: ,size\n Size ,, "));
        $this->assertCount(30, ExtractorConfig::list(implode(',', array_map(fn ($i) => 'L' . $i, range(1, 50)))));
        $this->assertSame([], ExtractorConfig::list(str_repeat('x', 61)));
    }
}
