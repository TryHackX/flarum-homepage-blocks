<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

/**
 * POST /api/tryhackx/homepage/whitelist/secret — admin-only. Body {secret: "..."} stores the tracker
 * API secret; {clear: true} removes it. The secret is deliberately NOT part of the regular settings
 * payload (it is stripped in the Deserializing listener) so a normal "Save" of unrelated settings can
 * never overwrite or wipe it, and the admin frontend never receives it.
 * A pasted full bearer token "key_id.secret" is accepted and split into both settings. The response
 * always echoes the stored key_id so the admin UI can refresh its field.
 */
class WhitelistSecretController implements RequestHandlerInterface
{
    public function __construct(protected SettingsRepositoryInterface $settings) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $body = (array) ($request->getParsedBody() ?? []);
        $key = 'tryhackx-homepage-blocks.whitelist_api_secret';
        if (!empty($body['clear'])) {
            $this->settings->set($key, '');
            return new JsonResponse(['ok' => true, 'set' => false]);
        }
        $secret = isset($body['secret']) && is_string($body['secret']) ? trim($body['secret']) : '';
        // Only a 64-hex secret or the composite 16-hex.64-hex token: anything else would get this
        // server's IP banned on the tracker for 30 days the moment it is used.
        if (preg_match(TrackerWhitelistClient::TOKEN_RE, $secret, $m)) {
            $this->settings->set('tryhackx-homepage-blocks.whitelist_api_key_id', strtolower($m[1]));
            $secret = $m[2];
        } elseif (!preg_match(TrackerWhitelistClient::SECRET_RE, $secret)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_secret'], 422);
        }
        $this->settings->set($key, strtolower($secret));
        return new JsonResponse(['ok' => true, 'set' => true, 'key_id' => (string) $this->settings->get('tryhackx-homepage-blocks.whitelist_api_key_id')]);
    }
}
