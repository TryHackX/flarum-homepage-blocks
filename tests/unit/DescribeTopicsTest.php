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

    private function tracker(callable $reply): TrackerWhitelistClient
    {
        return new class($reply) extends TrackerWhitelistClient {
            public array $calls = [];

            public function __construct(private $reply)
            {
            }

            public function copyImages(): bool
            {
                return false;
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
        $scanner = new WhitelistScanner($tracker, $url, new NullLogger(), new FieldLengthModifier($this->settings([]), new NullLogger()));
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
        $this->assertSame(5, $r['counters']['described']);
        $this->assertSame([5, 2, 3, 1, 2], array_map(fn ($c) => count($c['topics']), $tracker->calls));
        $this->assertSame([1, 2, 3, 4, 5], array_column(array_merge(...array_map(fn ($c) => count($c['topics']) <= 2 ? $c['topics'] : [], $tracker->calls)), 'post_id'));
    }

    #[Test]
    public function a_single_topic_still_too_large_goes_without_its_text(): void
    {
        $tracker = $this->tracker(fn (array $topics) => $topics[0]['content'] !== null ? ['ok' => false, 'status' => 413] : self::created($topics));

        $r = $this->send($tracker, $this->topics([1], 'long text'));

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertCount(2, $tracker->calls);
        $this->assertNull($tracker->calls[1]['topics'][0]['content']);
        $this->assertSame(9, $tracker->calls[1]['topics'][0]['chars']);
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
    public function a_failure_stops_after_the_calls_that_went_through(): void
    {
        $tracker = $this->tracker(fn (array $topics) => count($topics) === 25 ? self::created($topics) : ['ok' => false, 'status' => 429, 'error' => 'http_429']);

        $r = $this->send($tracker, $this->topics(range(1, 30)));

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['off']);
        $this->assertSame('http_429', $r['error']);
        $this->assertSame(25, $r['sent']);
        $this->assertSame(25, $r['counters']['described']);
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
    public function the_live_path_uses_the_configured_timeout(): void
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
        $r = $client->describe($this->topics([1]), self::LIMIT);
        $this->assertSame(['ok' => false, 'status' => 0, 'json' => null, 'error' => 'describe_off', 'elapsed_ms' => 0], $r);
        $this->assertFalse($client->inCooldown());

        $client->resumeDescribe();
        $this->assertFalse($client->describePaused());
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
