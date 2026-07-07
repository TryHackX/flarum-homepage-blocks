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
 * ⚠ MIĘKKA BRAMKA (świadoma decyzja audytu C1): to wyłącznie pre-flight po stronie
 * KLIENTA. Samo wyszukiwanie idzie potem w rdzeniowy /api/discussions, który NIE jest
 * bramkowany serwerowo — klient pomijający pre-flight (curl, bot, devtools) wykona je
 * bez naliczenia/limitu. Zaakceptowane: filtry title/user (LIKE) są ograniczone, a
 * twarda bramka wymagałaby dedykowanego middleware na /api/discussions z POJEDYNCZYM
 * punktem naliczania (inaczej pre-flight + middleware naliczyłyby podwójnie).
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

        return new JsonResponse([
            'ok' => true,
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
        ]);
    }
}
