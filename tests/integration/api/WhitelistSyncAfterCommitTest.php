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
use PHPUnit\Framework\Attributes\Test;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

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
    }

    #[Test]
    public function a_tracker_failure_never_breaks_posting(): void
    {
        $this->tracker->fail = true;

        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);

        $this->assertCount(1, $this->tracker->calls);
        $this->assertNotNull($this->database()->table('discussions')->where('id', $data['id'])->first(), 'the discussion is saved');
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
    public function a_post_awaiting_approval_syncs_nothing_until_it_is_approved(): void
    {
        $data = $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->calls = [];
        $this->tracker->describeOn = true;
        $post = CommentPost::query()->findOrFail($this->firstPostId($data));
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        $post->is_approved = false;
        $events->dispatch(new Posted($post));
        $this->assertCount(0, $this->tracker->calls);
        $this->assertCount(0, $this->tracker->described);

        $post->is_approved = true;
        $events->dispatch(new Posted($post));
        $this->assertCount(1, $this->tracker->calls);
        $this->assertCount(1, $this->tracker->described);
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
        $this->assertSame(['described' => 1, 'unchanged' => 0, 'kept' => 0, 'too_long' => 0, 'invalid' => 0, 'skipped' => 0, 'off' => false], $result['describe']);
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
    public function a_describe_failure_keeps_the_scan_cursor(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_503';

        $result = $this->scan();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['next_cursor']);
        $this->assertFalse($result['done']);
        $this->assertSame('describe: http_503', $result['error']);
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
        $this->assertSame(0, $result['describe']['described']);
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
        $this->assertSame('1', $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->get('tryhackx-homepage-blocks.whitelist_image_copy'));

        $this->saveSettings(['tryhackx-homepage-blocks.whitelist_image_copy' => '0']);

        $this->assertCount(2, $this->tracker->pings);
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
        $this->assertSame(2, $result['describe']['described']);
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
    public function a_failed_describe_keeps_the_description_pass_cursor(): void
    {
        $this->startDiscussion('magnet:?xt=urn:btih:' . self::HASH);
        $this->tracker->describeOn = true;
        $this->tracker->describeError = 'http_503';

        [, $result] = $this->describeAll();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['next_cursor']);
        $this->assertSame('describe: http_503', $result['error']);
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
    public ?string $describeError = null;
    public array $described = [];
    public array $pings = [];
    public array $pingJson = ['ok' => true];

    public function __construct()
    {
        // No parent constructor: every method the sync path uses is overridden.
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

    public function describePaused(): bool
    {
        return false;
    }

    public function copyImages(): bool
    {
        return false;
    }

    public function ping(?array $override = null, int $timeout = 6): array
    {
        $this->pings[] = ['override' => $override];

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
            return ['ok' => false, 'status' => $this->describeError === 'describe_off' ? 200 : 503, 'json' => null, 'error' => $this->describeError, 'elapsed_ms' => 1];
        }

        return ['ok' => true, 'status' => 200, 'json' => ['ok' => true, 'summary' => ['created' => count($topics)]], 'error' => null, 'elapsed_ms' => 1];
    }
}
