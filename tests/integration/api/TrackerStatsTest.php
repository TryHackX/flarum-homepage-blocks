<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * The public stats counter counts what the public can see: hidden discussions and
 * private ones (unapproved posts held by flarum/approval, private conversations)
 * stay out of it.
 */
class TrackerStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');

        $now = Carbon::now();
        $discussion = fn (int $id, array $extra = []) => array_merge([
            'id' => $id, 'title' => 'D' . $id, 'user_id' => 2, 'created_at' => $now,
            'comment_count' => 1, 'last_post_number' => 1,
        ], $extra);

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'poster', 'email' => 'poster@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                $discussion(1),
                $discussion(2),
                $discussion(3, ['is_private' => true]),
                $discussion(4, ['hidden_at' => $now]),
            ],
        ]);
    }

    #[Test]
    public function the_discussion_counter_skips_private_and_hidden_discussions(): void
    {
        // The internal stats are cached for a few seconds in the extension's store,
        // which outlives a test run — drop the entry so this run computes them.
        $paths = $this->app()->getContainer()->make(Paths::class);
        @unlink($paths->storage . '/cache/tryhackx_store/' . sha1('stats:internal') . '.json');

        $response = $this->send(new ServerRequest([], [], '/api/tryhackx/homepage/stats', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, json_decode((string) $response->getBody(), true)['data']['discussions']);
    }
}
