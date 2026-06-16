<?php

namespace TryHackX\HomepageBlocks\Cache;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Współdzielony magazyn oparty na cache Flarum — używany TYLKO gdy skonfigurowany
 * driver cache jest lockowalny i współdzielony między hostami (Redis/Memcached…).
 * Wtedy daje spójność CROSS-NODE, której pliki + flock dać nie mogą.
 *
 * To JEDYNE miejsce w rozszerzeniu stykające się z API cache Flarum/Laravel
 * ({@see Repository}, {@see LockProvider}). Jeśli rdzeń kiedyś to zmieni, łata się
 * tę jedną klasę; ścieżka domyślna ({@see FileStore}) zależy wyłącznie od PHP.
 *
 * Wartość jest opakowana w {v: <dane>, t: <unix zapisu>}, by `read()` mógł policzyć
 * wiek (cache statystyk potrzebuje świeżości). Klucze są prefiksowane, żeby nie
 * kolidować z innymi konsumentami cache, a IP w kluczach limitera są już
 * zhashowane przez wołającego (nie trafia tu surowy adres).
 */
class CacheStore implements Store
{
    private const PREFIX = 'thxhb:';
    private const LOCK_PREFIX = 'thxhb_lock:';
    /** Po tylu sekundach blokada wygasa sama (zabezpieczenie przed deadlockiem, gdy worker padnie). */
    private const LOCK_TTL = 10;
    /** Ile sekund maks. czekać na blokadę w trybie $wait=true, zanim pójdziemy best-effort. */
    private const LOCK_WAIT = 5;
    /** Domyślne TTL wpisu, gdy wołający nie poda własnego. */
    private const DEFAULT_TTL = 86400;

    private LockProvider $locks;

    public function __construct(
        protected Repository $cache
    ) {
        // Bezpieczne: provider tworzy CacheStore tylko gdy store JEST LockProviderem.
        $this->locks = $cache->getStore();
    }

    public function read(string $key): ?array
    {
        $payload = $this->cache->get(self::PREFIX . $key);
        if (!is_array($payload) || !isset($payload['v']) || !is_array($payload['v'])) {
            return null;
        }

        $ts = (int) ($payload['t'] ?? 0);
        $age = $ts > 0 ? max(0, time() - $ts) : 0;
        return ['value' => $payload['v'], 'age' => $age];
    }

    public function write(string $key, array $value, ?int $ttl = null): void
    {
        $this->cache->put(
            self::PREFIX . $key,
            ['v' => $value, 't' => time()],
            $ttl !== null ? max(1, $ttl) : self::DEFAULT_TTL
        );
    }

    public function withLock(string $key, callable $fn, bool $wait = true, mixed $fallback = null): mixed
    {
        $lock = $this->locks->lock(self::LOCK_PREFIX . $key, self::LOCK_TTL);

        if (!$wait) {
            // Single-flight: spróbuj założyć bez czekania. get() zwraca false, gdy zajęte.
            $result = $lock->get($fn);
            return $result === false ? $fallback : $result;
        }

        try {
            // Czekaj ograniczony czas, potem zwolnij blokadę.
            return $lock->block(self::LOCK_WAIT, $fn);
        } catch (LockTimeoutException $e) {
            // Skrajna kontencja — best-effort bez locka (jak fallback FileStore),
            // żeby limiter działał, a nie padał.
            return $fn();
        }
    }
}
