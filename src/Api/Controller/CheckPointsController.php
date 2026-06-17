<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

/**
 * Lekki endpoint pre-flight: nalicza punkty za akcję bez wykonywania jej.
 * Używany przez frontend przed natywnym wyszukiwaniem / odświeżeniem listy
 * dyskusji (których rozszerzenie nie bramkuje po stronie PHP).
 *
 * ⚠ MIĘKKA BRAMKA (świadoma decyzja audytu C1): dla zakresu 'search' to wyłącznie
 * pre-flight po stronie KLIENTA. Samo wyszukiwanie idzie potem w rdzeniowy
 * /api/discussions, który NIE jest bramkowany serwerowo — klient pomijający
 * pre-flight (curl, bot, devtools) wykona je bez naliczenia/limitu. Zaakceptowane:
 * filtry title/user (LIKE) są ograniczone, a twarda bramka wymagałaby dedykowanego
 * middleware na /api/discussions z POJEDYNCZYM punktem naliczania (inaczej pre-flight
 * + middleware naliczyłyby podwójnie) — do zrobienia, gdy ruch wzrośnie. Zakres
 * 'random' NIE jest miękki: RandomDiscussionController sam woła guard->verify('random').
 *
 * Query: ?action=search
 *        ?action=search&recaptcha_token=...
 *
 * Odpowiedź:
 *   200 { ok: true, balance: ... }
 *   403 { error: "captcha_required", balance: ... }   (wymagane captcha)
 *   429 { error: "rate_limited", retry_after: ... }   (blokada IP)
 *   403 { error: "reCAPTCHA verification failed" }     (tryb klasyczny, zły token)
 */
class CheckPointsController implements RequestHandlerInterface
{
    use BuildsGuardResponse;

    public function __construct(
        protected RecaptchaGuard $guard
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? null;
        $allowed = ['random', 'stats', 'external_stats', 'search'];
        if (!$action || !in_array($action, $allowed, true)) {
            return new JsonResponse(['error' => 'Invalid action'], 400);
        }

        $result = $this->guard->verify($request, $action);

        if ($error = $this->guardError($result)) {
            return $error;
        }

        return new JsonResponse([
            'ok' => true,
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
            'refilled' => $result['refilled'] ?? false,
        ]);
    }
}
