<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Security\RateLimiter;

/**
 * Lekki endpoint pre-flight: nalicza punkty za akcję bez wykonywania jej.
 * Używany przez frontend przed natywnym wyszukiwaniem / odświeżeniem listy
 * dyskusji (których rozszerzenie nie bramkuje po stronie PHP).
 *
 * Rola: to WARSTWA UX (szybka informacja dla przeglądarki + odliczanie blokady zanim
 * poleci realne zapytanie). Twardą, serwerową bramkę stanowi teraz {@see \TryHackX\
 * HomepageBlocks\Api\Middleware\SearchRateLimitMiddleware} na rdzeniowym /api/discussions
 * (łapie także boty/curl pomijające ten pre-flight). Żeby NIE naliczyć podwójnie, po
 * udanym naliczeniu przyznajemy „grace" ({@see RateLimiter::grantGrace()}) — middleware
 * skonsumuje go zamiast naliczać ponownie realne żądanie tego samego wyszukiwania.
 *
 * Query: ?action=search
 *
 * Odpowiedź:
 *   200 { ok: true, balance: ... }
 *   429 { error: "rate_limited", retry_after: ... }   (blokada IP)
 */
class CheckPointsController implements RequestHandlerInterface
{
    use BuildsGuardResponse;

    public function __construct(
        protected RateLimiter $limiter
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? null;
        if ($action !== 'search') {
            return new JsonResponse(['error' => 'Invalid action'], 400);
        }

        $result = $this->limiter->verify($request, $action);

        if ($error = $this->guardError($result)) {
            return $error;
        }

        // Naliczono w pre-flighcie → przyznaj grace, by SearchRateLimitMiddleware
        // przepuścił następujące po nim realne żądanie /api/discussions bez podwójnego
        // naliczenia (patrz docblock klasy).
        $this->limiter->grantGrace($request);

        return new JsonResponse([
            'ok' => true,
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
        ]);
    }
}
