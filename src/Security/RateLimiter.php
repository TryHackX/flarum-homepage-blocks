<?php

namespace TryHackX\HomepageBlocks\Security;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-IP rate limiter (token-bucket via {@see PointsManager}) for the user-initiated
 * `search` action. When the budget runs out, the IP is temporarily blocked.
 *
 * Zakresy: mierzone jest WYŁĄCZNIE 'search' — statystyki są pasywne (cache +
 * single-flight), więc nigdy nie są tu bramkowane.
 *
 * verify() zwraca tablicę:
 *   - ok          bool   — żądanie może przejść
 *   - blocked     bool   — IP tymczasowo zablokowane
 *   - retry_after ?int   — za ile sekund można spróbować ponownie
 *   - balance     ?float — pozostałe punkty
 *   - cost        ?float — koszt pobrany za tę akcję
 */
class RateLimiter
{
    public function __construct(
        protected PointsManager $points
    ) {}

    public function verify(ServerRequestInterface $request, string $scope): array
    {
        // Limiter wyłączony — nie ma czego egzekwować.
        if (!$this->points->isEnabled()) {
            return ['ok' => true];
        }

        // Mierzymy tylko akcję inicjowaną przez użytkownika ('search'); statystyki
        // są pasywne (cache + single-flight), więc ich nie bramkujemy.
        if ($scope !== 'search') {
            return ['ok' => true];
        }

        // Gość czy zalogowany? Wpływa tylko na KOSZT (goście płacą dopłatę) —
        // limitowani są wszyscy. Dopłata dla gości zastępuje dawne „pomiń zalogowanych".
        $isGuest = true;
        try {
            $actor = RequestUtil::getActor($request);
            if ($actor && $actor->exists && !$actor->isGuest()) {
                $isGuest = false;
            }
        } catch (\Throwable $e) {
            // W razie błędu zakładamy gościa (fail w stronę limitowania).
        }

        return $this->verifyPoints($request, $scope, $isGuest);
    }

    /**
     * Autorytatywna, SERWEROWA weryfikacja faktycznego żądania wyszukiwania
     * (GET /api/discussions z filtrem title/user) — wołana z {@see \TryHackX\
     * HomepageBlocks\Api\Middleware\SearchRateLimitMiddleware}.
     *
     * Jeśli IP ma świeży „grace" z pre-flightu (UI już naliczyło za tę akcję —
     * {@see CheckPointsController}), konsumujemy go i przepuszczamy BEZ podwójnego
     * naliczenia. W przeciwnym razie (bot/curl/scraper pomijający pre-flight)
     * naliczamy TU — to twarda bramka backendu chroniąca bazę przed zalewem
     * ciężkich zapytań LIKE. Zwraca tę samą strukturę co {@see verify()}.
     */
    public function verifyRequest(ServerRequestInterface $request): array
    {
        if (!$this->points->isEnabled()) {
            return ['ok' => true];
        }

        $ip = $this->points->getIp($request);

        // Pre-flight już naliczył za tę akcję → nie licz drugi raz.
        if ($this->points->consumeGrace($ip)) {
            return ['ok' => true];
        }

        return $this->verify($request, 'search');
    }

    /**
     * Po udanym pre-flighcie: przyznaj krótkotrwały grace, by następujące po nim
     * realne żądanie wyszukiwania nie zostało naliczone przez middleware drugi raz.
     */
    public function grantGrace(ServerRequestInterface $request): void
    {
        if (!$this->points->isEnabled()) {
            return;
        }

        $this->points->grantGrace($this->points->getIp($request));
    }

    /**
     * Logika punktowa z egzekwowaniem = tymczasowa blokada IP.
     */
    protected function verifyPoints(ServerRequestInterface $request, string $scope, bool $isGuest): array
    {
        $ip = $this->points->getIp($request);
        $cost = $this->points->getCost($scope, $isGuest);

        // 1) Czy IP jest już zablokowane?
        $remaining = $this->points->getBlockRemaining($ip);
        if ($remaining > 0) {
            return ['ok' => false, 'blocked' => true, 'retry_after' => $remaining, 'balance' => 0, 'cost' => $cost];
        }

        // 2) Spróbuj pobrać koszt.
        $charge = $this->points->charge($ip, $cost);
        if ($charge['ok']) {
            return ['ok' => true, 'balance' => $charge['balance'], 'cost' => $cost];
        }

        // 3) Punkty wyczerpane → zablokuj IP na czas z ustawień.
        $seconds = $this->points->block($ip, $this->points->getBlockSeconds());
        return ['ok' => false, 'blocked' => true, 'retry_after' => $seconds, 'balance' => 0, 'cost' => $cost];
    }
}
