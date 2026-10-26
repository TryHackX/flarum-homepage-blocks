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

    private function client(array $values): TrackerWhitelistClient
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

        $store = new class implements Store {
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

        return new class($settings, new NullLogger(), $store) extends TrackerWhitelistClient {
            public array $requests = [];

            protected function request(string $method, string $endpoint, ?array $body, int $timeout, ?array $override = null): array
            {
                $this->requests[] = ['method' => $method, 'endpoint' => $endpoint, 'body' => $body, 'override' => $override];
                return ['ok' => true, 'status' => 200, 'json' => ['ok' => true, 'summary' => []], 'error' => null, 'elapsed_ms' => 1];
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

        [$submit, $describe, $ping] = $this->requests($client);

        $this->assertFalse($client->copyImages());
        $this->assertFalse($submit['body']['copy_images']);
        $this->assertFalse($describe['body']['copy_images']);
        $this->assertSame('v1/whitelist/ping&copy_images=0', $ping['endpoint']);
    }

    #[Test]
    public function the_consent_reaches_every_body_and_the_ping(): void
    {
        $client = $this->client(['whitelist_sync_enabled' => '1', 'whitelist_image_copy' => '1']);

        [$submit, $describe, $ping] = $this->requests($client);

        $this->assertSame(['POST', 'v1/whitelist/submit'], [$submit['method'], $submit['endpoint']]);
        $this->assertSame(['items', 'source', 'copy_images'], array_keys($submit['body']));
        $this->assertTrue($submit['body']['copy_images']);

        $this->assertSame(['POST', 'v1/whitelist/describe'], [$describe['method'], $describe['endpoint']]);
        $this->assertSame(['topics', 'max_chars', 'copy_images'], array_keys($describe['body']));
        $this->assertSame(63000, $describe['body']['max_chars']);
        $this->assertTrue($describe['body']['copy_images']);

        $this->assertSame(['GET', 'v1/whitelist/ping&copy_images=1', null], [$ping['method'], $ping['endpoint'], $ping['body']]);
        $this->assertSame(['api_url' => 'https://tracker.example.org'], $ping['override']);
    }

    #[Test]
    public function the_consent_is_inert_while_the_sync_is_off(): void
    {
        $client = $this->client(['whitelist_image_copy' => '1']);

        [$submit, $describe, $ping] = $this->requests($client);

        $this->assertFalse($client->copyImages());
        $this->assertFalse($submit['body']['copy_images']);
        $this->assertFalse($describe['body']['copy_images']);
        $this->assertSame('v1/whitelist/ping&copy_images=0', $ping['endpoint']);
    }

    #[Test]
    public function an_unticked_consent_is_sent_as_false(): void
    {
        $client = $this->client(['whitelist_sync_enabled' => '1', 'whitelist_image_copy' => '0']);

        [$submit, $describe, $ping] = $this->requests($client);

        $this->assertFalse($submit['body']['copy_images']);
        $this->assertFalse($describe['body']['copy_images']);
        $this->assertSame('v1/whitelist/ping&copy_images=0', $ping['endpoint']);
    }
}
