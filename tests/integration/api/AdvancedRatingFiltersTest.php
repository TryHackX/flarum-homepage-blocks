<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * With tryhackx/flarum-topic-rating: Steam DB score, average stars, number of ratings
 * and "last rated" ranges through GET /api/discussions.
 *
 *   1  5.0★ × 40 ratings  rated 1 day ago    (Steam DB ≈ 83.6 %)
 *   2  5.0★ × 1 rating    rated 30 days ago  (Steam DB ≈ 59.4 % — few ratings pull it to 50 %)
 *   3  2.0★ × 10 ratings  rated 2 days ago   (Steam DB ≈ 44.9 %)
 *   4  unrated
 */
class AdvancedRatingFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-topic-rating', 'tryhackx-homepage-blocks');
        foreach (['adv_enabled', 'adv_f_steam', 'adv_f_rating', 'adv_f_rating_count', 'adv_f_rated'] as $key) {
            $this->setting('tryhackx-homepage-blocks.' . $key, '1');
        }

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Loved by many', 'user_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Loved by one', 'user_id' => 1, 'comment_count' => 1],
                ['id' => 3, 'title' => 'Meh', 'user_id' => 1, 'comment_count' => 1],
                ['id' => 4, 'title' => 'Unrated', 'user_id' => 1, 'comment_count' => 1],
            ],
        ]);
    }

    protected function rate(): void
    {
        // The Steam DB score needs POWER(), which MySQL / MariaDB / PostgreSQL have but
        // PHP's bundled SQLite may lack — provide it for this SQLite test run.
        $pdo = $this->database()->getPdo();
        if ($pdo instanceof \Pdo\Sqlite) {
            // PHP 8.4+: Laravel opens the connection with PDO::connect() (driver subclass);
            // PDO::sqliteCreateFunction() is deprecated since PHP 8.5.
            $pdo->createFunction('power', 'pow', 2);
        } elseif ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->sqliteCreateFunction('power', 'pow', 2);
        }
        $now = Carbon::now();
        foreach ([1 => [5, 40, 1], 2 => [5, 1, 30], 3 => [2, 10, 2]] as $id => [$avg, $count, $daysAgo]) {
            $this->database()->table('discussions')->where('id', $id)->update([
                'rating_average' => $avg, 'rating_count' => $count, 'last_rated_at' => $now->copy()->subDays($daysAgo),
            ]);
        }
    }

    protected function ids(array $filter): array
    {
        $response = $this->send($this->request('GET', '/api/discussions')->withQueryParams(['filter' => $filter]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $ids = array_map(fn ($r) => (int) $r['id'], json_decode((string) $response->getBody(), true)['data']);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function steam_db_score_weighs_the_number_of_ratings(): void
    {
        $this->rate();

        $this->assertSame([1], $this->ids(['steamRange' => '80..']), 'many 5★ ratings beat a single one');
        $this->assertSame([1, 2], $this->ids(['steamRange' => '50..']));
        $this->assertSame([2, 3], $this->ids(['steamRange' => '40..60']));
        $this->assertSame([3], $this->ids(['steamRange' => '..50']), 'unrated never matches');
    }

    #[Test]
    public function stars_count_and_last_rated(): void
    {
        $this->rate();

        $this->assertSame([1, 2], $this->ids(['ratingRange' => '4.5..']));
        $this->assertSame([3], $this->ids(['ratingRange' => '..3']), 'unrated (stored as 0) never matches');
        $this->assertSame([1, 3], $this->ids(['ratingCountRange' => '5..']));
        $this->assertSame([1, 3], $this->ids(['ratedRange' => Carbon::now()->subDays(7)->getTimestamp() . '..']));
    }
}
