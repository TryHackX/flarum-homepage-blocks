<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Security\PointsManager;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

/**
 * Lightweight pre-flight endpoint: charge points for an action without doing
 * the actual work. Used by the frontend before triggering native Flarum
 * search / discussion list refreshes (which the extension can't directly
 * gate at the PHP layer).
 *
 * Query: ?action=search
 *        ?action=search&recaptcha_token=...
 *
 * Response:
 *   200 { ok: true, balance: ...}
 *   403 { error: "captcha_required", balance: ... }        (needs captcha)
 *   403 { error: "reCAPTCHA verification failed" }         (classic mode, no/bad token)
 */
class CheckPointsController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? null;
        $allowed = ['random', 'stats', 'external_stats', 'search'];
        if (!$action || !in_array($action, $allowed, true)) {
            return new JsonResponse(['error' => 'Invalid action'], 400);
        }

        $points = new PointsManager($this->settings, $this->paths);
        $guard = new RecaptchaGuard($this->settings, $points);

        $result = $guard->verify($request, $action);

        if (!empty($result['ok'])) {
            return new JsonResponse([
                'ok' => true,
                'balance' => $result['balance'] ?? null,
                'cost' => $result['cost'] ?? null,
                'refilled' => $result['refilled'] ?? false,
            ]);
        }

        if (!empty($result['captcha_required'])) {
            return new JsonResponse([
                'error' => 'captcha_required',
                'captcha_required' => true,
                'balance' => $result['balance'] ?? 0,
                'cost' => $result['cost'] ?? null,
            ], 403);
        }

        return new JsonResponse(['error' => 'reCAPTCHA verification failed'], 403);
    }
}
