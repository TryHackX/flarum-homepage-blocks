<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * With tryhackx/flarum-topic-rating enabled, the "Rating interval" filter
 * (last_rated_at) narrows the counts too.
 */
class FacetsRatingIntervalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-topic-rating', 'tryhackx-homepage-blocks');

        $this->prepareDatabase([
            Tag::class => [
                ['id' => 1, 'name' => 'Drama', 'slug' => 'drama', 'position' => 0, 'is_primary' => true],
                ['id' => 10, 'name' => 'PL', 'slug' => 'pl', 'position' => null],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Rated today', 'user_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Rated long ago', 'user_id' => 1, 'comment_count' => 1],
                ['id' => 3, 'title' => 'Never rated', 'user_id' => 1, 'comment_count' => 1],
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1], ['discussion_id' => 1, 'tag_id' => 10],
                ['discussion_id' => 2, 'tag_id' => 1],
                ['discussion_id' => 3, 'tag_id' => 1], ['discussion_id' => 3, 'tag_id' => 10],
            ],
        ]);
    }

    #[Test]
    public function the_rating_interval_is_taken_into_account(): void
    {
        $this->database()->table('discussions')->where('id', 1)->update(['last_rated_at' => Carbon::now()->subHour()]);
        $this->database()->table('discussions')->where('id', 2)->update(['last_rated_at' => Carbon::now()->subYears(2)]);

        $response = $this->send($this->request('GET', '/api/tryhackx/homepage/facets')
            ->withQueryParams(['filter' => ['ratingInterval' => '1w']]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $facets = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $facets['category']['counts']['1'] ?? 0);
        $this->assertSame(1, $facets['resolution']['counts']['10'] ?? 0);
        $this->assertSame(1, $facets['category']['total']);
    }
}
