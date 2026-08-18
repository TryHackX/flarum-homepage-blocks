<?php

namespace TryHackX\HomepageBlocks\Service;

use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

/**
 * Batched, resumable "scan the whole forum" for magnet links → tracker whitelist.
 *
 * Contract (see WhitelistScanController): one HTTP request = one batch. We iterate visible
 * comment posts by id (> cursor), extract magnets, and make EXACTLY ONE tracker call per batch.
 * The batch stops early when the wall budget is spent or MAX_HASHES_PER_CALL hashes are collected.
 * If the tracker call fails, `next_cursor` is NOT advanced (nothing is silently skipped) and the
 * caller can retry ("Resume") — the tracker endpoint is idempotent.
 */
final class WhitelistScanner
{
    public const BATCH_POSTS = 200;          // posts per HTTP batch
    public const CHUNK_POSTS = 50;           // DB chunk inside a batch
    public const MAX_HASHES_PER_CALL = 500;  // tracker API cap (API_MAX_ITEMS)
    public const WALL_BUDGET_SEC = 20.0;     // hard per-request budget (php-fpm 30 s ceiling)
    public const SCAN_BUDGET_SEC = 8.0;      // extraction share of the budget; the rest is the tracker call

    public function __construct(
        private TrackerWhitelistClient $client,
        private UrlGenerator $url,
        private LoggerInterface $logger,
    ) {
    }

    /** Visible comment posts (not hidden/private, discussion not hidden/private). */
    private function baseQuery()
    {
        return CommentPost::query()
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->where(function ($q) { $q->whereNull('posts.is_private')->orWhere('posts.is_private', 0); })
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->whereNull('discussions.hidden_at')
            ->where(function ($q) { $q->whereNull('discussions.is_private')->orWhere('discussions.is_private', 0); })
            ->select('posts.id', 'posts.number', 'posts.discussion_id', 'posts.content', 'discussions.slug as d_slug', 'discussions.title as d_title');
    }

    public function countPosts(): int
    {
        return (int) $this->baseQuery()->count('posts.id');
    }

    public function maxPostId(): int
    {
        return (int) ($this->baseQuery()->max('posts.id') ?? 0);
    }

    /**
     * @return array{ok:bool,next_cursor:int,done:bool,processed:int,hashes_found:int,sent:int,added:int,exists:int,banned:int,invalid:int,error:?string,elapsed_ms:int,last_post_id:int}
     */
    public function scanBatch(int $cursor, int $batch = self::BATCH_POSTS, bool $dryRun = false): array
    {
        $t0 = microtime(true);
        $batch = max(10, min(1000, $batch));
        $bare = $this->client->detectBareHashes();
        $processed = 0;
        $lastId = $cursor;
        $items = [];      // hash => item
        $stopped = false; // stopped early (budget / cap) → not done even if fewer rows
        $maxId = $this->maxPostId();

        $rows = $this->baseQuery()->where('posts.id', '>', $cursor)->orderBy('posts.id')->limit($batch)->get();
        foreach ($rows as $post) {
            if ((microtime(true) - $t0) > self::SCAN_BUDGET_SEC || count($items) >= self::MAX_HASHES_PER_CALL) {
                $stopped = true;
                break;
            }
            $xml = (string) ($post->getRawOriginal('content') ?? $post->getAttributes()['content'] ?? '');
            $found = MagnetExtractor::extract($xml, $bare);
            if ($found) {
                $ref = $this->refFor($post);
                foreach ($found as $hash => $meta) {
                    if (isset($items[$hash])) continue;
                    $items[$hash] = $this->buildItem($hash, $meta, $ref);
                    if (count($items) >= self::MAX_HASHES_PER_CALL) break;
                }
            }
            $processed++;
            $lastId = (int) $post->id;
        }
        $done = !$stopped && (count($rows) < $batch || $lastId >= $maxId);

        $out = ['ok' => true, 'next_cursor' => $lastId, 'done' => $done, 'processed' => $processed, 'hashes_found' => count($items),
                'sent' => 0, 'added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0, 'error' => null, 'elapsed_ms' => 0, 'last_post_id' => $lastId];

        if ($items && !$dryRun) {
            $remaining = self::WALL_BUDGET_SEC - (microtime(true) - $t0);
            $timeout = (int) max(3, min(15, floor($remaining)));
            $r = $this->client->submit(array_values($items), 'forum', $timeout);
            if (!$r['ok']) {
                // do NOT advance the cursor: the caller retries this batch
                $out['ok'] = false;
                $out['next_cursor'] = $cursor;
                $out['done'] = false;
                $out['error'] = $r['error'] ?? 'tracker_error';
                $out['elapsed_ms'] = (int) ((microtime(true) - $t0) * 1000);
                return $out;
            }
            $out['sent'] = count($items);
            $s = $r['json']['summary'] ?? [];
            foreach (['added', 'exists', 'banned', 'invalid'] as $k) $out[$k] = (int) ($s[$k] ?? 0);
        }
        $out['elapsed_ms'] = (int) ((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** Live path: extract + submit for one post. Returns the client result or null when nothing to send. */
    public function syncPost(CommentPost $post): ?array
    {
        $xml = (string) ($post->getRawOriginal('content') ?? $post->getAttributes()['content'] ?? '');
        if ($xml === '') return null;
        $found = MagnetExtractor::extract($xml, $this->client->detectBareHashes());
        if (!$found) return null;
        $ref = $this->refFor($post);
        $items = [];
        foreach ($found as $hash => $meta) $items[] = $this->buildItem($hash, $meta, $ref);
        return $this->client->submit($items, 'forum');
    }

    private function buildItem(string $hash, array $meta, array $ref): array
    {
        $item = ['ref' => $ref];
        if (!empty($meta['magnet'])) {
            $item['magnet'] = mb_substr($meta['magnet'], 0, 2000);
        } else {
            $item['hash'] = $hash;
        }
        if (!empty($meta['name'])) $item['name'] = $meta['name'];
        return $item;
    }

    private function refFor($post): array
    {
        $discussionId = (int) $post->discussion_id;
        $slug = $post->d_slug ?? ($post->discussion->slug ?? '');
        $ref = ['post_id' => (int) $post->id, 'discussion_id' => $discussionId];
        try {
            $ref['url'] = $this->url->to('forum')->route('discussion', ['id' => $discussionId . ($slug !== '' ? '-' . $slug : ''), 'near' => (int) $post->number]);
        } catch (\Throwable $e) {
            // URL is optional
        }
        return $ref;
    }
}
