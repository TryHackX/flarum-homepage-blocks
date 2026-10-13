<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Meta\ExtractorConfig;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * Admin-only Advanced Filters index endpoints (one controller, three routes):
 *
 *   GET  /tryhackx/homepage/meta/status          → MetaIndexer::status()
 *   POST /tryhackx/homepage/meta/reindex         {cursor, only_stale?} → ONE batch
 *        200 {next_cursor, done, processed, elapsed_ms, status?(when done)}
 *        409 {error: 'already_running'} — another batch is running right now
 *   GET  /tryhackx/homepage/meta/preview?id=N    → what the parser reads from discussion N
 *        404 when there is no such discussion
 */
class MetaIndexController implements RequestHandlerInterface
{
    private const RUNNING_KEY = 'meta:reindex_running';

    public function __construct(
        protected MetaIndexer $indexer,
        protected Store $store,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return match ($request->getAttribute('routeName')) {
            'tryhackx.homepage.meta.reindex' => $this->reindex($request),
            'tryhackx.homepage.meta.preview' => $this->preview($request),
            // + the parser defaults: the admin shows them as placeholders of the label fields.
            default => new JsonResponse($this->indexer->status() + ['defaults' => ExtractorConfig::DEFAULTS]),
        };
    }

    private function reindex(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $cursor = max(0, (int) ($body['cursor'] ?? 0));
        $onlyStale = ! empty($body['only_stale']);

        // Single flight: a marker (so a second tab can't interleave) + the Store lock.
        $marker = $this->store->read(self::RUNNING_KEY);
        if ($marker && (int) ($marker['value']['until'] ?? 0) > time()) {
            return new JsonResponse(['error' => 'already_running'], 409);
        }
        $this->store->write(self::RUNNING_KEY, ['until' => time() + (int) MetaIndexer::BUDGET_SEC + 10], 60);
        try {
            $result = $this->store->withLock('meta:reindex', fn () => $this->indexer->indexBatch($cursor, MetaIndexer::BATCH, $onlyStale), false, null);
            if ($result === null) {
                return new JsonResponse(['error' => 'already_running'], 409);
            }
            if ($result['done']) {
                $result['status'] = $this->indexer->status();
            }

            return new JsonResponse($result);
        } finally {
            $this->store->write(self::RUNNING_KEY, ['until' => 0], 5);
        }
    }

    private function preview(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $preview = $id > 0 ? $this->indexer->preview($id) : null;

        return $preview === null
            ? new JsonResponse(['error' => 'not_found'], 404)
            : new JsonResponse($preview);
    }
}
