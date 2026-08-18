<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

/**
 * POST /api/tryhackx/homepage/whitelist/test — admin-only "Test connection".
 * Body may carry unsaved overrides {api_url, api_key_id, api_secret}; empty/missing fields fall
 * back to the saved settings (so the button works with a stored secret the UI never sees).
 * Never logs or echoes the credentials.
 */
class WhitelistTestController implements RequestHandlerInterface
{
    public function __construct(protected TrackerWhitelistClient $client) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $body = (array) ($request->getParsedBody() ?? []);
        $override = [];
        foreach (['api_url', 'api_key_id', 'api_secret'] as $k) {
            $v = isset($body[$k]) && is_string($body[$k]) ? trim($body[$k]) : '';
            if ($v !== '') $override[$k] = $v;
        }
        if (isset($override['api_url']) && TrackerWhitelistClient::validateUrl($override['api_url']) === null) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_url'], 200);
        }
        $r = $this->client->ping($override ?: null);
        $json = $r['json'] ?? [];
        $serverTime = isset($json['server_time']) ? (int) $json['server_time'] : null;
        return new JsonResponse([
            'ok' => (bool) $r['ok'],
            'http_status' => (int) $r['status'],
            'error' => $r['error'],
            'server_time' => $serverTime,
            'skew_seconds' => $serverTime !== null ? $serverTime - time() : null,
            'mode' => $json['mode'] ?? null,
            'whitelist_count' => isset($json['whitelist_count']) ? (int) $json['whitelist_count'] : null,
            'client' => $json['client'] ?? null,
            'elapsed_ms' => (int) $r['elapsed_ms'],
            'last_error' => $this->client->lastError(),
        ]);
    }
}
