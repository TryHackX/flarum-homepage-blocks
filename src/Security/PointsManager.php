<?php

namespace TryHackX\HomepageBlocks\Security;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Concerns\ResolvesClientIp;

/**
 * Limiter akcji oparty na kubełku punktów (token-bucket) per IP klienta.
 *
 * Stan kubełka: {"balance": <float>, "ts": <unix>, "blocked_until": <unix|0>},
 * trzymany w {@see Store} pod kluczem `points:{sha1(ip)}` (IP jest zhashowane —
 * surowy adres nie trafia do magazynu/kluczy cache).
 *
 * Magazyn jest WYMIENNY (audyt #2/#3):
 *   - domyślnie {@see \TryHackX\HomepageBlocks\Cache\FileStore} (pliki+flock,
 *     poprawny na pojedynczym serwerze),
 *   - a gdy Flarum ma współdzielony, lockowalny cache (Redis…) — automatycznie
 *     {@see \TryHackX\HomepageBlocks\Cache\CacheStore}, dający atomowość CROSS-NODE
 *     (na klastrze IP nie obejdzie limitu „N× per node").
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
        protected Store $store
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
        return $this->store->withLock($this->key($ip), function () use ($ip) {
            $state = $this->normalize($this->readState($ip));
            $this->writeState($ip, $state);

            $remaining = (int) $state['blocked_until'] - time();
            return max(0, $remaining);
        }, true, 0); // fail-closed: brak locka → 0 (charge i tak zagrodzi) (audyt H2)
    }

    /**
     * Zablokuj IP na zadaną liczbę sekund (drenując kubełek).
     */
    public function block(string $ip, int $seconds): int
    {
        $seconds = max(1, $seconds);
        return $this->store->withLock($this->key($ip), function () use ($ip, $seconds) {
            $state = $this->normalize($this->readState($ip));
            $state['balance'] = 0.0;
            $state['ts'] = time();
            $state['blocked_until'] = time() + $seconds;
            $this->writeState($ip, $state);
            return $seconds;
        }, true, $seconds); // fail-closed: brak locka → zwróć zamierzony czas blokady (audyt H2)
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
        return $this->store->withLock($this->key($ip), function () use ($ip, $cost) {
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
        }, true, ['ok' => false, 'balance' => 0.0]); // fail-closed: brak locka → DENY (audyt H2)
    }

    /**
     * Odnów saldo do wartości startowej i zdejmij ewentualną blokadę
     * (wywoływane po poprawnym captcha).
     */
    public function refillToStart(string $ip): float
    {
        $start = $this->getStart();
        $this->store->withLock($this->key($ip), function () use ($ip, $start) {
            $this->writeState($ip, ['balance' => $start, 'ts' => time(), 'blocked_until' => 0]);
        });
        return $start;
    }

    // ────────────── Magazyn (delegowany do Store) ──────────────

    /**
     * Klucz magazynu dla IP. Hashujemy adres, więc surowe IP nie trafia do nazw
     * plików ani kluczy cache (Redis).
     */
    protected function key(string $ip): string
    {
        return 'points:' . sha1($ip);
    }

    /**
     * TTL kubełka: po tylu sekundach bezczynności i tak byłby pełny i odblokowany,
     * więc współdzielony cache może go wygasić (a GC plikowy — skasować).
     */
    protected function bucketTtl(): int
    {
        return max(86400, $this->getBlockSeconds() * 4);
    }

    protected function readState(string $ip): array
    {
        $fresh = ['balance' => $this->getStart(), 'ts' => time(), 'blocked_until' => 0];

        $read = $this->store->read($this->key($ip));
        if ($read === null) {
            return $fresh;
        }

        $data = $read['value'];
        if (!isset($data['balance'], $data['ts'])) {
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
        $this->store->write($this->key($ip), [
            'balance' => (float) $state['balance'],
            'ts' => (int) $state['ts'],
            'blocked_until' => (int) ($state['blocked_until'] ?? 0),
        ], $this->bucketTtl());
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
}
