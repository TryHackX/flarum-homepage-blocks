<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TryHackX\HomepageBlocks\Api\FieldLengthModifier;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\DescriptionPayload;
use TryHackX\HomepageBlocks\Service\SentTopics;
use TryHackX\HomepageBlocks\Service\TrackerQueue;
use TryHackX\HomepageBlocks\Service\WhitelistScanner;

class DescribeTopicsTest extends TestCase
{
    private const LIMIT = 500000;

    private function topics(array $postIds, string $content = 'text'): array
    {
        $out = [];
        foreach ($postIds as $i => $postId) {
            $out[] = DescriptionPayload::topics($postId, $postId, null, null, null, $content, self::LIMIT, [str_pad(dechex($i + 1), 40, '0', STR_PAD_LEFT)])[0];
        }
        return $out;
    }

    private function tracker(callable $reply, array $limits = []): TrackerWhitelistClient
    {
        return new class($reply, $limits) extends TrackerWhitelistClient {
            public array $calls = [];

            public function __construct(private $reply, private array $limits)
            {
            }

            public function copyImages(): bool
            {
                return false;
            }

            public function describeLimits(): array
            {
                return $this->limits + ['chars' => null, 'bytes' => DescriptionPayload::MAX_BYTES, 'topics' => DescriptionPayload::MAX_TOPICS,
                    'hashes' => DescriptionPayload::MAX_HASHES, 'withdraw' => true, 'at' => time()];
            }

            public function describe(array $topics, int $maxChars, ?int $timeout = null): array
            {
                $this->calls[] = ['topics' => $topics, 'max_chars' => $maxChars, 'timeout' => $timeout];
                return ($this->reply)($topics) + ['json' => null, 'error' => null, 'elapsed_ms' => 1];
            }
        };
    }

    private function send(TrackerWhitelistClient $tracker, array $topics, ?float $t0 = null, int $maxChars = self::LIMIT): array
    {
        $url = (new \ReflectionClass(UrlGenerator::class))->newInstanceWithoutConstructor();
        $sent = (new \ReflectionClass(SentTopics::class))->newInstanceWithoutConstructor();
        $queue = (new \ReflectionClass(TrackerQueue::class))->newInstanceWithoutConstructor();
        $scanner = new WhitelistScanner($tracker, $url, new NullLogger(), new FieldLengthModifier($this->settings([]), new NullLogger()), $sent, $queue);
        return (new \ReflectionMethod(WhitelistScanner::class, 'describeTopics'))->invoke($scanner, $topics, $maxChars, $t0);
    }

    private static function created(array $topics): array
    {
        return ['ok' => true, 'status' => 200, 'json' => ['ok' => true, 'summary' => ['created' => count($topics)]]];
    }

    #[Test]
    public function a_call_refused_as_too_large_is_split_until_it_fits(): void
    {
        $tracker = $this->tracker(fn (array $topics) => count($topics) > 2 ? ['ok' => false, 'status' => 413] : self::created($topics));

        $r = $this->send($tracker, $this->topics([1, 2, 3, 4, 5]));

        $this->assertTrue($r['ok']);
        $this->assertSame(5, $r['sent']);
        $this->assertSame(5, $r['counters']['published']);
        $this->assertSame([5, 2, 3, 1, 2], array_map(fn ($c) => count($c['topics']), $tracker->calls));
        $this->assertSame([1, 2, 3, 4, 5], array_column(array_merge(...array_map(fn ($c) => count($c['topics']) <= 2 ? $c['topics'] : [], $tracker->calls)), 'post_id'));
    }

    #[Test]
    public function a_single_topic_still_too_large_is_reported_too_big_in_bytes(): void
    {
        $tracker = $this->tracker(fn (array $topics) => ['ok' => false, 'status' => 413]);
        $topics = $this->topics([1], 'long text');

        $r = $this->send($tracker, $topics);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $tracker->calls);
        $this->assertSame(1, $r['counters']['too_long']);
        $this->assertSame([], $r['failed']);
        $bytes = strlen(json_encode(DescriptionPayload::body($topics, self::LIMIT, false), TrackerWhitelistClient::JSON_FLAGS));
        $this->assertSame([['discussion_id' => 1, 'hash' => $topics[0]['hashes'][0], 'status' => 'too_big', 'detail' => (string) $bytes, 'hidden' => false]], $r['results']);
    }

    #[Test]
    public function describe_off_stops_quietly(): void
    {
        $tracker = $this->tracker(fn () => ['ok' => false, 'status' => 200, 'error' => 'describe_off']);

        $r = $this->send($tracker, $this->topics([1, 2]));

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['off']);
        $this->assertSame(0, $r['sent']);
        $this->assertCount(1, $tracker->calls);
    }

    #[Test]
    public function a_rate_limit_stops_after_the_calls_that_went_through(): void
    {
        $tracker = $this->tracker(fn (array $topics) => count($topics) === 25 ? self::created($topics) : ['ok' => false, 'status' => 429, 'error' => 'http_429', 'retry_after' => 17]);

        $r = $this->send($tracker, $this->topics(range(1, 30)));

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['off']);
        $this->assertTrue($r['throttled']);
        $this->assertSame(17, $r['retry_after']);
        $this->assertSame('http_429', $r['error']);
        $this->assertSame(25, $r['sent']);
        $this->assertSame(25, $r['counters']['published']);
        $this->assertSame([], $r['failed']);
    }

    #[Test]
    public function a_call_refused_by_the_pacer_counts_as_throttled(): void
    {
        $tracker = $this->tracker(fn () => ['ok' => false, 'status' => 0, 'error' => 'paced', 'retry_after' => 9]);

        $r = $this->send($tracker, $this->topics([1, 2]));

        $this->assertTrue($r['throttled']);
        $this->assertSame(9, $r['retry_after']);
        $this->assertSame(0, $r['sent']);
    }

    #[Test]
    public function a_refused_call_fails_only_its_own_topics_and_the_rest_go_on(): void
    {
        $tracker = $this->tracker(fn (array $topics) => $topics[0]['post_id'] === 1 ? ['ok' => false, 'status' => 422, 'error' => 'http_422:too_many_topics'] : self::created($topics));

        $r = $this->send($tracker, $this->topics(range(1, 30)));

        $this->assertFalse($r['ok']);
        $this->assertSame(30, $r['sent']);
        $this->assertCount(2, $tracker->calls);
        $this->assertSame(range(1, 25), array_keys($r['failed']));
        $this->assertSame('http_422:too_many_topics', $r['failed'][1]);
        $this->assertSame(25, $r['counters']['errors']);
        $this->assertSame(5, $r['counters']['published']);
    }

    #[Test]
    public function a_dead_tracker_fails_every_remaining_topic_at_once(): void
    {
        $tracker = $this->tracker(fn () => ['ok' => false, 'status' => 0, 'error' => 'transport: timed out']);

        $r = $this->send($tracker, $this->topics(range(1, 30)));

        $this->assertCount(1, $tracker->calls);
        $this->assertSame(30, $r['sent']);
        $this->assertCount(30, $r['failed']);
        $this->assertSame(30, $r['counters']['errors']);
    }

    #[Test]
    public function per_hash_results_are_mapped_back_to_their_topics(): void
    {
        $hidden = DescriptionPayload::hidden(7, 70, [str_repeat('b', 40)], true);
        $topics = array_merge($this->topics([5]), $hidden);
        $tracker = $this->tracker(fn (array $topics) => ['ok' => true, 'status' => 200, 'json' => ['ok' => true, 'summary' => ['queued' => 1, 'released' => 1], 'results' => [
            ['topic' => 0, 'discussion_id' => 5, 'hash' => $topics[0]['hashes'][0], 'status' => 'queued', 'detail' => null],
            ['topic' => 1, 'discussion_id' => 7, 'hash' => str_repeat('b', 40), 'status' => 'released', 'detail' => null],
            ['topic' => 9, 'discussion_id' => 9, 'hash' => str_repeat('c', 40), 'status' => 'created', 'detail' => null],
        ]]]);

        $r = $this->send($tracker, $topics);

        $this->assertSame([
            ['discussion_id' => 5, 'hash' => $topics[0]['hashes'][0], 'status' => 'queued', 'detail' => null, 'hidden' => false],
            ['discussion_id' => 7, 'hash' => str_repeat('b', 40), 'status' => 'released', 'detail' => null, 'hidden' => true],
        ], $r['results']);
        $this->assertSame(1, $r['counters']['waiting']);
        $this->assertSame(1, $r['counters']['withdrawn']);
        $this->assertSame(0, $r['counters']['published']);
    }

    #[Test]
    public function calls_follow_the_limits_the_tracker_reported(): void
    {
        $tracker = $this->tracker(fn (array $topics) => self::created($topics), ['topics' => 4]);

        $this->send($tracker, $this->topics(range(1, 10)));

        $this->assertSame([4, 4, 2], array_map(fn ($c) => count($c['topics']), $tracker->calls));
    }

    #[Test]
    public function a_spent_budget_still_sends_until_one_whole_post_went_out(): void
    {
        $tracker = $this->tracker(fn (array $topics) => self::created($topics));
        $topics = array_merge(array_fill(0, 26, $this->topics([10])[0]), $this->topics([11, 12]));

        $r = $this->send($tracker, $topics, microtime(true) - 100);

        $this->assertTrue($r['ok']);
        $this->assertSame([25, 3], array_map(fn ($c) => count($c['topics']), $tracker->calls));
        $this->assertSame([3, 3], array_column($tracker->calls, 'timeout'));
        $this->assertSame(28, $r['sent']);
    }

    #[Test]
    public function a_spent_budget_stops_once_a_post_is_complete(): void
    {
        $tracker = $this->tracker(fn (array $topics) => self::created($topics));

        $r = $this->send($tracker, $this->topics(range(1, 30)), microtime(true) - 100);

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $tracker->calls);
        $this->assertSame(25, $r['sent']);
        $this->assertSame(25, DescriptionPayload::sentThrough($this->topics(range(1, 30)), $r['sent'], 0));
    }

    #[Test]
    public function the_batch_timeout_never_exceeds_the_describe_timeout(): void
    {
        $tracker = $this->tracker(fn (array $topics) => self::created($topics));

        $this->send($tracker, $this->topics([1]), microtime(true));

        $this->assertSame(TrackerWhitelistClient::DESCRIBE_TIMEOUT - 1, $tracker->calls[0]['timeout']);
    }

    #[Test]
    public function the_live_path_leaves_the_timeout_to_the_client(): void
    {
        $tracker = $this->tracker(fn (array $topics) => self::created($topics));

        $this->send($tracker, $this->topics([1]));

        $this->assertNull($tracker->calls[0]['timeout']);
    }

    #[Test]
    public function every_call_carries_the_forum_limit(): void
    {
        $tracker = $this->tracker(fn (array $topics) => count($topics) > 1 ? ['ok' => false, 'status' => 413] : self::created($topics));

        $this->send($tracker, $this->topics([1, 2, 3]), null, 63000);

        $this->assertSame([63000, 63000, 63000, 63000, 63000], array_column($tracker->calls, 'max_chars'));
    }

    private function settings(array $settings): SettingsRepositoryInterface
    {
        return new class($settings) implements SettingsRepositoryInterface {
            public function __construct(private array $values)
            {
            }

            public function all(): array
            {
                return $this->values;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $keyLike): void
            {
                unset($this->values[$keyLike]);
            }
        };
    }

    private function client(array $settings, ?Store $store = null): TrackerWhitelistClient
    {
        return new TrackerWhitelistClient($this->settings($settings), new NullLogger(), $store ?? $this->store());
    }

    private function store(): Store
    {
        return new class implements Store {
            public array $data = [];

            public function read(string $key): ?array
            {
                return isset($this->data[$key]) ? ['value' => $this->data[$key], 'age' => 0] : null;
            }

            public function write(string $key, array $value, ?int $ttl = null): void
            {
                $this->data[$key] = $value;
            }

            public function withLock(string $key, callable $fn, bool $wait = true, mixed $fallback = null): mixed
            {
                return $fn();
            }
        };
    }

    #[Test]
    public function descriptions_are_inert_while_the_sync_is_off(): void
    {
        $this->assertFalse($this->client(['tryhackx-homepage-blocks.whitelist_describe_enabled' => '1'])->describeEnabled());
        $this->assertFalse($this->client(['tryhackx-homepage-blocks.whitelist_sync_enabled' => '1'])->describeEnabled());
        $this->assertTrue($this->client([
            'tryhackx-homepage-blocks.whitelist_sync_enabled' => '1',
            'tryhackx-homepage-blocks.whitelist_describe_enabled' => '1',
        ])->describeEnabled());
    }

    #[Test]
    public function a_paused_describe_sends_nothing_until_resumed(): void
    {
        $store = $this->store();
        $store->write('whitelist:describe_off', ['until' => time() + 600]);
        $client = $this->client([], $store);

        $this->assertTrue($client->describePaused());
        $this->assertSame(time() + 600, $client->describePausedUntil());
        $r = $client->describe($this->topics([1]), self::LIMIT);
        $this->assertSame(['ok' => false, 'status' => 0, 'json' => null, 'error' => 'describe_off', 'elapsed_ms' => 0], $r);
        $this->assertFalse($client->inCooldown());

        $client->resumeDescribe();
        $this->assertFalse($client->describePaused());
        $this->assertNull($client->describePausedUntil());
        $this->assertSame('invalid_url', $client->describe($this->topics([1]), self::LIMIT)['error']);
    }

    #[Test]
    public function retry_after_is_read_as_seconds_or_a_date(): void
    {
        $this->assertSame(120, TrackerWhitelistClient::retryAfterSeconds('120'));
        $this->assertSame(1, TrackerWhitelistClient::retryAfterSeconds('0'));
        $this->assertSame(3600, TrackerWhitelistClient::retryAfterSeconds('86400'));
        $this->assertSame(30, TrackerWhitelistClient::retryAfterSeconds(null));
        $this->assertSame(30, TrackerWhitelistClient::retryAfterSeconds('soon'));
        $seconds = TrackerWhitelistClient::retryAfterSeconds(gmdate('D, d M Y H:i:s', time() + 90) . ' GMT');
        $this->assertGreaterThanOrEqual(88, $seconds);
        $this->assertLessThanOrEqual(90, $seconds);
    }
}
