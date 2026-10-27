<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\DescriptionPayload;

class TrackerWhitelistClientTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef01234567';

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

    private function client(array $values, ?Store $store = null, array $reply = ['ok' => true, 'summary' => []]): TrackerWhitelistClient
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed['tryhackx-homepage-blocks.' . $key] = $value;
        }

        $settings = new class($prefixed) implements SettingsRepositoryInterface {
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

        return new class($settings, new NullLogger(), $store ?? $this->store(), $reply) extends TrackerWhitelistClient {
            public array $requests = [];

            public function __construct(SettingsRepositoryInterface $settings, NullLogger $logger, Store $store, private array $reply)
            {
                parent::__construct($settings, $logger, $store);
            }

            protected function request(string $method, string $endpoint, ?array $body, int $timeout, ?array $override = null, array $flags = []): array
            {
                $this->requests[] = ['method' => $method, 'endpoint' => $endpoint, 'body' => $body, 'override' => $override, 'timeout' => $timeout, 'flags' => $flags];
                return ['ok' => true, 'status' => 200, 'json' => $this->reply, 'error' => null, 'elapsed_ms' => 1];
            }
        };
    }

    private function requests(TrackerWhitelistClient $client): array
    {
        $client->submit([['hash' => self::HASH]]);
        $client->describe(DescriptionPayload::topics(1, 2, null, null, null, 'text', 63000, [self::HASH]), 63000);
        $client->ping(['api_url' => 'https://tracker.example.org']);
        return $client->requests;
    }

    #[Test]
    public function the_consent_is_off_by_default(): void
    {
        $client = $this->client(['whitelist_sync_enabled' => '1']);

        [$submit, $describe] = $this->requests($client);

        $this->assertFalse($client->copyImages());
        $this->assertFalse($submit['body']['copy_images']);
        $this->assertFalse($describe['body']['copy_images']);
    }

    #[Test]
    public function the_consent_reaches_every_body(): void
    {
        $client = $this->client(['whitelist_sync_enabled' => '1', 'whitelist_image_copy' => '1']);

        [$submit, $describe] = $this->requests($client);

        $this->assertSame(['POST', 'v1/whitelist/submit'], [$submit['method'], $submit['endpoint']]);
        $this->assertSame(['items', 'source', 'copy_images'], array_keys($submit['body']));
        $this->assertTrue($submit['body']['copy_images']);

        $this->assertSame(['POST', 'v1/whitelist/describe'], [$describe['method'], $describe['endpoint']]);
        $this->assertSame(['topics', 'max_chars', 'copy_images'], array_keys($describe['body']));
        $this->assertSame(63000, $describe['body']['max_chars']);
        $this->assertTrue($describe['body']['copy_images']);
    }

    #[Test]
    public function the_consent_is_inert_while_the_sync_is_off(): void
    {
        $client = $this->client(['whitelist_image_copy' => '1']);

        [$submit, $describe] = $this->requests($client);

        $this->assertFalse($client->copyImages());
        $this->assertFalse($submit['body']['copy_images']);
        $this->assertFalse($describe['body']['copy_images']);
    }

    #[Test]
    public function a_plain_ping_never_pushes_the_consent(): void
    {
        foreach ([['whitelist_sync_enabled' => '1', 'whitelist_image_copy' => '1'], ['whitelist_image_copy' => '1'], []] as $settings) {
            $client = $this->client($settings);

            $ping = $this->requests($client)[2];

            $this->assertSame(['GET', 'v1/whitelist/ping', null], [$ping['method'], $ping['endpoint'], $ping['body']]);
            $this->assertSame(['api_url' => 'https://tracker.example.org'], $ping['override']);
        }
    }

    #[Test]
    public function a_consent_ping_carries_exactly_the_value_asked_for(): void
    {
        $client = $this->client(['whitelist_sync_enabled' => '1']);

        $client->ping(null, 6, true);
        $client->ping(null, 6, false);

        $this->assertSame(['v1/whitelist/ping&copy_images=1', 'v1/whitelist/ping&copy_images=0'], array_column($client->requests, 'endpoint'));
    }

    #[Test]
    public function describe_has_its_own_longer_timeout_and_never_cools_the_client_down(): void
    {
        $client = $this->client(['whitelist_sync_enabled' => '1', 'whitelist_timeout_seconds' => '3']);

        [$submit, $describe, $ping] = $this->requests($client);

        $this->assertSame(3, $submit['timeout']);
        $this->assertSame([], $submit['flags']);
        $this->assertSame(TrackerWhitelistClient::DESCRIBE_TIMEOUT, $describe['timeout']);
        $this->assertSame(['soft' => true], $describe['flags']);
        $this->assertSame(['pace' => false], $ping['flags']);
    }

    #[Test]
    public function the_limits_fall_back_to_the_constants_until_a_ping_answers(): void
    {
        $store = $this->store();
        $client = $this->client(['whitelist_sync_enabled' => '1'], $store, [
            'ok' => true, 'describe_max_chars' => 1000000, 'describe_max_bytes' => 4194304, 'describe_max_topics' => 25, 'describe_max_hashes' => 30,
        ]);

        $this->assertSame(['chars' => null, 'bytes' => DescriptionPayload::MAX_BYTES, 'topics' => DescriptionPayload::MAX_TOPICS,
            'hashes' => DescriptionPayload::MAX_HASHES, 'withdraw' => null, 'tags' => null, 'at' => null], $client->describeLimits());
        $this->assertTrue($client->limitsStale());

        $client->ping();

        $limits = $client->describeLimits();
        $this->assertSame([1000000, 4194304, 25, 30, true], [$limits['chars'], $limits['bytes'], $limits['topics'], $limits['hashes'], $limits['withdraw']]);
        $this->assertFalse($client->limitsStale());
    }

    #[Test]
    public function the_ping_says_whether_the_tracker_takes_tags_and_a_describe_answer_keeps_it(): void
    {
        $ping = TrackerWhitelistClient::limitsFrom(['ok' => true, 'describe_max_hashes' => 20, 'describe_tags' => true], true, []);
        $this->assertTrue($ping['tags']);
        $kept = TrackerWhitelistClient::limitsFrom(['ok' => true, 'max_topics' => 10, 'summary' => ['created' => 1]], false, $ping);
        $this->assertTrue($kept['tags']);
        $older = TrackerWhitelistClient::limitsFrom(['ok' => true, 'describe_max_hashes' => 20], true, $ping);
        $this->assertFalse($older['tags']);
    }

    #[Test]
    public function limits_written_before_tags_existed_ask_for_a_new_ping(): void
    {
        $store = $this->store();
        $client = $this->client(['whitelist_sync_enabled' => '1'], $store, ['ok' => true, 'describe_max_hashes' => 20, 'describe_tags' => true]);
        $store->write('whitelist:limits', ['topics' => 25, 'hashes' => 20, 'withdraw' => true, 'at' => time()], 3600);
        $this->assertNull($client->describeLimits()['tags']);
        $this->assertTrue($client->limitsStale());

        $client->ping();

        $this->assertTrue($client->describeLimits()['tags']);
        $this->assertFalse($client->limitsStale());
    }

    #[Test]
    public function a_ping_with_unsaved_values_does_not_store_their_limits(): void
    {
        $store = $this->store();
        $client = $this->client(['whitelist_sync_enabled' => '1', 'whitelist_api_url' => 'https://tracker.example.org', 'whitelist_api_key_id' => '0123456789abcdef'], $store, [
            'ok' => true, 'describe_max_topics' => 5, 'describe_max_hashes' => 5,
        ]);

        $client->ping(['api_url' => 'https://other.example.org']);
        $this->assertNull($client->describeLimits()['at']);

        $client->ping(['api_url' => 'https://tracker.example.org/', 'api_key_id' => '0123456789ABCDEF']);
        $this->assertSame(5, $client->describeLimits()['topics']);
    }

    #[Test]
    public function limits_are_read_from_pings_and_describe_answers(): void
    {
        $old = ['ok' => true, 'describe_max_chars' => 1000000, 'describe_max_bytes' => 4194304, 'describe_max_topics' => 25];
        $ping = TrackerWhitelistClient::limitsFrom($old, true, []);
        $this->assertSame([1000000, 4194304, 25, false], [$ping['chars'], $ping['bytes'], $ping['topics'], $ping['withdraw']]);
        $this->assertArrayNotHasKey('hashes', $ping);

        $describe = TrackerWhitelistClient::limitsFrom(['ok' => true, 'max_chars' => 63000, 'max_topics' => 10, 'max_hashes' => 15, 'max_bytes' => 1, 'summary' => ['withdrawn' => 0]], false, $ping);
        $this->assertSame([1000000, 65536, 10, 15, true], [$describe['chars'], $describe['bytes'], $describe['topics'], $describe['hashes'], $describe['withdraw']]);
        $this->assertSame($ping['at'], $describe['at']);

        $older = TrackerWhitelistClient::limitsFrom(['ok' => true, 'summary' => ['created' => 1]], false, $ping);
        $this->assertFalse($older['withdraw']);

        $nonsense = TrackerWhitelistClient::limitsFrom(['describe_max_topics' => 0, 'describe_max_hashes' => 'many', 'describe_max_chars' => 99999999], true, []);
        $this->assertSame([1, 16000000, true], [$nonsense['topics'], $nonsense['chars'], $nonsense['withdraw']]);
        $this->assertArrayNotHasKey('hashes', $nonsense);
    }

    #[Test]
    public function the_consent_counts_as_delivered_only_when_the_answer_agrees(): void
    {
        $this->assertTrue(TrackerWhitelistClient::consentEchoed(['copy_images' => true, 'images' => 'no_consent'], true));
        $this->assertFalse(TrackerWhitelistClient::consentEchoed(['copy_images' => false], true));
        $this->assertTrue(TrackerWhitelistClient::consentEchoed(['images' => 'copy'], true));
        $this->assertFalse(TrackerWhitelistClient::consentEchoed(['images' => 'copy'], false));
        $this->assertTrue(TrackerWhitelistClient::consentEchoed(['images' => 'no_consent'], false));
        $this->assertFalse(TrackerWhitelistClient::consentEchoed(['images' => 'no_consent'], true));
        $this->assertTrue(TrackerWhitelistClient::consentEchoed(['images' => 'off'], true));
        $this->assertTrue(TrackerWhitelistClient::consentEchoed(['ok' => true], false));
    }

    #[Test]
    public function calls_are_paced_under_the_trackers_rate_limit(): void
    {
        $store = $this->store();
        $client = $this->client(['whitelist_sync_enabled' => '1'], $store);
        $now = time();

        $this->assertSame(0, $client->paceDelay(3));

        $store->write('whitelist:pace', ['t' => array_merge(array_fill(0, 30, $now - 50), array_fill(0, 8, $now - 10))]);
        $this->assertSame(0, $client->paceDelay(2));
        $this->assertEqualsWithDelta(10, $client->paceDelay(3), 1);

        $store->write('whitelist:pace', ['t' => array_merge([$now - 70, $now - 61], array_fill(0, 39, $now - 5))]);
        $this->assertSame(0, $client->paceDelay(1));
        $this->assertEqualsWithDelta(55, $client->paceDelay(2), 1);
    }

    #[Test]
    public function the_cooldown_tells_how_long_and_why(): void
    {
        $store = $this->store();
        $client = $this->client(['whitelist_sync_enabled' => '1'], $store);

        $this->assertNull($client->cooldown());
        $this->assertFalse($client->inCooldown());

        $store->write('whitelist:backoff', ['until' => time() + 42, 'reason' => 'http_429']);
        $cooldown = $client->cooldown();
        $this->assertSame('http_429', $cooldown['reason']);
        $this->assertEqualsWithDelta(42, $cooldown['retry_after'], 1);
        $this->assertTrue($client->inCooldown());

        $store->write('whitelist:backoff', ['until' => time() - 1, 'reason' => 'transport']);
        $this->assertNull($client->cooldown());
    }
}
