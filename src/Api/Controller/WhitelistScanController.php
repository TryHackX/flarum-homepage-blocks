<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\WhitelistScanner;

/**
 * POST /api/tryhackx/homepage/whitelist/scan — admin-only, ONE batch per call (see WhitelistScanner).
 * Body: {cursor: int, batch?: int, dry_run?: bool}
 * 200 {ok, next_cursor, done, processed, hashes_found, sent, added, exists, banned, invalid, error,
 *      elapsed_ms, total_posts (cursor==0 only), max_post_id, last_error}
 * 409 {error:'already_running'}       — another scan batch is executing right now
 * 503 {error:'cooldown', retry_after} — the client is backing off after a tracker failure
 * 422 {error:'not_configured'}
 * On a tracker failure the batch answers 200 with ok:false and next_cursor == cursor (nothing skipped);
 * the UI stops and offers Resume.
 */
class WhitelistScanController implements RequestHandlerInterface
{
    private const RUNNING_KEY = 'whitelist:scan_running';

    public function __construct(
        protected WhitelistScanner $scanner,
        protected TrackerWhitelistClient $client,
        protected Store $store,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        if (!$this->client->isConfigured()) {
            return new JsonResponse(['error' => 'not_configured'], 422);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $cursor = max(0, (int) ($body['cursor'] ?? 0));
        $batch = isset($body['batch']) ? (int) $body['batch'] : WhitelistScanner::BATCH_POSTS;
        $dryRun = !empty($body['dry_run']);

        if ($this->client->inCooldown() && !$dryRun) {
            return new JsonResponse(['error' => 'cooldown', 'retry_after' => 60, 'last_error' => $this->client->lastError()], 503);
        }
        // Best-effort single-flight: a marker (60 s) so a second admin tab cannot start an overlapping
        // batch, plus the Store lock for the duration of this batch.
        $marker = $this->store->read(self::RUNNING_KEY);
        if ($marker && !empty($marker['value']['until']) && (int) $marker['value']['until'] > time()) {
            return new JsonResponse(['error' => 'already_running'], 409);
        }
        $this->store->write(self::RUNNING_KEY, ['until' => time() + 40], 60);
        try {
            $result = $this->store->withLock('whitelist:scan', fn () => $this->scanner->scanBatch($cursor, $batch, $dryRun), false, null);
            if ($result === null) {
                return new JsonResponse(['error' => 'already_running'], 409);
            }
            if ($cursor === 0) {
                $result['total_posts'] = $this->scanner->countPosts();
            }
            $result['max_post_id'] = $this->scanner->maxPostId();
            $result['last_error'] = $this->client->lastError();
            return new JsonResponse($result, 200);
        } finally {
            $this->store->write(self::RUNNING_KEY, ['until' => 0], 5);
        }
    }
}
