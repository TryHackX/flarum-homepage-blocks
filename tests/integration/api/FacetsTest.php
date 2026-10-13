<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/tryhackx/homepage/facets — the live Category / Resolution counts.
 *
 * Fixture (guest-visible unless noted):
 *   1 Drama + PL   (2 days old)
 *   2 Drama + HD   (2 days old)
 *   3 Comedy + PL  (60 days old)
 *   4 Comedy + PL + HD, title "The Matrix Reloaded" (1 hour old)
 *   5 Drama + PL   private  → invisible
 *   6 Comedy + HD  hidden   → invisible
 */
class FacetsTest extends TestCase
{
    protected const DRAMA = 1;
    protected const COMEDY = 2;
    protected const PL = 10;
    protected const HD = 11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');

        $now = Carbon::now();
        $discussion = fn (int $id, string $title, Carbon $created, array $extra = []) => array_merge([
            'id' => $id, 'title' => $title, 'user_id' => 2, 'created_at' => $created,
            'comment_count' => 1, 'last_post_number' => 1,
        ], $extra);

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'poster', 'email' => 'poster@machine.local', 'is_email_confirmed' => 1],
            ],
            Tag::class => [
                ['id' => self::DRAMA, 'name' => 'Drama', 'slug' => 'drama', 'position' => 0, 'is_primary' => true],
                ['id' => self::COMEDY, 'name' => 'Comedy', 'slug' => 'comedy', 'position' => 1, 'is_primary' => true],
                ['id' => self::PL, 'name' => 'PL', 'slug' => 'pl', 'position' => null],
                ['id' => self::HD, 'name' => 'HD', 'slug' => 'hd', 'position' => null],
            ],
            Discussion::class => [
                $discussion(1, 'Alpha', $now->copy()->subDays(2)),
                $discussion(2, 'Beta', $now->copy()->subDays(2)),
                $discussion(3, 'Gamma', $now->copy()->subDays(60)),
                $discussion(4, 'The Matrix Reloaded', $now->copy()->subHour()),
                $discussion(5, 'Private', $now->copy()->subDay(), ['is_private' => 1]),
                $discussion(6, 'Hidden', $now->copy()->subDay(), ['hidden_at' => $now, 'hidden_user_id' => 1]),
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => self::DRAMA], ['discussion_id' => 1, 'tag_id' => self::PL],
                ['discussion_id' => 2, 'tag_id' => self::DRAMA], ['discussion_id' => 2, 'tag_id' => self::HD],
                ['discussion_id' => 3, 'tag_id' => self::COMEDY], ['discussion_id' => 3, 'tag_id' => self::PL],
                ['discussion_id' => 4, 'tag_id' => self::COMEDY], ['discussion_id' => 4, 'tag_id' => self::PL],
                ['discussion_id' => 4, 'tag_id' => self::HD],
                ['discussion_id' => 5, 'tag_id' => self::DRAMA], ['discussion_id' => 5, 'tag_id' => self::PL],
                ['discussion_id' => 6, 'tag_id' => self::COMEDY], ['discussion_id' => 6, 'tag_id' => self::HD],
            ],
        ]);
    }

    protected function facets(array $params = [], ?int $asUser = null): array
    {
        $request = $this->request('GET', '/api/tryhackx/homepage/facets', $asUser ? ['authenticatedAs' => $asUser] : [])
            ->withQueryParams($params);
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    /** Count a facet's numbers, keyed by tag id (missing = 0). */
    protected function counts(array $facet, array $tagIds): array
    {
        return array_map(fn ($id) => $facet['counts'][(string) $id] ?? 0, $tagIds);
    }

    /** How many discussions the real list returns for these filters. */
    protected function listCount(array $filter): int
    {
        $response = $this->send($this->request('GET', '/api/discussions')->withQueryParams(['filter' => $filter]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return count(json_decode((string) $response->getBody(), true)['data']);
    }

    #[Test]
    public function without_filters_it_counts_visible_discussions_per_tag(): void
    {
        $facets = $this->facets();

        $this->assertSame([2, 2], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
        $this->assertSame([3, 2], $this->counts($facets['resolution'], [self::PL, self::HD]));
        $this->assertSame(4, $facets['category']['total']);
        $this->assertSame(4, $facets['resolution']['total']);
    }

    #[Test]
    public function picking_a_resolution_recounts_the_categories_and_vice_versa(): void
    {
        $facets = $this->facets(['resolution' => 'pl']);
        // Categories among PL discussions…
        $this->assertSame([1, 2], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
        $this->assertSame(3, $facets['category']['total']);
        // …while the resolution list itself ignores its own pick.
        $this->assertSame([3, 2], $this->counts($facets['resolution'], [self::PL, self::HD]));

        $facets = $this->facets(['category' => 'drama', 'resolution' => 'hd']);
        $this->assertSame([1, 1], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
        $this->assertSame([1, 1], $this->counts($facets['resolution'], [self::PL, self::HD]));
    }

    #[Test]
    public function the_time_interval_is_taken_into_account(): void
    {
        $facets = $this->facets(['filter' => ['dateInterval' => '1w']]);

        $this->assertSame([2, 1], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
        $this->assertSame([2, 2], $this->counts($facets['resolution'], [self::PL, self::HD]));
        $this->assertSame(3, $facets['category']['total']);
    }

    #[Test]
    public function the_title_filter_is_taken_into_account(): void
    {
        $facets = $this->facets(['filter' => ['title' => 'matrix']]);

        $this->assertSame([0, 1], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
        $this->assertSame([1, 1], $this->counts($facets['resolution'], [self::PL, self::HD]));
        $this->assertSame(1, $facets['resolution']['total']);

        // Shorter than 3 characters is ignored, exactly like the list's TitleFilter.
        $this->assertSame(4, $this->facets(['filter' => ['title' => 'ma']])['category']['total']);
    }

    #[Test]
    public function every_count_matches_what_the_list_returns_for_that_pick(): void
    {
        $slugs = [self::DRAMA => 'drama', self::COMEDY => 'comedy', self::PL => 'pl', self::HD => 'hd'];

        foreach ([[], ['dateInterval' => '1w'], ['title' => 'matrix']] as $base) {
            foreach ([null, 'pl', 'hd'] as $resolution) {
                $facets = $this->facets(['filter' => $base, 'resolution' => $resolution ?? 'all']);

                foreach ([self::DRAMA, self::COMEDY] as $category) {
                    $tags = array_values(array_filter([$slugs[$category], $resolution]));
                    $this->assertSame(
                        $this->listCount($base + ['tag' => $tags]),
                        $facets['category']['counts'][(string) $category] ?? 0,
                        'category ' . $slugs[$category] . ' with ' . json_encode([$base, $resolution])
                    );
                }

                $this->assertSame(
                    $this->listCount($base + ($resolution ? ['tag' => [$resolution]] : [])),
                    $facets['category']['total'],
                    'total with ' . json_encode([$base, $resolution])
                );
            }
        }
    }

    #[Test]
    public function only_known_filters_are_applied(): void
    {
        // An author filter (or anything else) can't be smuggled through this endpoint.
        $facets = $this->facets(['filter' => ['author' => 'poster', 'q' => 'Alpha', 'tag' => 'comedy']]);

        $this->assertSame(4, $facets['category']['total']);
        $this->assertSame([2, 2], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
    }

    #[Test]
    public function an_unknown_tag_slug_yields_zero_rather_than_an_error(): void
    {
        $facets = $this->facets(['resolution' => 'does-not-exist']);

        $this->assertSame(0, $facets['category']['total']);
        $this->assertSame([0, 0], $this->counts($facets['category'], [self::DRAMA, self::COMEDY]));
    }
}
