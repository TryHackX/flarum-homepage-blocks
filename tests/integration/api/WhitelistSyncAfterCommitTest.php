<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use PHPUnit\Framework\Attributes\Test;
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
}

/** Records submits (and the transaction level at that moment) instead of calling a tracker. */
class RecordingWhitelistClient extends TrackerWhitelistClient
{
    /** @var array<int, array{items: array, level: int}> */
    public array $calls = [];
    public bool $fail = false;
    public ?ConnectionInterface $db = null;

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

        return ['ok' => true, 'status' => 200, 'json' => ['summary' => ['added' => count($items)]], 'error' => null, 'elapsed_ms' => 1];
    }
}
