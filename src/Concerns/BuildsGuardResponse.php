<?php

namespace TryHackX\HomepageBlocks\Concerns;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Buduje znormalizowaną odpowiedź błędu bramki ochrony dla endpointów API.
 *
 * Kształty:
 *   - blokada IP  → HTTP 429 + nagłówek Retry-After, { error:'rate_limited',
 *                   blocked:true, retry_after, balance, cost }
 *   - captcha     → HTTP 403, { error:'captcha_required', captcha_required:true, ... }
 *   - klasyczne   → HTTP 403, { error:'reCAPTCHA verification failed' }
 */
trait BuildsGuardResponse
{
    /**
     * Zwraca odpowiedź błędu, albo null gdy żądanie może przejść (ok).
     */
    protected function guardError(array $result): ?ResponseInterface
    {
        if (!empty($result['ok'])) {
            return null;
        }

        if (!empty($result['blocked'])) {
            $retry = max(0, (int) ($result['retry_after'] ?? 0));
            $headers = $retry > 0 ? ['Retry-After' => (string) $retry] : [];

            return new JsonResponse([
                'error' => 'rate_limited',
                'blocked' => true,
                'retry_after' => $retry,
                'balance' => $result['balance'] ?? 0,
                'cost' => $result['cost'] ?? null,
            ], 429, $headers);
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
