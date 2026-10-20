<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Security\PointsManager;

/**
 * The per-IP token bucket. Time is simulated by moving the stored `ts` back.
 * Prod-like economy: start 12, +2 points every 100 s (long period: a second ticking
 * over mid-test can't complete one), a search costs 3.
 */
class PointsManagerTest extends TestCase
{
    private const IP = '203.0.113.5';

    private PointsManager $points;
    private Store $store;
    private string $key;

    protected function setUp(): void
    {
        $settings = new class([
            'tryhackx-homepage-blocks.ratelimit_enabled' => '1',
            'tryhackx-homepage-blocks.ratelimit_start' => '12',
            'tryhackx-homepage-blocks.ratelimit_refill_seconds' => '100',
            'tryhackx-homepage-blocks.ratelimit_refill_amount' => '2',
        ]) implements SettingsRepositoryInterface {
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

        $this->store = new class implements Store {
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

        $this->points = new PointsManager($settings, $this->store);
        $this->key = 'points:' . sha1(self::IP);
    }

    #[Test]
    public function the_part_of_a_refill_period_already_waited_survives_a_charge(): void
    {
        $now = time();
        $this->store->data[$this->key] = ['balance' => 6.0, 'ts' => $now - 50, 'blocked_until' => 0];

        $first = $this->points->charge(self::IP, 3.0);
        $this->assertTrue($first['ok']);
        $this->assertSame(3.0, $first['balance']);
        $this->assertSame($now - 50, $this->store->data[$this->key]['ts'], 'the 50 s already waited are kept');

        // 50 s more: the period completes, +2. (The clock used to restart at every
        // charge, so a visitor searching more often than every period never refilled.)
        $this->store->data[$this->key]['ts'] -= 50;
        $second = $this->points->charge(self::IP, 3.0);
        $this->assertTrue($second['ok']);
        $this->assertSame(2.0, $second['balance']);
    }

    #[Test]
    public function a_full_bucket_does_not_bank_refill_time(): void
    {
        $now = time();
        $this->store->data[$this->key] = ['balance' => 12.0, 'ts' => $now - 50, 'blocked_until' => 0];

        $this->assertSame(9.0, $this->points->charge(self::IP, 3.0)['balance']);
        $this->assertGreaterThanOrEqual($now, $this->store->data[$this->key]['ts'], 'the clock starts when the bucket stops being full');
    }

    #[Test]
    public function a_new_visitor_starts_full_and_an_empty_bucket_is_refused(): void
    {
        $this->assertSame(9.0, $this->points->charge(self::IP, 3.0)['balance']);

        $this->store->data[$this->key] = ['balance' => 2.0, 'ts' => time(), 'blocked_until' => 0];
        $refused = $this->points->charge(self::IP, 3.0);
        $this->assertFalse($refused['ok']);
        $this->assertSame(2.0, $refused['balance']);
    }

    #[Test]
    public function an_active_block_charges_nothing_and_an_expired_one_restores_the_budget(): void
    {
        $this->store->data[$this->key] = ['balance' => 0.0, 'ts' => time(), 'blocked_until' => time() + 60];
        $this->assertFalse($this->points->charge(self::IP, 3.0)['ok']);
        $this->assertGreaterThan(0, $this->points->getBlockRemaining(self::IP));

        $this->store->data[$this->key]['blocked_until'] = time() - 1;
        $this->assertSame(0, $this->points->getBlockRemaining(self::IP));
        $this->assertSame(9.0, $this->points->charge(self::IP, 3.0)['balance'], "block_reset 'full' (default)");
    }
}
