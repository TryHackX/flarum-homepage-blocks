<?php

namespace TryHackX\HomepageBlocks\Cache;

/**
 * Abstrakcja magazynu współdzielonego dla limitera punktów (per-IP token-bucket)
 * oraz cache statystyk (wewnętrzne agregaty + zewnętrzny OpenTracker).
 *
 * Istnieją DWIE implementacje, wybierane automatycznie (patrz
 * {@see \TryHackX\HomepageBlocks\Provider\StoreProvider}):
 *
 *   - {@see FileStore}  — domyślna, natywna (pliki + flock + atomowy rename).
 *     Poprawna i szybka na POJEDYNCZYM serwerze (także bardzo dużym: miliony
 *     userów na jednym hoście). NIE wymaga żadnego API cache Flarum — to celowo
 *     izolowana, „nigdy nie psująca się" ścieżka domyślna.
 *
 *   - {@see CacheStore} — używana TYLKO gdy Flarum ma skonfigurowany współdzielony,
 *     lockowalny driver cache (Redis/Memcached itp. — np. przez rozszerzenie
 *     fof/redis przepinające `cache.store`). Daje spójność CROSS-NODE: na klastrze
 *     wielu workerów/hostów limiter nie da się obejść „N× per node", a single-flight
 *     statystyk działa globalnie.
 *
 * Cała styczność z API cache Flarum żyje w {@see CacheStore} — jeśli rdzeń kiedyś
 * zmieni cache, łata się jedną klasę, a {@see FileStore} zostaje jako fallback.
 *
 * Model danych: wartością jest zawsze tablica (kubełek punktów albo zestaw
 * statystyk), serializowana do JSON. `read()` zwraca też WIEK wpisu w sekundach,
 * by wołający mógł zdecydować o świeżości (cache statystyk) — kubełek punktów wiek
 * ignoruje (nosi własny znacznik czasu w wartości).
 */
interface Store
{
    /**
     * Odczytaj wartość i jej wiek (sekundy od ostatniego zapisu).
     * Zwraca null, gdy klucz nie istnieje lub dane są uszkodzone.
     *
     * @return array{value: array, age: int}|null
     */
    public function read(string $key): ?array;

    /**
     * Zapisz wartość pod kluczem. $ttl (sekundy) to GÓRNA granica życia wpisu:
     * w CacheStore steruje wygaśnięciem (Redis sam usunie stary klucz), w FileStore
     * jest wskazówką dla sprzątania (GC). null = bez wymuszonego TTL.
     */
    public function write(string $key, array $value, ?int $ttl = null): void;

    /**
     * Wykonaj $fn pod EKSKLUZYWNĄ blokadą skojarzoną z $key — serializuje
     * równoległych workerów obsługujących ten sam klucz (atomowe read-modify-write).
     *
     * $wait = true  → czekaj na blokadę (limiter: operacje są krótkie). Gdy locka
     *                 nie da się założyć (skrajna kontencja / brak praw), $fn biegnie
     *                 best-effort BEZ locka — limiter ma działać, nie padać.
     * $wait = false → single-flight: gdy ktoś już trzyma blokadę, NIE czekaj i zwróć
     *                 $fallback bez uruchamiania $fn.
     *
     * @template T
     * @param  callable():T $fn
     * @return T|mixed
     */
    public function withLock(string $key, callable $fn, bool $wait = true, mixed $fallback = null): mixed;
}
