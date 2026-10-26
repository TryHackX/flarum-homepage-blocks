<?php

namespace TryHackX\HomepageBlocks\Service;

use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Api\FieldLengthModifier;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

/**
 * Batched, resumable "scan the whole forum" for magnet links → tracker whitelist.
 *
 * Contract (see WhitelistScanController): one HTTP request = one batch. We iterate visible
 * comment posts by id (> cursor) and extract magnets.
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
    public const SCAN_BUDGET_SEC = 8.0;      // extraction share of the budget

    /** Memoised configured tracker hosts (see eligible()); the scanner is a per-request singleton. @var ?string[] */
    private ?array $trackerHosts = null;

    private ?bool $approvalColumn = null;

    public function __construct(
        private TrackerWhitelistClient $client,
        private UrlGenerator $url,
        private LoggerInterface $logger,
        private FieldLengthModifier $fieldLength,
    ) {
    }

    /** Visible comment posts (not hidden/private, discussion not hidden/private). */
    private function baseQuery()
    {
        $query = CommentPost::query()
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->where(function ($q) { $q->whereNull('posts.is_private')->orWhere('posts.is_private', 0); })
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->whereNull('discussions.hidden_at')
            ->where(function ($q) { $q->whereNull('discussions.is_private')->orWhere('discussions.is_private', 0); })
            ->select('posts.id', 'posts.number', 'posts.discussion_id', 'posts.user_id', 'posts.created_at', 'posts.edited_at', 'posts.content',
                'discussions.slug as d_slug', 'discussions.title as d_title', 'discussions.first_post_id as d_first_post_id');
        if ($this->approvalColumn ??= $query->getConnection()->getSchemaBuilder()->hasColumn('posts', 'is_approved')) {
            $query->where('posts.is_approved', true);
        }
        return $query;
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
     * @return array{ok:bool,next_cursor:int,done:bool,processed:int,hashes_found:int,skipped_no_tracker:int,sent:int,added:int,exists:int,banned:int,invalid:int,error:?string,elapsed_ms:int,last_post_id:int,describe:?array}
     */
    public function scanBatch(int $cursor, int $batch = self::BATCH_POSTS, bool $dryRun = false): array
    {
        $t0 = microtime(true);
        $batch = max(10, min(1000, $batch));
        $bare = $this->client->detectBareHashes();
        $describe = !$dryRun && $this->client->describeEnabled();
        $firstPosts = [];
        $processed = 0;
        $lastId = $cursor;
        $items = [];      // hash => item
        $skipped = [];    // hash => true — found, but not eligible (no magnet pointing at our tracker)
        $stopped = false; // stopped early (budget / cap) → not done even if fewer rows
        $maxId = $this->maxPostId();

        $rows = $this->baseQuery()->where('posts.id', '>', $cursor)->orderBy('posts.id')->limit($batch)->get();
        foreach ($rows as $post) {
            if ((microtime(true) - $t0) > self::SCAN_BUDGET_SEC || count($items) >= self::MAX_HASHES_PER_CALL) {
                $stopped = true;
                break;
            }
            $xml = (string) ($post->getRawOriginal('content') ?? $post->getAttributes()['content'] ?? '');
            [$found, $notEligible] = $this->eligible(MagnetExtractor::extract($xml, $bare));
            foreach ($notEligible as $hash => $_) {
                if (!isset($items[$hash])) $skipped[$hash] = true;
            }
            if ($found) {
                $ref = $this->refFor($post);
                foreach ($found as $hash => $meta) {
                    if (isset($items[$hash])) continue;
                    unset($skipped[$hash]);
                    $items[$hash] = $this->buildItem($hash, $meta, $ref);
                    if (count($items) >= self::MAX_HASHES_PER_CALL) break;
                }
                if ($describe && (int) $post->d_first_post_id === (int) $post->id) $firstPosts[(int) $post->id] = $found;
            }
            $processed++;
            $lastId = (int) $post->id;
        }
        $done = !$stopped && (count($rows) < $batch || $lastId >= $maxId);

        $out = ['ok' => true, 'next_cursor' => $lastId, 'done' => $done, 'processed' => $processed, 'hashes_found' => count($items),
                'skipped_no_tracker' => count($skipped),
                'sent' => 0, 'added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0, 'error' => null, 'elapsed_ms' => 0, 'last_post_id' => $lastId,
                'describe' => null];

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

        if ($describe) {
            $out['describe'] = DescriptionPayload::tally([]) + ['off' => $this->client->describePaused()];
            if ($firstPosts && !$out['describe']['off']) {
                $rows->filter(fn ($post) => isset($firstPosts[(int) $post->id]))->load('user');
                $maxChars = $this->fieldLength->contentMaxLength();
                $topics = [];
                foreach ($rows as $post) {
                    if (!isset($firstPosts[(int) $post->id])) continue;
                    array_push($topics, ...$this->topicsFor($post, array_keys(array_intersect_key($firstPosts[(int) $post->id], $items)), $maxChars));
                }
                $d = $this->describeTopics($topics, $maxChars, $t0);
                $out['describe'] = $d['counters'] + ['off' => $d['off']];
                if (!$d['ok'] && !$d['off']) {
                    $out['ok'] = false;
                    $out['next_cursor'] = $cursor;
                    $out['done'] = false;
                    $out['error'] = 'describe: ' . ($d['error'] ?? 'tracker_error');
                } elseif (!$d['off'] && $d['sent'] < count($topics)) {
                    $through = DescriptionPayload::sentThrough($topics, $d['sent'], $cursor);
                    $out['next_cursor'] = $through;
                    $out['last_post_id'] = $through;
                    $out['done'] = false;
                    $out['processed'] = $rows->take($processed)->filter(fn ($post) => (int) $post->id <= $through)->count();
                }
            }
        }
        $out['elapsed_ms'] = (int) ((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** Live path: extract + submit for one post. Returns the client result or null when nothing to send. */
    public function syncPost(CommentPost $post): ?array
    {
        $xml = (string) ($post->getRawOriginal('content') ?? $post->getAttributes()['content'] ?? '');
        if ($xml === '') return null;
        [$found] = $this->eligible(MagnetExtractor::extract($xml, $this->client->detectBareHashes()));
        if (!$found) return null;
        $ref = $this->refFor($post);
        $items = [];
        foreach ($found as $hash => $meta) $items[] = $this->buildItem($hash, $meta, $ref);
        return $this->client->submit($items, 'forum') + ['hashes' => array_keys($found)];
    }

    public function describePost(CommentPost $post, array $hashes): ?array
    {
        if (!$hashes || $this->client->describePaused()) return null;
        $maxChars = $this->fieldLength->contentMaxLength();
        return $this->describeTopics($this->topicsFor($post, $hashes, $maxChars), $maxChars);
    }

    private function describeTopics(array $topics, int $maxChars, ?float $t0 = null): array
    {
        $out = ['ok' => true, 'sent' => 0, 'off' => false, 'error' => null, 'status' => 0, 'counters' => DescriptionPayload::tally([])];
        $queue = DescriptionPayload::pack($topics, $maxChars);
        while ($queue) {
            $timeout = null;
            if ($t0 !== null) {
                $remaining = self::WALL_BUDGET_SEC - (microtime(true) - $t0);
                if ($remaining < 3 && DescriptionPayload::sentThrough($topics, $out['sent'], 0) > 0) break;
                $timeout = (int) max(3, min(15, floor($remaining)));
            }
            $call = array_shift($queue);
            $r = $this->client->describe($call, $maxChars, $timeout);
            if (!$r['ok'] && $r['status'] === 413) {
                if (count($call) > 1) {
                    $half = intdiv(count($call), 2);
                    array_unshift($queue, array_slice($call, 0, $half), array_slice($call, $half));
                    continue;
                }
                if ($call[0]['content'] !== null) {
                    $call[0]['content'] = null;
                    array_unshift($queue, $call);
                    continue;
                }
            }
            if (!$r['ok']) {
                $out['ok'] = false;
                $out['off'] = $r['error'] === 'describe_off';
                $out['error'] = $r['error'];
                $out['status'] = $r['status'];
                break;
            }
            $out['sent'] += count($call);
            $out['counters'] = DescriptionPayload::tally($r['json']['summary'] ?? [], $out['counters']);
        }
        return $out;
    }

    private function topicsFor($post, array $hashes, int $maxChars): array
    {
        $user = $post->user;
        return DescriptionPayload::topics(
            (int) $post->discussion_id,
            (int) $post->id,
            $this->topicUrl($post),
            $user ? ['id' => (string) $user->id, 'name' => (string) $user->display_name] : null,
            $post->edited_at ?? $post->created_at,
            $post->content,
            $maxChars,
            $hashes,
        );
    }

    private function topicUrl($post): ?string
    {
        $discussionId = (int) $post->discussion_id;
        $slug = $post->d_slug ?? ($post->discussion->slug ?? '');
        try {
            $forum = $this->url->to('forum');
            $url = $forum->route('discussion', ['id' => $discussionId . ($slug !== '' ? '-' . $slug : '')]);
            return strlen($url) <= DescriptionPayload::MAX_URL ? $url : $forum->route('discussion', ['id' => (string) $discussionId]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The ONE gate for "Only sync magnets that point at our tracker" — used by the live path
     * (syncPost) and the forum scan (scanBatch), so counters only ever see eligible hashes.
     * Off → everything passes. On → only entries whose magnet has a `tr=` on a configured host
     * (bare btih: / bare hex never qualify). On with an empty host list → nothing passes and the
     * misconfiguration is surfaced through the admin last-error line (once per request).
     *
     * @param  array<string, array{magnet: ?string, name: ?string}> $found
     * @return array{0: array<string, array{magnet: ?string, name: ?string}>, 1: array<string, array{magnet: ?string, name: ?string}>} [eligible, skipped]
     */
    private function eligible(array $found): array
    {
        if (!$this->client->requireTracker()) return [$found, []];
        $hosts = $this->trackerHosts ??= $this->client->trackerHosts();
        if (!$hosts) {
            $this->client->noteEmptyTrackerHosts();
            return [[], $found];
        }
        return $found ? MagnetExtractor::filterByTrackerHosts($found, $hosts) : [[], []];
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
