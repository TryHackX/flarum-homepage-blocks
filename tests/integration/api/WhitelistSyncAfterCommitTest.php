<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\DescriptionPayload;
use TryHackX\HomepageBlocks\Service\SentTopics;
use TryHackX\HomepageBlocks\Service\TrackerQueue;
use TryHackX\HomepageBlocks\Service\TrackerSync;

/**
 * The tracker whitelist sync runs after the transaction that saved the post commits —
 * never inside it (Flarum dispatches `Posted` for a new discussion's first post inside
 * the discussion-create transaction).
 *
 * The suite itself runs inside one test transaction (level 1), whose inline manager
 * would run after-commit callbacks at once; here a manager that fires them when the
 * app's own transaction commits back to level 1 is used instead, and the tracker
 * transport is a recording double. A submit seen at level 1 = after the commit.
 */
class WhitelistSyncAfterCommitTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const HASH = 'aeb79b5a45543c7db600a6a09ac02205809f5cbe';
    private const OTHER_HASH = '0123456789abcdef0123456789abcdef01234567';
    private const THIRD_HASH = 'fedcba9876543210fedcba9876543210fedcba98';

    private RecordingWhitelistClient $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');
        $this->setting('tryhackx-homepage-blocks.whitelist_sync_enabled', '1');
        $this->setting('flarum-tags.min_primary_tags', '0');
        $this->setting('flarum-tags.min_secondary_tags', '0');

        $this->tracker = new RecordingWhitelistClient();
        $this->extend(new class($this->tracker) implements ExtenderInterface {
            public function __construct(private RecordingWhitelistClient $tracker)
            {
            }

            public function extend(Container $container, ?Extension $extension = null): void
            {
                $container->instance(TrackerWhitelistClient::class, $this->tracker);

                $manager = new class extends DatabaseTransactionsManager {
                    public function afterCommitCallbacksShouldBeExecuted($level)
                    {
                        return $level === 1; // the test's own wrapping transaction
                    }
                };
                $container->instance('db.transactions', $manager);
                $connection = $container->make(ConnectionInterface::class);
                if ($connection instanceof Connection) {
                    $connection->setTransactionManager($manager);
                }
                $this->tracker->db = $connection;
            }
        });
    }

    protected function startDiscussion(string $content): array
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Release', 'content' => $content]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    #[Test]
    public function a_new_discussion_is_synced_once_after_its_transaction_commits(): void
    {
        $this->startDiscussion('Magnet: magnet:?xt=urn:btih:' . self::HASH . '&dn=Outer%20Banks');

        $this->assertCount(1, $this->tracker->calls, 'exactly one submit');
        $this->assertSame(1, $this->tracker->calls[0]['level'], 'after the discussion transaction committed (level 2 → 1)');
        $this->assertStringContainsString(self::HASH, json_encode($this->tracker->calls[0]['items']));
        $this->assertTrue($this->tracker->calls[0]['items'][0]['ref']['first'] ?? false, 'registered by the first post of its topic');
    }

    #[Test]
    public function a_reply_is_synced_once_too(): void
    {
        $discussion = $this->startDiscussion('No magnet here.');
        $this->assertCount(0, $this->tracker->calls);

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'magnet:?xt=urn:btih:' . self::HASH],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => $discussion['id']]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertCount(1, $this->tracker->calls);
        $this->assertLessThanOrEqual(1, $this->tracker->calls[0]['level'], 'never inside an open app transaction');
        $this->assertArrayNotHasKey('first', $this->tracker->calls[0]['items'][0]['ref'], 'a reply is not the first post');
    }

    #[Test]
    public function a_tracker_failure_never_breaks_posting(): void
    {
        $this->tracker->fail = true;

        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->assertCount(1, $this->tracker->calls);
        $this->assertNotNull($this->database()->table('discussions')->where('id', $data['id'])->first(), 'the discussion is saved');
        $row = $this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame('tracker down', $row->last_error);
        $this->assertGreaterThan(Carbon::now()->addSeconds(30), Carbon::parse($row->next_at));
    }

    private function drain(): array
    {
        return $this->app()->getContainer()->make(TrackerSync::class)->drain();
    }

    private function makeDue(int $discussionId): void
    {
        $this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $discussionId)->update(['next_at' => Carbon::now()->subSecond()]);
    }

    #[Test]
    public function a_change_the_tracker_missed_is_sent_by_a_later_drain(): void
    {
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_503';
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->assertCount(1, $this->tracker->described);
        $row = $this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first();
        $this->assertSame(TrackerQueue::DESCRIBE, $row->reason, 'the magnets went through, only the description waits');
        $this->assertSame('describe: http_503', $row->last_error);

        $this->tracker->describeError = null;
        $this->assertSame(0, $this->drain()['synced'], 'not before its retry time');
        $this->makeDue((int) $data['id']);
        $this->assertSame(1, $this->drain()['synced']);

        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(2, $this->tracker->described);
        $this->assertNull($this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first());
        $sent = $this->app()->getContainer()->make(SentTopics::class)->get((int) $data['id']);
        $this->assertSame([self::HASH], $sent['hashes']);
        $this->assertTrue($sent['visible']);
        $this->assertSame('published', $sent['state']);
    }

    #[Test]
    public function a_rate_limited_change_waits_without_counting_as_a_failure(): void
    {
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_429';
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $row = $this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first();
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame(TrackerQueue::DESCRIBE, $row->reason);
        $this->assertGreaterThan(Carbon::now()->addSeconds(20), Carbon::parse($row->next_at));
    }

    #[Test]
    public function an_unchanged_first_post_is_not_described_again(): void
    {
        $this->tracker->describeOn = true;
        $discussion = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->reply($discussion, 'magnet:?xt=urn:btih:' . self::OTHER_HASH);

        $this->assertCount(2, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
    }

    private function firstPostId(array $discussion): int
    {
        return (int) $this->database()->table('discussions')->where('id', $discussion['id'])->value('first_post_id');
    }

    private function reply(array $discussion, string $content): void
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => $content],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => $discussion['id']]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function a_new_discussion_sends_its_first_post_as_the_description_after_the_magnets(): void
    {
        $this->tracker->describeOn = true;
        $content = "Zażółć gęślą jaźń\n\n**Opis** magnet:?xt=urn:btih:" . self::HASH . '&dn=Outer%20Banks';

        $data = $this->startDiscussion($content);

        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
        $this->assertSame(1, $this->tracker->described[0]['submits']);
        $this->assertSame(1, $this->tracker->described[0]['level']);
        $this->assertSame(63000, $this->tracker->described[0]['max_chars'], "core's own limit while the override is off");
        $topic = $this->tracker->described[0]['topics'][0];
        $this->assertSame((int) $data['id'], $topic['discussion_id']);
        $this->assertSame($this->firstPostId($data), $topic['post_id']);
        $this->assertSame($content, $topic['content']);
        $this->assertSame(mb_strlen($content), $topic['chars']);
        $this->assertSame('flarum', $topic['format']);
        $this->assertSame(['id' => '1', 'name' => 'admin'], $topic['author']);
        $this->assertSame([self::HASH], $topic['hashes']);
        $this->assertMatchesRegularExpression('#/d/' . $data['id'] . '-release$#', $topic['url']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $topic['edited_at']);
    }

    #[Test]
    public function a_reply_never_sends_a_description(): void
    {
        $this->tracker->describeOn = true;
        $discussion = $this->startDiscussion('No magnet here.');

        $this->reply($discussion, 'magnet:?xt=urn:btih:' . self::HASH);

        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(0, $this->tracker->described);
    }

    #[Test]
    public function editing_the_first_post_sends_the_new_text(): void
    {
        $this->tracker->describeOn = true;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $postId = $this->firstPostId($data);

        $response = $this->send($this->request('PATCH', '/api/posts/' . $postId, [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['content' => 'Edited magnet:?xt=urn:btih:' . self::HASH]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertCount(2, $this->tracker->described);
        $this->assertSame('Edited magnet:?xt=urn:btih:' . self::HASH, $this->tracker->described[1]['topics'][0]['content']);
    }

    #[Test]
    public function no_description_is_sent_while_the_option_is_off(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(0, $this->tracker->described);
    }

    #[Test]
    public function a_post_a_guest_cannot_see_syncs_nothing_until_it_can(): void
    {
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;
        $post = CommentPost::query()->findOrFail($this->firstPostId($data));
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        $post->is_private = true;
        $post->save();
        $events->dispatch(new Posted($post));
        $this->assertCount(0, $this->tracker->calls);
        $this->assertCount(0, $this->tracker->described);

        $post->is_private = false;
        $post->save();
        $events->dispatch(new Posted($post));
        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
    }

    #[Test]
    public function the_members_audience_sends_a_topic_only_members_can_see(): void
    {
        $this->database()->table('tags')->insert(['id' => 90, 'name' => 'Members', 'slug' => 'members-only', 'is_restricted' => 1]);
        $this->database()->table('group_permission')->insert(['group_id' => 3, 'permission' => 'tag90.viewForum']);
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->database()->table('discussion_tag')->insert(['discussion_id' => $data['id'], 'tag_id' => 90]);
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;
        $post = CommentPost::query()->findOrFail($this->firstPostId($data));
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        $events->dispatch(new Posted($post));
        $this->assertCount(0, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
        $this->assertFalse($this->tracker->described[0]['topics'][0]['visible']);
        $this->assertArrayNotHasKey('content', $this->tracker->described[0]['topics'][0]);

        $this->tracker->described = [];
        $this->tracker->audienceValue = 'members';
        $events->dispatch(new Posted($post));
        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
        $topic = $this->tracker->described[0]['topics'][0];
        $this->assertSame('members', $topic['audience']);
        $this->assertSame([self::HASH], $topic['hashes']);
        $this->assertSame('magnet:?xt=urn:btih:' . self::HASH, $topic['content']);
    }

    #[Test]
    public function the_guests_audience_is_said_in_every_topic(): void
    {
        $this->tracker->describeOn = true;
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->assertCount(1, $this->tracker->described);
        $this->assertSame('guests', $this->tracker->described[0]['topics'][0]['audience']);
    }

    private function scan(int $cursor = 0): array
    {
        $response = $this->send($this->request('POST', '/api/tryhackx/homepage/whitelist/scan', [
            'authenticatedAs' => 1,
            'json' => ['cursor' => $cursor],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function the_forum_scan_sends_first_posts_with_hashes_only(): void
    {
        $content = "Release notes\nmagnet:?xt=urn:btih:" . self::HASH;
        $withMagnet = $this->startDiscussion($content);
        $this->reply($withMagnet, 'magnet:?xt=urn:btih:' . self::OTHER_HASH);
        $this->startDiscussion('No magnet here.');
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;

        $result = $this->scan();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertSame(['published' => 1, 'off' => false], array_filter($result['describe'], fn ($n) => $n !== 0));
        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
        $this->assertSame(1, $this->tracker->described[0]['submits']);
        $topics = $this->tracker->described[0]['topics'];
        $this->assertCount(1, $topics);
        $this->assertSame($this->firstPostId($withMagnet), $topics[0]['post_id']);
        $this->assertSame($content, $topics[0]['content']);
        $this->assertSame(['id' => '1', 'name' => 'admin'], $topics[0]['author']);
        $this->assertSame([self::HASH], $topics[0]['hashes']);
    }

    #[Test]
    public function the_scan_sends_the_forum_limit_and_a_longer_post_without_its_text(): void
    {
        $content = str_repeat('Opis wydania. ', 3) . 'magnet:?xt=urn:btih:' . self::HASH;
        $this->startDiscussion($content);
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $settings->set('tryhackx-homepage-blocks.content_length_enabled', '1');
        $settings->set('tryhackx-homepage-blocks.content_max_length', '30');
        $this->tracker->describeOn = true;

        $this->scan();

        $this->assertSame(30, $this->tracker->described[0]['max_chars']);
        $topic = $this->tracker->described[0]['topics'][0];
        $this->assertNull($topic['content']);
        $this->assertSame(mb_strlen($content), $topic['chars']);

        $settings->set('tryhackx-homepage-blocks.content_max_length', '500000');
        $this->scan();

        $this->assertSame(500000, $this->tracker->described[1]['max_chars']);
        $this->assertSame($content, $this->tracker->described[1]['topics'][0]['content']);
    }

    #[Test]
    public function a_describe_failure_queues_the_topic_and_the_scan_goes_on(): void
    {
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_503';

        $result = $this->scan();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertSame($this->firstPostId($data), $result['next_cursor']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['describe']['errors']);
        $row = $this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first();
        $this->assertSame('describe: http_503', $row->last_error);
        $this->assertSame(1, (int) $row->attempts);
    }

    #[Test]
    public function describe_off_lets_the_scan_go_on_with_magnets_only(): void
    {
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'describe_off';

        $result = $this->scan();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertSame($this->firstPostId($data), $result['next_cursor']);
        $this->assertTrue($result['describe']['off']);
        $this->assertSame(0, $result['describe']['published']);
    }

    private function saveSettings(array $settings): void
    {
        $response = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => 1,
            'json' => $settings,
        ]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function saving_the_picture_consent_tells_the_tracker_right_away(): void
    {
        $this->saveSettings(['tryhackx-homepage-blocks.whitelist_image_copy' => '1']);

        $this->assertCount(1, $this->tracker->pings);
        $this->assertNull($this->tracker->pings[0]['override']);
        $this->assertTrue($this->tracker->pings[0]['consent']);
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $this->assertSame('1', $settings->get('tryhackx-homepage-blocks.whitelist_image_copy'));
        $this->assertSame('1', $settings->get(TrackerSync::CONSENT_SENT));

        $this->saveSettings(['tryhackx-homepage-blocks.whitelist_image_copy' => '0']);

        $this->assertCount(2, $this->tracker->pings);
        $this->assertFalse($this->tracker->pings[1]['consent']);
        $this->assertSame('0', $settings->get(TrackerSync::CONSENT_SENT));
    }

    #[Test]
    public function the_consent_is_sent_again_until_the_tracker_agrees(): void
    {
        $this->tracker->pingJson = ['ok' => true, 'images' => 'no_consent', 'image_domains' => ['tryhackx.org']];
        $this->saveSettings(['tryhackx-homepage-blocks.whitelist_image_copy' => '1']);
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $this->assertCount(1, $this->tracker->pings);
        $this->assertSame('', (string) $settings->get(TrackerSync::CONSENT_SENT));

        $this->drain();
        $this->assertCount(1, $this->tracker->pings, 'an answer that disagrees is asked again after a pause, not on every drain');

        $this->app()->getContainer()->make(Store::class)->write('whitelist:consent_try', ['wanted' => true, 'at' => time() - 3600], 60);
        $this->tracker->pingJson = ['ok' => true, 'images' => 'copy', 'image_domains' => ['tryhackx.org']];
        $this->drain();
        $this->assertCount(2, $this->tracker->pings);
        $this->assertTrue($this->tracker->pings[1]['consent']);
        $this->assertSame('1', $settings->get(TrackerSync::CONSENT_SENT));

        $this->drain();
        $this->assertCount(2, $this->tracker->pings);
    }

    #[Test]
    public function test_connection_never_pushes_the_consent(): void
    {
        $this->setting('tryhackx-homepage-blocks.whitelist_image_copy', '0');

        $result = $this->testConnection();

        $this->assertCount(1, $this->tracker->pings);
        $this->assertNull($this->tracker->pings[0]['consent']);
        $this->assertSame(['wanted' => false, 'confirmed' => null], $result['consent']);
    }

    #[Test]
    public function saving_other_settings_sends_nothing(): void
    {
        $this->saveSettings(['tryhackx-homepage-blocks.whitelist_timeout_seconds' => '5']);

        $this->assertCount(0, $this->tracker->pings);
        $this->assertCount(0, $this->tracker->calls);
    }

    private function testConnection(): array
    {
        $response = $this->send($this->request('POST', '/api/tryhackx/homepage/whitelist/test', [
            'authenticatedAs' => 1,
            'json' => [],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function test_connection_shows_the_trackers_picture_state(): void
    {
        $this->tracker->pingJson = ['ok' => true, 'images' => 'copy', 'image_domains' => ['tryhackx.org', 7, 'img.example.org']];
        $result = $this->testConnection();
        $this->assertSame('copy', $result['images']);
        $this->assertSame(['tryhackx.org', 'img.example.org'], $result['image_domains']);

        $this->tracker->pingJson = ['ok' => true, 'images' => 'no_consent', 'image_domains' => ['tryhackx.org']];
        $this->assertSame('no_consent', $this->testConnection()['images']);

        $this->tracker->pingJson = ['ok' => true, 'images' => 'off', 'image_domains' => []];
        $this->assertSame('off', $this->testConnection()['images']);

        $this->tracker->pingJson = ['ok' => true];
        $result = $this->testConnection();
        $this->assertNull($result['images']);
        $this->assertSame([], $result['image_domains']);
    }

    private function describeAll(int $cursor = 0): array
    {
        $response = $this->send($this->request('POST', '/api/tryhackx/homepage/whitelist/scan', [
            'authenticatedAs' => 1,
            'json' => ['cursor' => $cursor, 'mode' => 'describe'],
        ]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function the_description_pass_walks_first_posts_only_and_submits_before_describing(): void
    {
        $first = $this->startDiscussion("Release notes\nmagnet:?xt=urn:btih:" . self::HASH);
        $this->reply($first, 'magnet:?xt=urn:btih:' . self::OTHER_HASH);
        $this->startDiscussion('No magnet here.');
        $last = $this->startDiscussion('magnet:?xt=urn:btih:' . self::THIRD_HASH);
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;

        [$status, $result] = $this->describeAll();

        $this->assertSame(200, $status);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertSame(3, $result['total_posts']);
        $this->assertSame(3, $result['processed']);
        $this->assertSame($this->firstPostId($last), $result['next_cursor']);
        $this->assertSame(2, $result['describe']['published']);
        $this->assertCount(1, $this->tracker->calls);
        $items = json_encode($this->tracker->calls[0]['items']);
        $this->assertStringContainsString(self::HASH, $items);
        $this->assertStringContainsString(self::THIRD_HASH, $items);
        $this->assertStringNotContainsString(self::OTHER_HASH, $items);
        $this->assertCount(1, $this->tracker->described);
        $this->assertSame(1, $this->tracker->described[0]['submits']);
        $this->assertSame([$this->firstPostId($first), $this->firstPostId($last)], array_column($this->tracker->described[0]['topics'], 'post_id'));
    }

    #[Test]
    public function the_description_pass_needs_the_description_setting(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->calls = [];

        [$status, $result] = $this->describeAll();

        $this->assertSame(422, $status);
        $this->assertSame('describe_disabled', $result['error']);
        $this->assertCount(0, $this->tracker->calls);
    }

    #[Test]
    public function a_failed_submit_keeps_the_description_pass_cursor(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->submitError = 'http_503';

        [, $result] = $this->describeAll();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['next_cursor']);
        $this->assertFalse($result['done']);
        $this->assertCount(0, $this->tracker->described);
    }

    #[Test]
    public function a_failed_describe_queues_the_topic_and_the_description_pass_goes_on(): void
    {
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_503';

        [, $result] = $this->describeAll();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertSame($this->firstPostId($data), $result['next_cursor']);
        $this->assertSame(1, $result['queued']);
        $this->assertNotNull($this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first());
    }

    #[Test]
    public function a_rate_limit_makes_the_description_pass_wait_instead_of_failing(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_429';

        [, $result] = $this->describeAll();

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['done']);
        $this->assertSame(0, $result['next_cursor']);
        $this->assertSame(30, $result['wait']);
        $this->assertSame(0, $result['queued']);
    }

    #[Test]
    public function describe_off_holds_the_description_pass(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'describe_off';

        [, $result] = $this->describeAll();

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['done']);
        $this->assertSame(0, $result['next_cursor']);
        $this->assertSame(0, $result['processed']);
        $this->assertTrue($result['describe']['off']);
    }

    #[Test]
    public function the_description_pass_and_the_scan_never_run_together(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;
        $store = $this->app()->getContainer()->make(Store::class);
        $store->write('whitelist:scan_running', ['until' => time() + 40], 60);

        try {
            [$describeStatus] = $this->describeAll();
            $scan = $this->send($this->request('POST', '/api/tryhackx/homepage/whitelist/scan', [
                'authenticatedAs' => 1,
                'json' => ['cursor' => 0],
            ]));
        } finally {
            $store->write('whitelist:scan_running', ['until' => 0], 5);
        }

        $this->assertSame(409, $describeStatus);
        $this->assertSame(409, $scan->getStatusCode());
        $this->assertCount(0, $this->tracker->calls);
        $this->assertCount(0, $this->tracker->described);

        [$status] = $this->describeAll();
        $this->assertSame(200, $status);
    }

    #[Test]
    public function a_dry_run_sends_no_description(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;

        $response = $this->send($this->request('POST', '/api/tryhackx/homepage/whitelist/scan', [
            'authenticatedAs' => 1,
            'json' => ['cursor' => 0, 'dry_run' => true],
        ]));
        $result = json_decode((string) $response->getBody(), true);

        $this->assertNull($result['describe']);
        $this->assertCount(0, $this->tracker->calls);
        $this->assertCount(0, $this->tracker->described);
    }

    private function patch(string $type, int $id, array $attributes, array $relationships = []): void
    {
        $data = ['type' => $type, 'id' => (string) $id];
        if ($attributes) {
            $data['attributes'] = $attributes;
        }
        if ($relationships) {
            $data['relationships'] = $relationships;
        }
        $response = $this->send($this->request('PATCH', '/api/' . $type . '/' . $id, [
            'authenticatedAs' => 1,
            'json' => ['data' => $data],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function lastDescribed(): array
    {
        return $this->tracker->described[count($this->tracker->described) - 1]['topics'];
    }

    private function sentRow(array $discussion): ?array
    {
        return $this->app()->getContainer()->make(SentTopics::class)->get((int) $discussion['id']);
    }

    #[Test]
    public function hiding_the_first_post_withdraws_the_topic_and_restoring_sends_it_again(): void
    {
        $this->tracker->describeOn = true;
        $content = "Opis\nmagnet:?xt=urn:btih:" . self::HASH;
        $data = $this->startDiscussion($content);
        $postId = $this->firstPostId($data);

        $this->patch('posts', $postId, ['isHidden' => true]);

        $this->assertSame([['discussion_id' => (int) $data['id'], 'post_id' => $postId, 'visible' => false, 'hashes' => [self::HASH]]], $this->lastDescribed());
        $sent = $this->sentRow($data);
        $this->assertFalse($sent['visible']);
        $this->assertSame('withdrawn', $sent['state']);
        $this->assertSame([self::HASH], $sent['hashes']);

        $this->patch('posts', $postId, ['isHidden' => false]);

        $topics = $this->lastDescribed();
        $this->assertCount(3, $this->tracker->described);
        $this->assertCount(1, $topics);
        $this->assertSame($content, $topics[0]['content']);
        $this->assertArrayNotHasKey('visible', $topics[0]);
        $this->assertTrue($this->sentRow($data)['visible']);
        $this->assertSame('published', $this->sentRow($data)['state']);
    }

    #[Test]
    public function hiding_the_discussion_withdraws_the_topic_and_restoring_sends_it_again(): void
    {
        $this->tracker->describeOn = true;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->patch('discussions', (int) $data['id'], ['isHidden' => true]);

        $this->assertSame(false, $this->lastDescribed()[0]['visible']);
        $this->assertFalse($this->sentRow($data)['visible']);

        $this->patch('discussions', (int) $data['id'], ['isHidden' => false]);

        $this->assertCount(3, $this->tracker->described);
        $this->assertArrayNotHasKey('visible', $this->lastDescribed()[0]);
        $this->assertTrue($this->sentRow($data)['visible']);
    }

    #[Test]
    public function deleting_the_discussion_releases_its_hashes(): void
    {
        $this->tracker->describeOn = true;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $postId = $this->firstPostId($data);

        $response = $this->send($this->request('DELETE', '/api/discussions/' . $data['id'], ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([['discussion_id' => (int) $data['id'], 'post_id' => $postId, 'visible' => false, 'gone' => true, 'hashes' => [self::HASH]]], $this->lastDescribed());
        $this->assertNull($this->sentRow($data));
        $this->assertNull($this->database()->table(TrackerQueue::TABLE)->where('discussion_id', $data['id'])->first());
    }

    #[Test]
    public function deleting_a_discussion_described_before_this_version_still_releases_its_hashes(): void
    {
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->assertNull($this->sentRow($data));

        $response = $this->send($this->request('DELETE', '/api/discussions/' . $data['id'], ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertCount(1, $this->tracker->described);
        $this->assertTrue($this->lastDescribed()[0]['gone']);
        $this->assertSame([self::HASH], $this->lastDescribed()[0]['hashes']);
    }

    #[Test]
    public function a_hash_edited_out_of_the_first_post_is_released(): void
    {
        $this->tracker->describeOn = true;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH . "\nmagnet:?xt=urn:btih:" . self::OTHER_HASH);
        $postId = $this->firstPostId($data);

        $this->patch('posts', $postId, ['content' => 'Only one now: magnet:?xt=urn:btih:' . self::HASH]);

        $topics = $this->lastDescribed();
        $this->assertCount(2, $topics);
        $this->assertSame([self::HASH], $topics[0]['hashes']);
        $this->assertSame('Only one now: magnet:?xt=urn:btih:' . self::HASH, $topics[0]['content']);
        $this->assertSame(['discussion_id' => (int) $data['id'], 'post_id' => $postId, 'visible' => false, 'gone' => true, 'hashes' => [self::OTHER_HASH]], $topics[1]);
        $this->assertSame([self::HASH], $this->sentRow($data)['hashes']);
    }

    #[Test]
    public function moving_a_topic_to_a_tag_guests_cannot_see_withdraws_it(): void
    {
        $this->prepareDatabase(['tags' => [['id' => 7, 'name' => 'Staff', 'slug' => 'staff', 'position' => 0, 'is_restricted' => 1]]]);
        $this->tracker->describeOn = true;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->patch('discussions', (int) $data['id'], [], ['tags' => ['data' => [['type' => 'tags', 'id' => '7']]]]);

        $this->assertSame(false, $this->lastDescribed()[0]['visible']);
        $this->assertFalse($this->sentRow($data)['visible']);

        $this->patch('discussions', (int) $data['id'], [], ['tags' => ['data' => []]]);

        $this->assertArrayNotHasKey('visible', $this->lastDescribed()[0]);
        $this->assertTrue($this->sentRow($data)['visible']);
    }

    #[Test]
    public function a_reply_a_guest_cannot_see_registers_nothing(): void
    {
        $discussion = $this->startDiscussion('No magnet here.');
        $this->patch('discussions', (int) $discussion['id'], ['isHidden' => true]);
        $this->tracker->calls = [];

        $this->reply($discussion, 'magnet:?xt=urn:btih:' . self::HASH);

        $this->assertCount(0, $this->tracker->calls);
    }

    #[Test]
    public function an_older_tracker_is_never_sent_a_withdrawal(): void
    {
        $this->tracker->describeOn = true;
        $this->tracker->withdraw = false;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->patch('discussions', (int) $data['id'], ['isHidden' => true]);

        $this->assertCount(1, $this->tracker->described);
        $this->assertTrue($this->sentRow($data)['visible']);
    }

    #[Test]
    public function the_description_pass_withdraws_topics_a_guest_can_no_longer_see(): void
    {
        $this->tracker->describeOn = true;
        $gone = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $kept = $this->startDiscussion('magnet:?xt=urn:btih:' . self::THIRD_HASH);
        $this->database()->table('discussions')->where('id', $gone['id'])->update(['is_private' => true]);
        $this->tracker->calls = [];
        $this->tracker->described = [];

        [, $result] = $this->describeAll();

        $this->assertTrue($result['done']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['describe']['published']);
        $this->assertSame(1, $result['describe']['withdrawn']);
        $topics = $this->lastDescribed();
        $this->assertSame(['discussion_id' => (int) $gone['id'], 'post_id' => $this->firstPostId($gone), 'visible' => false, 'hashes' => [self::HASH]], $topics[0]);
        $this->assertSame((int) $kept['id'], $topics[1]['discussion_id']);
        $this->assertFalse($this->sentRow($gone)['visible']);

        [, $again] = $this->describeAll();

        $this->assertSame(0, $again['describe']['withdrawn'], 'withdrawn once');
        $this->assertCount(2, $this->tracker->described);
        $this->assertCount(1, $this->lastDescribed());
    }

    #[Test]
    public function the_description_pass_releases_topics_deleted_behind_its_back(): void
    {
        $this->tracker->describeOn = true;
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $postId = $this->firstPostId($data);
        $this->database()->table('posts')->where('discussion_id', $data['id'])->delete();
        $this->database()->table('discussions')->where('id', $data['id'])->delete();

        [, $result] = $this->describeAll();

        $this->assertTrue($result['done']);
        $this->assertSame(1, $result['describe']['withdrawn']);
        $this->assertSame([['discussion_id' => (int) $data['id'], 'post_id' => $postId, 'visible' => false, 'gone' => true, 'hashes' => [self::HASH]]], $this->lastDescribed());
        $this->assertNull($this->sentRow($data));
    }

    private static function magnets(int $from, int $count): string
    {
        $lines = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $lines[] = 'magnet:?xt=urn:btih:' . str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
        }

        return implode("\n", $lines);
    }

    #[Test]
    public function the_scan_never_splits_a_post_at_the_hash_cap(): void
    {
        $first = $this->startDiscussion(self::magnets(1, 300));
        $second = $this->startDiscussion(self::magnets(1000, 300));
        $this->tracker->calls = [];

        $batch = $this->scan();

        $this->assertFalse($batch['done']);
        $this->assertSame(1, $batch['processed']);
        $this->assertSame(300, $batch['sent']);
        $this->assertSame($this->firstPostId($first), $batch['next_cursor']);

        $next = $this->scan($batch['next_cursor']);

        $this->assertTrue($next['done']);
        $this->assertSame(300, $next['sent']);
        $this->assertSame($this->firstPostId($second), $next['next_cursor']);
    }

    #[Test]
    public function a_post_with_more_hashes_than_one_call_takes_is_sent_in_several_calls(): void
    {
        $this->startDiscussion(self::magnets(1, 600));

        $this->assertSame([500, 100], array_map(fn ($call) => count($call['items']), $this->tracker->calls));
    }

    #[Test]
    public function the_status_lists_the_problems_with_links_and_the_counters(): void
    {
        $this->tracker->describeOn = true;
        $this->tracker->answers = [self::HASH => 'too_long'];
        $long = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->answers = [];
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::THIRD_HASH);
        $this->tracker->describeError = 'http_503';
        $failing = $this->startDiscussion('magnet:?xt=urn:btih:' . self::OTHER_HASH);

        $response = $this->send($this->request('GET', '/api/tryhackx/homepage/whitelist/status', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $status = json_decode((string) $response->getBody(), true);

        $this->assertSame(['published' => 1, 'waiting' => 0, 'proposed' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'error' => 1], $status['topics']);
        $this->assertSame(1, $status['queue']['pending']);
        $this->assertSame(1, $status['queue']['failing']);
        $this->assertTrue($status['describe']['enabled']);
        $problems = array_column($status['problems'], null, 'discussion_id');
        $this->assertSame('too_long', $problems[(int) $long['id']]['status']);
        $this->assertSame('failed', $problems[(int) $failing['id']]['status']);
        $this->assertSame('describe: http_503', $problems[(int) $failing['id']]['detail']);
        $this->assertSame('Release', $problems[(int) $failing['id']]['title']);
        $this->assertMatchesRegularExpression('#/d/' . $failing['id'] . '-release$#', $problems[(int) $failing['id']]['url']);

        $guest = $this->send($this->request('GET', '/api/tryhackx/homepage/whitelist/status'));
        $this->assertSame(403, $guest->getStatusCode());
    }
}

/** Records submits (and the transaction level at that moment) instead of calling a tracker. */
class RecordingWhitelistClient extends TrackerWhitelistClient
{
    /** @var array<int, array{items: array, level: int}> */
    public array $calls = [];
    public bool $fail = false;
    public ?string $submitError = null;
    public ?ConnectionInterface $db = null;
    public bool $describeOn = false;
    public string $audienceValue = 'guests';
    public ?string $describeError = null;
    public array $described = [];
    public array $pings = [];
    public array $pingJson = ['ok' => true];
    public ?bool $withdraw = true;
    public array $answers = [];

    public function __construct()
    {
        // No parent constructor: every method the sync path uses is overridden.
    }

    public function describeLimits(): array
    {
        return ['chars' => null, 'bytes' => DescriptionPayload::MAX_BYTES, 'topics' => DescriptionPayload::MAX_TOPICS,
            'hashes' => DescriptionPayload::MAX_HASHES, 'withdraw' => $this->withdraw, 'at' => time()];
    }

    public function limitsStale(): bool
    {
        return false;
    }

    public function paceDelay(int $calls = 1): int
    {
        return 0;
    }

    public function describePausedUntil(): ?int
    {
        return null;
    }

    public function cooldown(): ?array
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function inCooldown(): bool
    {
        return false;
    }

    public function detectBareHashes(): bool
    {
        return false;
    }

    public function requireTracker(): bool
    {
        return false;
    }

    public function submit(array $items, string $source = 'forum', ?int $timeout = null): array
    {
        $this->calls[] = ['items' => $items, 'level' => $this->db ? $this->db->transactionLevel() : -1];
        if ($this->fail) {
            throw new \RuntimeException('tracker down');
        }
        if ($this->submitError !== null) {
            return ['ok' => false, 'status' => 503, 'json' => null, 'error' => $this->submitError, 'elapsed_ms' => 1];
        }

        return ['ok' => true, 'status' => 200, 'json' => ['summary' => ['added' => count($items)]], 'error' => null, 'elapsed_ms' => 1];
    }

    public function describeEnabled(): bool
    {
        return $this->describeOn;
    }

    public function audience(): string
    {
        return $this->audienceValue;
    }

    public function describePaused(): bool
    {
        return false;
    }

    public function copyImages(): bool
    {
        return false;
    }

    public function ping(?array $override = null, int $timeout = 6, ?bool $consent = null): array
    {
        $this->pings[] = ['override' => $override, 'consent' => $consent];

        return ['ok' => true, 'status' => 200, 'json' => $this->pingJson, 'error' => null, 'elapsed_ms' => 1];
    }

    public function lastError(): ?array
    {
        return null;
    }

    public function describe(array $topics, int $maxChars, ?int $timeout = null): array
    {
        $this->described[] = ['topics' => $topics, 'max_chars' => $maxChars, 'level' => $this->db ? $this->db->transactionLevel() : -1, 'submits' => count($this->calls)];
        if ($this->describeError !== null) {
            $status = ['describe_off' => 200, 'http_429' => 429][$this->describeError] ?? 503;

            return ['ok' => false, 'status' => $status, 'json' => null, 'error' => $this->describeError, 'elapsed_ms' => 1] + ($status === 429 ? ['retry_after' => 30] : []);
        }

        $results = [];
        $summary = [];
        foreach ($topics as $i => $topic) {
            foreach ($topic['hashes'] as $hash) {
                $status = $this->answers[$hash] ?? (isset($topic['visible']) ? (empty($topic['gone']) ? 'withdrawn' : 'released') : 'created');
                $results[] = ['topic' => $i, 'discussion_id' => $topic['discussion_id'], 'hash' => $hash, 'status' => $status, 'detail' => null];
                $summary[$status] = ($summary[$status] ?? 0) + 1;
            }
        }

        return ['ok' => true, 'status' => 200, 'json' => ['ok' => true, 'summary' => $summary, 'results' => $results], 'error' => null, 'elapsed_ms' => 1];
    }
}
