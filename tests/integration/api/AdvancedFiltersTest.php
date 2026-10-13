<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * "More filters": the metadata index, every filter through GET /api/discussions, the
 * size / year sorts, the admin index endpoints and the facets' Type counts.
 *
 * Fixture (guest-visible unless noted; first post = "Rozmiar: …" style release text):
 *   1 movie   Ocalony (2013)                13.49 GB, 2 h 1 min, IMDb 7.5   10 days old, 1 post
 *   2 series  Dr House (2004 - 2012)        609,87 GiB, 8 seasons, 177 ep.   2 days old, 3 posts
 *   3 series  Beast Games (2024-)            5.48 GiB, 10 episodes (running)  1 day old
 *   4 —       Witamy na forum                (not a release)                  1 day old
 *   5 series  Private show (2020)  private → invisible to guests
 *   6 movie   Short (2020) [1 GB]  tagged "seriale" — a tag never makes it a series
 */
class AdvancedFiltersTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const SERIES_TAG = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');

        foreach (['adv_enabled', 'adv_f_type', 'adv_f_created', 'adv_f_replies', 'adv_f_size', 'adv_f_seasons',
            'adv_f_episodes', 'adv_f_year', 'adv_f_duration', 'adv_f_imdb', 'adv_f_filmweb', 'adv_sort_size', 'adv_sort_year'] as $key) {
            $this->setting('tryhackx-homepage-blocks.' . $key, '1');
        }

        $now = Carbon::now();
        $d = fn (int $id, string $title, Carbon $created, array $extra = []) => array_merge([
            'id' => $id, 'title' => $title, 'user_id' => 2, 'created_at' => $created, 'last_posted_at' => $created,
            'first_post_id' => $id, 'comment_count' => 1, 'last_post_number' => 1,
        ], $extra);
        $p = fn (int $id, string $text) => [
            'id' => $id, 'discussion_id' => $id, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $now,
            'content' => '<t>' . str_replace("\n", "<br/>\n", htmlspecialchars($text, ENT_XML1)) . '</t>',
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
            Tag::class => [
                ['id' => self::SERIES_TAG, 'name' => 'Seriale', 'slug' => 'seriale', 'position' => 0, 'is_primary' => true],
            ],
            Discussion::class => [
                $d(1, 'Ocalony / Lone Survivor (2013) [13.49 GB] [AVC 15Mb/s]', $now->copy()->subDays(10)),
                $d(2, 'Dr House / House M.D. (2004 - 2012) [609,87 GiB]', $now->copy()->subDays(2), ['comment_count' => 3]),
                $d(3, 'Beast Games (2024-) [5.48 GiB]', $now->copy()->subDay()),
                $d(4, 'Witamy na forum', $now->copy()->subDay()),
                $d(5, 'Private show (2020) [3 GB]', $now->copy()->subDay(), ['is_private' => 1]),
                $d(6, 'Short (2020) [1 GB]', $now->copy()->subDay()),
            ],
            Post::class => [
                $p(1, "Rok premiery: 2013\nRozmiar: 13.49 GB, Czas trwania: 2 h 1 min, Typ wydania: BluRay\nOcena: IMDB - 7.5/10 (190K), Filmweb - 7,3/10"),
                $p(2, "Rok premiery: 2004 - 2012, Serial: TV Series\nRozmiar: 609,87 GiB, Czas trwania: 44 min\nSezony: 1-8, Epizody: 177"),
                $p(3, "Rozmiar: 5.48 GiB, Czas trwania: ~43 min\nEpizody: 10"),
                $p(4, 'Regulamin: bądźcie mili.'),
                $p(5, "Serial: TV Series\nRozmiar: 3 GB"),
                $p(6, "Rozmiar: 1 GB, Czas trwania: 15 min"),
            ],
            'discussion_tag' => [
                ['discussion_id' => 6, 'tag_id' => self::SERIES_TAG],
            ],
        ]);
    }

    /** Change a setting after boot (setting() only applies before the app starts). */
    protected function set(string $key, string $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('tryhackx-homepage-blocks.' . $key, $value);
    }

    protected function reindex(): void
    {
        $indexer = $this->app()->getContainer()->make(MetaIndexer::class);
        do {
            $r = $indexer->indexBatch($cursor ?? 0);
            $cursor = $r['next_cursor'];
        } while (! $r['done']);
    }

    /** Ids the discussion list returns for these filters (guest unless $as). */
    protected function ids(array $filter, ?string $sort = null, ?int $as = null): array
    {
        $params = ['filter' => $filter];
        if ($sort !== null) {
            $params['sort'] = $sort;
        }
        $response = $this->send($this->request('GET', '/api/discussions', $as ? ['authenticatedAs' => $as] : [])->withQueryParams($params));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $ids = array_map(fn ($r) => (int) $r['id'], json_decode((string) $response->getBody(), true)['data']);

        return $sort === null ? $this->sorted($ids) : $ids;
    }

    protected function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    #[Test]
    public function the_index_reads_every_field(): void
    {
        $this->reindex();
        $rows = $this->database()->table(MetaIndexer::TABLE)->get()->keyBy('discussion_id');

        $this->assertCount(6, $rows, 'every discussion, private ones too');
        $this->assertSame(1, (int) $rows[2]->is_series);
        $this->assertSame(8, (int) $rows[2]->seasons);
        $this->assertSame(177, (int) $rows[2]->episodes);
        $this->assertSame([2024, 9999], [(int) $rows[3]->year_start, (int) $rows[3]->year_end]);
        $this->assertSame(0, (int) $rows[1]->is_series);
        $this->assertNull($rows[4]->is_series, 'not a release');
        $this->assertSame((int) round(13.49 * 1024 ** 3), (int) $rows[1]->size_bytes);
    }

    #[Test]
    public function every_filter_narrows_the_list(): void
    {
        $this->reindex();

        $this->assertSame([1, 2, 3, 4, 6], $this->ids([]), 'baseline (5 is private)');
        $this->assertSame([1], $this->ids(['sizeRange' => '10GB..100GB']));
        $this->assertSame([3, 6], $this->ids(['sizeRange' => '..10GB']));
        $this->assertSame([2], $this->ids(['sizeRange' => '0.5TB..']));
        $this->assertSame([2], $this->ids(['seasonsRange' => '5..']));
        $this->assertSame([3], $this->ids(['episodesRange' => '..20']));
        $this->assertSame([2], $this->ids(['yearRange' => '2010..2010']), 'a span overlapping the range');
        $this->assertSame([3], $this->ids(['yearRange' => '2030..']), 'still running');
        $this->assertSame([1], $this->ids(['durationRange' => '100..']));
        $this->assertSame([1], $this->ids(['imdbRange' => '7..']));
        $this->assertSame([1], $this->ids(['filmwebRange' => '7..8']));
        $this->assertSame([2], $this->ids(['repliesRange' => '2..']));
        $this->assertSame([1, 3, 4, 6], $this->ids(['repliesRange' => '..0']));
    }

    #[Test]
    public function media_type_comes_from_the_post_not_from_tags(): void
    {
        $this->reindex();

        $this->assertSame([2, 3], $this->ids(['mediaType' => 'series']), 'a "Seriale" tag does not make 6 a series');
        $this->assertSame([1, 6], $this->ids(['mediaType' => 'movie']), 'not 4 (no release)');
        $this->assertSame([1, 4, 6], $this->ids(['-mediaType' => 'series']), 'negation = everything else');
        $this->assertSame([2, 3], $this->ids(['mediaType' => 'series'], null, 2), 'the private one stays invisible');
    }

    #[Test]
    public function created_range_takes_dates_and_local_day_timestamps(): void
    {
        $this->reindex();
        $fiveDaysAgo = Carbon::now()->subDays(5);

        $this->assertSame([1], $this->ids(['createdRange' => '..' . $fiveDaysAgo->format('Y-m-d')]));
        $this->assertSame([2, 3, 4, 6], $this->ids(['createdRange' => $fiveDaysAgo->getTimestamp() . '..']));
        $this->assertSame([1, 2, 3, 4, 6], $this->ids(['createdRange' => '2026-02-30..']), 'invalid date → ignored');
    }

    #[Test]
    public function malformed_or_huge_values_are_ignored_not_errors(): void
    {
        $this->reindex();
        $all = [1, 2, 3, 4, 6];

        $this->assertSame($all, $this->ids(['sizeRange' => '1...5']));
        $this->assertSame($all, $this->ids(['yearRange' => 'abc']));
        $this->assertSame($all, $this->ids(['mediaType' => 'cartoon']));
        $this->assertSame([], $this->ids(['sizeRange' => '999999999999TB..']));
        $this->assertSame([1], $this->ids(['sizeRange' => '100GB..10GB']), 'swapped bounds');
    }

    #[Test]
    public function a_disabled_filter_or_feature_is_ignored(): void
    {
        $this->reindex();
        $this->set('adv_f_size', '0');
        $this->assertSame([1, 2, 3, 4, 6], $this->ids(['sizeRange' => '10GB..100GB']));
        $this->assertSame([1], $this->ids(['durationRange' => '100..']), 'the others still work');

        $this->set('adv_enabled', '0');
        $this->assertSame([1, 2, 3, 4, 6], $this->ids(['durationRange' => '100..', 'mediaType' => 'movie']));
    }

    #[Test]
    public function size_and_year_sorts_put_unknown_values_last(): void
    {
        $this->reindex();

        $this->assertSame([2, 1, 3, 6, 4], $this->ids([], '-hbSize'));
        $this->assertSame([6, 3, 1, 2, 4], $this->ids([], 'hbSize'));
        $this->assertSame(4, $this->ids([], '-hbYear')[4], 'no year → last');

        // Switched off in the admin: still a valid sort (never an HTTP 500).
        $this->set('adv_sort_size', '0');
        $this->set('adv_enabled', '0');
        $this->assertSame([2, 1, 3, 6, 4], $this->ids([], '-hbSize'));
    }

    #[Test]
    public function facets_count_the_type_options_and_honour_the_new_filters(): void
    {
        $this->reindex();
        $facets = fn (array $filter) => json_decode((string) $this->send(
            $this->request('GET', '/api/tryhackx/homepage/facets')->withQueryParams(['filter' => $filter])
        )->getBody(), true);

        $all = $facets([]);
        $this->assertSame(['total' => 5, 'movie' => 2, 'series' => 2], $all['mediaType']);

        $big = $facets(['sizeRange' => '10GB..']);
        $this->assertSame(['total' => 2, 'movie' => 1, 'series' => 1], $big['mediaType']);
        $this->assertSame(2, $big['category']['total']);

        $series = $facets(['mediaType' => 'series']);
        $this->assertSame(2, $series['category']['total']);
        $this->assertSame(0, $series['category']['counts'][(string) self::SERIES_TAG] ?? 0, 'tags play no part in the type');

        $this->set('adv_f_type', '0');
        $this->assertArrayNotHasKey('mediaType', $facets([]));
    }

    #[Test]
    public function a_rebuild_never_overwrites_fresher_rows_or_writes_deleted_ones(): void
    {
        $indexer = $this->app()->getContainer()->make(MetaIndexer::class);
        $this->reindex();
        $this->database()->table(MetaIndexer::TABLE)->update(['indexed_at' => Carbon::now()->subHour()]);
        $started = Carbon::now()->subSecond();
        // Discussion 2 was re-indexed by the live listener after the batch started.
        $this->database()->table(MetaIndexer::TABLE)->where('discussion_id', 2)->update(['indexed_at' => Carbon::now()]);

        $stillCurrent = new \ReflectionMethod($indexer, 'stillCurrent');
        $rows = $stillCurrent->invoke($indexer, [
            ['discussion_id' => 1], ['discussion_id' => 2], ['discussion_id' => 999],
        ], $started);

        $this->assertSame([1], array_column($rows, 'discussion_id'), '2 is fresher, 999 no longer exists');
    }

    #[Test]
    public function the_index_endpoints_are_admin_only(): void
    {
        foreach ([['GET', '/api/tryhackx/homepage/meta/status'], ['POST', '/api/tryhackx/homepage/meta/reindex'],
            ['GET', '/api/tryhackx/homepage/meta/preview']] as [$method, $path]) {
            $this->assertContains($this->send($this->request($method, $path))->getStatusCode(), [400, 401, 403], "guest $path");
            $this->assertSame(403, $this->send($this->request($method, $path, ['authenticatedAs' => 2]))->getStatusCode(), "user $path");
        }
    }

    #[Test]
    public function the_admin_rebuilds_the_index_in_batches(): void
    {
        $response = $this->send($this->request('GET', '/api/tryhackx/homepage/meta/status', ['authenticatedAs' => 1]));
        $status = json_decode((string) $response->getBody(), true);
        $this->assertSame([6, 0, 6], [$status['total'], $status['indexed'], $status['missing']]);

        $response = $this->send($this->request('POST', '/api/tryhackx/homepage/meta/reindex', ['authenticatedAs' => 1, 'json' => ['cursor' => 0]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $batch = json_decode((string) $response->getBody(), true);
        $this->assertTrue($batch['done']);
        $this->assertSame(6, $batch['processed']);
        $this->assertSame([6, 0, 0, 5, 3, 2], [
            $batch['status']['indexed'], $batch['status']['missing'], $batch['status']['stale'],
            $batch['status']['with_size'], $batch['status']['series'], $batch['status']['movies'],
        ]);

        // A parser setting changes → every row is stale until the next rebuild.
        $this->set('meta_size_units', 'decimal');
        $status = json_decode((string) $this->send($this->request('GET', '/api/tryhackx/homepage/meta/status', ['authenticatedAs' => 1]))->getBody(), true);
        $this->assertSame(6, $status['stale']);

        $preview = json_decode((string) $this->send(
            $this->request('GET', '/api/tryhackx/homepage/meta/preview', ['authenticatedAs' => 1])->withQueryParams(['id' => 1])
        )->getBody(), true);
        $this->assertSame(13_490_000_000, $preview['extracted']['size_bytes'], 'preview uses the new settings');
        $this->assertSame((int) round(13.49 * 1024 ** 3), (int) $preview['stored']['size_bytes'], 'the index still holds the old value');
    }

    #[Test]
    public function the_forum_payload_lists_only_enabled_and_available_filters(): void
    {
        $this->setting('tryhackx-homepage-blocks.adv_f_views', '1'); // fof/discussion-views is not installed here
        $response = $this->send($this->request('GET', '/api'));
        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $adv = $attributes['tryhackxHomepageAdv'];
        $this->assertContains('size', $adv['filters']);
        $this->assertNotContains('views', $adv['filters']);
        $this->assertNotContains('active', $adv['filters']);
        $this->assertSame(['size', 'year'], $adv['sorts']);
        $this->assertSame('button', $adv['typePlacement'], 'default: the switch next to "More filters"');
        $this->assertArrayNotHasKey('size_labels', $adv, 'parser rules stay server-side');
    }

    #[Test]
    public function the_forum_payload_is_null_while_the_feature_is_off(): void
    {
        // The forum resource's fields are built once per app boot — set before the first request.
        $this->setting('tryhackx-homepage-blocks.adv_enabled', '0');
        $attributes = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];

        $this->assertArrayHasKey('tryhackxHomepageAdv', $attributes);
        $this->assertNull($attributes['tryhackxHomepageAdv']);
    }
}
