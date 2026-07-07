<?php

namespace TryHackX\HomepageBlocks\Concerns;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Buduje znormalizowaną odpowiedź błędu limitera dla endpointów API.
 *
 * Jedyny wynik odmowy: tymczasowa blokada IP →
 *   HTTP 429 + nagłówek Retry-After, { error:'rate_limited', blocked:true,
 *   retry_after, balance, cost }
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

        // Jedyny tryb egzekwowania: tymczasowa blokada IP (rate limited).
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
}
