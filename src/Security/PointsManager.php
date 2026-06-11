<?php

namespace TryHackX\HomepageBlocks\Security;

use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TryHackX\HomepageBlocks\Concerns\ResolvesClientIp;

/**
 * Limiter akcji oparty na kubełku punktów (token-bucket) per IP klienta.
 *
 * Magazyn: storage/cache/tryhackx_points/{hash}.json — jeden plik na IP.
 * Zawartość: {"balance": <float>, "ts": <unix>, "blocked_until": <unix|0>}.
 *
 * Model działania:
 *   - Każda chroniona akcja zdejmuje z kubełka koszt akcji.
 *   - Kubełek odnawia się w czasie (+refill_amount co refill_seconds), do `start`
 *     — dzięki temu normalne, regularne korzystanie nigdy nie wyczerpuje puli,
 *     ale szybkie spamowanie ją drenuje.
 *   - Gdy zabraknie punktów, egzekwowanie zależy od trybu (`enforcement`):
 *       * 'captcha' — użytkownik rozwiązuje reCAPTCHA, co odnawia kubełek,
 *       * 'block'   — IP zostaje tymczasowo zablokowane na `block_seconds`
 *                     (nie wymaga reCAPTCHA). Po wygaśnięciu blokady kubełek
 *                     jest resetowany do pełna.
 *
 * IP wyznaczamy przez rdzeń Flarum (atrybut `ipAddress`), NIE przez spoofowalne
 * nagłówki — patrz {@see ResolvesClientIp}.
 */
class PointsManager
{
    use ResolvesClientIp;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_enabled');
    }

    /**
     * Tryb egzekwowania po wyczerpaniu punktów: 'captcha' lub 'block'.
     * Domyślnie 'captcha' (zachowanie zgodne wstecz).
     */
    public function getEnforcement(): string
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_enforcement');
        $mode = is_string($raw) ? strtolower(trim($raw)) : '';
        return $mode === 'block' ? 'block' : 'captcha';
    }

    /**
     * Czas blokady IP w sekundach (tryb 'block'). Domyślnie 60, min 1.
     */
    public function getBlockSeconds(): int
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_block_seconds');
        return ($raw === null || $raw === '') ? 60 : max(1, (int) $raw);
    }

    /**
     * Co zrobić z budżetem, GDY blokada IP się skończy:
     *   'full'  — przywróć pełny budżet startowy (domyślnie, łagodniej),
     *   'empty' — zostaw 0 i odnawiaj stopniowo od zera (surowiej).
     *
     * UWAGA: w OBU trybach podczas trwania blokady odnawianie jest zamrożone —
     * blokada to stały „cooldown" o długości block_seconds, nie zdejmowany wcześniej.
     */
    public function getBlockReset(): string
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_block_reset');
        $mode = is_string($raw) ? strtolower(trim($raw)) : '';
        return $mode === 'empty' ? 'empty' : 'full';
    }

    /**
     * Koszt akcji dla danego zakresu (z dopłatą dla gości).
     */
    public function getCost(string $scope, bool $isGuest): float
    {
        $defaults = [
            'random' => 0.5,
            'search' => 3.0,
            'stats' => 1.0,
            'external_stats' => 1.0,
        ];

        $key = 'tryhackx-homepage-blocks.recaptcha_points_cost_' . $scope;
        $raw = $this->settings->get($key);
        $cost = ($raw === null || $raw === '') ? ($defaults[$scope] ?? 1.0) : (float) $raw;

        if ($isGuest) {
            $extra = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_guest_extra');
            $extra = ($extra === null || $extra === '') ? 2.0 : (float) $extra;
            $cost += $extra;
        }

        return max(0.0, $cost);
    }

    public function getStart(): float
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_start');
        return ($raw === null || $raw === '') ? 10.0 : max(0.0, (float) $raw);
    }

    public function getRefillSeconds(): int
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_refill_seconds');
        return ($raw === null || $raw === '') ? 15 : max(1, (int) $raw);
    }

    public function getRefillAmount(): float
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_refill_amount');
        return ($raw === null || $raw === '') ? 1.0 : max(0.0, (float) $raw);
    }

    /**
     * Stabilne IP dzwoniącego (rdzeń Flarum, odporne na spoofing nagłówków).
     */
    public function getIp(ServerRequestInterface $request): string
    {
        return $this->getClientIp($request);
    }

    /**
     * Pozostały czas blokady IP w sekundach (0 = nie zablokowany).
     * Wygasłą blokadę czyści i resetuje kubełek do pełna.
     */
    public function getBlockRemaining(string $ip): int
    {
        return $this->withLock($ip, function () use ($ip) {
            $state = $this->normalize($this->readState($ip));
            $this->writeState($ip, $state);

            $remaining = (int) $state['blocked_until'] - time();
            return max(0, $remaining);
        });
    }

    /**
     * Zablokuj IP na zadaną liczbę sekund (drenując kubełek).
     */
    public function block(string $ip, int $seconds): int
    {
        $seconds = max(1, $seconds);
        return $this->withLock($ip, function () use ($ip, $seconds) {
            $state = $this->normalize($this->readState($ip));
            $state['balance'] = 0.0;
            $state['ts'] = time();
            $state['blocked_until'] = time() + $seconds;
            $this->writeState($ip, $state);
            return $seconds;
        });
    }

    /**
     * Spróbuj pobrać koszt z kubełka IP.
     * Zwraca:
     *   ['ok' => true,  'balance' => float]  — pobrano
     *   ['ok' => false, 'balance' => float]  — za mało punktów
     */
    public function charge(string $ip, float $cost): array
    {
        // Całość read→sprawdź→write pod ekskluzywną blokadą per-IP, inaczej dwa
        // równoległe workery odczytałyby to samo saldo i każdy pobrałby koszt,
        // a wygrałby tylko ostatni zapis (TOCTOU) — co pozwalało zdrenować kubełek
        // wolniej, niż wynika z konfiguracji, i osłabiało limiter (audyt A3).
        return $this->withLock($ip, function () use ($ip, $cost) {
            $state = $this->normalize($this->readState($ip));

            // Aktywna blokada — nic nie pobieramy.
            if ((int) $state['blocked_until'] > time()) {
                $this->writeState($ip, $state);
                return ['ok' => false, 'balance' => (float) $state['balance']];
            }

            if ($state['balance'] < $cost) {
                $this->writeState($ip, $state);
                return ['ok' => false, 'balance' => (float) $state['balance']];
            }

            $state['balance'] = max(0.0, $state['balance'] - $cost);
            $state['ts'] = time();
            $this->writeState($ip, $state);
            return ['ok' => true, 'balance' => (float) $state['balance']];
        });
    }

    /**
     * Odnów saldo do wartości startowej i zdejmij ewentualną blokadę
     * (wywoływane po poprawnym captcha).
     */
    public function refillToStart(string $ip): float
    {
        $start = $this->getStart();
        $this->withLock($ip, function () use ($ip, $start) {
            $this->writeState($ip, ['balance' => $start, 'ts' => time(), 'blocked_until' => 0]);
        });
        return $start;
    }

    // ────────────── Magazyn wewnętrzny ──────────────

    /**
     * Wykonaj operację read-modify-write na kubełku danego IP pod ekskluzywną
     * blokadą plikową (osobny plik .lock per IP). Serializuje równoległe workery
     * obsługujące to samo IP, więc księgowanie punktów jest atomowe.
     *
     * Gdy locka nie da się założyć (np. brak praw do pliku), wykonujemy callback
     * bez niego — limiter ma działać best-effort, a nie paść przez brak .lock.
     *
     * @template T
     * @param  callable():T $fn
     * @return T
     */
    protected function withLock(string $ip, callable $fn)
    {
        $lockPath = $this->getFile($ip) . '.lock';
        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            return $fn();
        }

        $locked = @flock($fp, LOCK_EX);
        try {
            return $fn();
        } finally {
            if ($locked) {
                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }
    }

    protected function getDir(): string
    {
        $dir = $this->paths->storage . '/cache/tryhackx_points';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    protected function getFile(string $ip): string
    {
        return $this->getDir() . '/' . sha1($ip) . '.json';
    }

    protected function readState(string $ip): array
    {
        $fresh = ['balance' => $this->getStart(), 'ts' => time(), 'blocked_until' => 0];

        $file = $this->getFile($ip);
        if (!file_exists($file)) {
            return $fresh;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $fresh;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['balance'], $data['ts'])) {
            return $fresh;
        }

        return [
            'balance' => (float) $data['balance'],
            'ts' => (int) $data['ts'],
            'blocked_until' => (int) ($data['blocked_until'] ?? 0),
        ];
    }

    protected function writeState(string $ip, array $state): void
    {
        $file = $this->getFile($ip);

        // Zapis atomowy: zapisz do pliku tymczasowego i podmień (rename jest atomowy).
        $payload = json_encode([
            'balance' => (float) $state['balance'],
            'ts' => (int) $state['ts'],
            'blocked_until' => (int) ($state['blocked_until'] ?? 0),
        ]);

        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) {
                @file_put_contents($file, $payload, LOCK_EX);
                @unlink($tmp);
            }
        }

        $this->maybeCollectGarbage();
    }

    /**
     * Wygaśnięcie blokady → reset do pełna; w przeciwnym razie zwykłe odnowienie.
     * Podczas aktywnej blokady saldo nie jest odnawiane.
     */
    protected function normalize(array $state): array
    {
        $now = time();
        $blockedUntil = (int) ($state['blocked_until'] ?? 0);

        if ($blockedUntil > 0 && $now >= $blockedUntil) {
            // Blokada minęła. Budżet zależnie od ustawienia: pełny (łagodniej)
            // albo pusty + odnawianie od zera (surowiej).
            $balance = $this->getBlockReset() === 'empty' ? 0.0 : $this->getStart();
            return ['balance' => $balance, 'ts' => $now, 'blocked_until' => 0];
        }

        if ($blockedUntil > $now) {
            // Wciąż zablokowany — bez odnawiania.
            return $state;
        }

        return $this->applyRefill($state);
    }

    /**
     * Dolicz zaległe odnowienia (czas / refill_seconds * refill_amount),
     * z górnym ograniczeniem do salda startowego.
     */
    protected function applyRefill(array $state): array
    {
        $now = time();
        $elapsed = $now - (int) $state['ts'];
        if ($elapsed <= 0) {
            return $state;
        }

        $refillSeconds = $this->getRefillSeconds();
        $refillAmount = $this->getRefillAmount();
        if ($refillAmount <= 0 || $refillSeconds <= 0) {
            return $state;
        }

        $ticks = (int) floor($elapsed / $refillSeconds);
        if ($ticks <= 0) {
            return $state;
        }

        $max = $this->getStart();
        $newBalance = min($max, (float) $state['balance'] + ($ticks * $refillAmount));

        return [
            'balance' => $newBalance,
            'ts' => (int) $state['ts'] + ($ticks * $refillSeconds),
            'blocked_until' => (int) ($state['blocked_until'] ?? 0),
        ];
    }

    /**
     * Probabilistyczne sprzątanie: co jakiś czas usuwa pliki kubełków, które od
     * dawna nie były ruszane (są w pełni odnowione i nie są zablokowane), żeby
     * katalog nie rósł w nieskończoność na ruchu botów. Patrz audyt B4.
     */
    protected function maybeCollectGarbage(): void
    {
        // ~2% zapisów.
        if (mt_rand(1, 50) !== 1) {
            return;
        }

        // Po tym czasie bezczynności kubełek i tak byłby pełny i odblokowany —
        // plik nie niesie żadnej informacji, można go skasować.
        $ttl = max(86400, $this->getBlockSeconds() * 4);
        $cutoff = time() - $ttl;

        try {
            $dir = $this->getDir();
            $files = @glob($dir . '/*.json');
            if (!is_array($files)) {
                $files = [];
            }
            // Osierocone pliki-blokady (.json.lock) też sprzątamy — glob '*.json'
            // ich nie łapie, a po dniach bezczynności i tak nic nie znaczą.
            $locks = @glob($dir . '/*.json.lock');
            if (is_array($locks)) {
                $files = array_merge($files, $locks);
            }
            $deleted = 0;
            foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $cutoff) {
                    @unlink($file);
                    // Bezpiecznik: nie usuwaj więcej niż 500 plików na przebieg.
                    if (++$deleted >= 500) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // sprzątanie jest best-effort — błędy ignorujemy
        }
    }
}
