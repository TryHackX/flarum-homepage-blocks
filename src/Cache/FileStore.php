<?php

namespace TryHackX\HomepageBlocks\Cache;

use Flarum\Foundation\Paths;

/**
 * Natywny magazyn plikowy — domyślna implementacja {@see Store}.
 *
 * Jeden plik na klucz w storage/cache/tryhackx_store/{sha1(key)}.json, z osobnym
 * plikiem .lock per klucz. Zapis jest atomowy (tmp + rename), a sprzątanie
 * przeterminowanych wpisów (głównie kubełków per-IP) biegnie strumieniowo i z
 * twardymi limitami, więc nie staje się spike'iem I/O na dużym katalogu.
 *
 * To celowo NIE używa cache Flarum — pojedynczy serwer dostaje sprawdzoną,
 * zależną tylko od PHP ścieżkę. Cross-node obsługuje {@see CacheStore}.
 *
 * (Logika przeniesiona z dawnego PointsManagera + TrackerStatsController; ujednolicona.)
 */
class FileStore implements Store
{
    /** ~2% zapisów uruchamia GC. */
    private const GC_PROBABILITY = 50;
    /** Po tylu sekundach bezczynności wpis jest sprzątany (kubełek i tak byłby pełny/odblokowany). */
    private const GC_TTL = 86400;
    /** Twarde limity na jeden przebieg GC — spłaszczają spike I/O niezależnie od rozmiaru katalogu. */
    private const GC_MAX_SCAN = 2000;
    private const GC_MAX_DELETE = 500;

    public function __construct(
        protected Paths $paths
    ) {}

    public function read(string $key): ?array
    {
        $file = $this->fileFor($key);
        if (!is_file($file)) {
            return null;
        }

        $mtime = @filemtime($file);
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $age = $mtime === false ? 0 : max(0, time() - $mtime);
        return ['value' => $data, 'age' => $age];
    }

    public function write(string $key, array $value, ?int $ttl = null): void
    {
        $file = $this->fileFor($key);
        $payload = json_encode($value);

        // Zapis atomowy: do pliku tymczasowego, potem rename (atomowy). Czytelnik
        // bez locka nigdy nie trafi na rozerwany plik. Fallback gdy rename po
        // istniejącym pliku zawiedzie (zdarza się na Windows).
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) {
                @file_put_contents($file, $payload, LOCK_EX);
                @unlink($tmp);
            }
        }

        // $ttl jest tu tylko informacyjne — w pliku świeżość liczymy z mtime, a
        // sprzątaniem zajmuje się GC. (W CacheStore $ttl wymusza realne wygaśnięcie.)
        $this->maybeCollectGarbage();
    }

    public function withLock(string $key, callable $fn, bool $wait = true, mixed $fallback = null): mixed
    {
        $lockPath = $this->fileFor($key) . '.lock';
        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            // Nie da się założyć locka (np. brak praw) — best-effort bez niego.
            return $fn();
        }

        $flags = $wait ? LOCK_EX : (LOCK_EX | LOCK_NB);
        $locked = @flock($fp, $flags);

        if (!$locked && !$wait) {
            // Single-flight: ktoś już trzyma blokadę — nie czekamy.
            @fclose($fp);
            return $fallback;
        }

        try {
            return $fn();
        } finally {
            if ($locked) {
                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }
    }

    protected function dir(): string
    {
        $dir = $this->paths->storage . '/cache/tryhackx_store';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    protected function fileFor(string $key): string
    {
        return $this->dir() . '/' . sha1($key) . '.json';
    }

    /**
     * Probabilistyczne, strumieniowe sprzątanie przeterminowanych wpisów. Czyta
     * katalog wpis po wpisie (opendir/readdir, O(1) pamięci — NIE glob()) i ma
     * twarde limity na przebieg, więc samo GC nie staje się blokującym I/O na
     * katalogu z setkami tysięcy kubełków. Backlog domykają kolejne przebiegi.
     */
    protected function maybeCollectGarbage(): void
    {
        if (mt_rand(1, self::GC_PROBABILITY) !== 1) {
            return;
        }

        $cutoff = time() - self::GC_TTL;

        try {
            $dir = $this->dir();
            $dh = @opendir($dir);
            if ($dh === false) {
                return;
            }

            $scanned = 0;
            $deleted = 0;
            try {
                while (($entry = readdir($dh)) !== false) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (++$scanned > self::GC_MAX_SCAN) {
                        break;
                    }
                    // Tylko nasze pliki: {hash}.json oraz osierocone {hash}.json.lock / .tmp.
                    if (!str_ends_with($entry, '.json')
                        && !str_ends_with($entry, '.json.lock')
                        && !str_ends_with($entry, '.tmp')) {
                        continue;
                    }

                    $file = $dir . '/' . $entry;
                    $mtime = @filemtime($file);
                    if ($mtime !== false && $mtime < $cutoff) {
                        @unlink($file);
                        if (++$deleted >= self::GC_MAX_DELETE) {
                            break;
                        }
                    }
                }
            } finally {
                closedir($dh);
            }
        } catch (\Throwable $e) {
            // sprzątanie jest best-effort — błędy ignorujemy
        }
    }
}
