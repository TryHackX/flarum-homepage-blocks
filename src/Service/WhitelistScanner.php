<?php

namespace TryHackX\HomepageBlocks\Service;

use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Api\FieldLengthModifier;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

/**
 * Batched, resumable "scan the whole forum" for magnet links → tracker whitelist.
 *
 * Contract (see WhitelistScanController): one HTTP request = one batch. We iterate visible
 * comment posts by id (> cursor) and extract magnets.
 * The batch stops early when the wall budget is spent or MAX_HASHES_PER_CALL hashes are collected.
 */
final class WhitelistScanner
{
    public const BATCH_POSTS = 200;          // posts per HTTP batch
    public const DESCRIBE_BATCH_POSTS = 50;
    public const CHUNK_POSTS = 50;           // DB chunk inside a batch
    public const MAX_HASHES_PER_CALL = 500;  // tracker API cap (API_MAX_ITEMS)
    public const WALL_BUDGET_SEC = 20.0;     // hard per-request budget (php-fpm 30 s ceiling)
    public const SCAN_BUDGET_SEC = 8.0;      // extraction share of the budget
    public const BATCH_CALLS = 3;

    /** Memoised configured tracker hosts (see eligible()); the scanner is a per-request singleton. @var ?string[] */
    private ?array $trackerHosts = null;

    private ?User $viewer = null;

    private string $viewerAudience = '';

    public function __construct(
        private TrackerWhitelistClient $client,
        private UrlGenerator $url,
        private LoggerInterface $logger,
        private FieldLengthModifier $fieldLength,
        private SentTopics $sent,
        private TrackerQueue $queue,
    ) {
    }

    private function viewer(): User
    {
        $audience = $this->client->audience();
        if ($this->viewer !== null && $this->viewerAudience === $audience) return $this->viewer;
        $this->viewerAudience = $audience;
        if ($audience !== 'members') return $this->viewer = new Guest();
        $member = new User();
        $member->id = 0;
        $member->is_email_confirmed = true;
        $member->setRelation('groups', new Collection());
        return $this->viewer = $member;
    }

    private function visiblePosts()
    {
        return CommentPost::query()->where('posts.type', 'comment')->whereVisibleTo($this->viewer());
    }

    private function baseQuery()
    {
        return $this->visiblePosts()
            ->select('posts.id', 'posts.number', 'posts.discussion_id', 'posts.user_id', 'posts.created_at', 'posts.edited_at', 'posts.content');
    }

    private static function firstPostOnly($query)
    {
        return $query->whereExists(function ($sub) {
            $sub->selectRaw('1')->from('discussions')->whereColumn('discussions.first_post_id', 'posts.id');
        });
    }

    public function countPosts(): int
    {
        return (int) $this->visiblePosts()->count('posts.id');
    }

    public function maxPostId(): int
    {
        return (int) ($this->visiblePosts()->max('posts.id') ?? 0);
    }

    private function firstPostQuery()
    {
        return self::firstPostOnly($this->baseQuery());
    }

    public function countFirstPosts(): int
    {
        return (int) self::firstPostOnly($this->visiblePosts())->count('posts.id');
    }

    public function maxFirstPostId(): int
    {
        return (int) (self::firstPostOnly($this->visiblePosts())->max('posts.id') ?? 0);
    }

    public function scanBatch(int $cursor, int $batch = self::BATCH_POSTS, bool $dryRun = false): array
    {
        return $this->walk($cursor, $batch, $dryRun, false);
    }

    public function describeBatch(int $cursor, int $batch = self::DESCRIBE_BATCH_POSTS, bool $restore = false): array
    {
        return $this->walk($cursor, $batch, false, true, $restore);
    }

    private function walk(int $cursor, int $batch, bool $dryRun, bool $firstPostsOnly, bool $restore = false): array
    {
        $t0 = microtime(true);
        $batch = max(10, min(1000, $batch));
        $bare = $this->client->detectBareHashes();
        $describe = !$dryRun && $this->client->describeEnabled();
        $out = ['ok' => true, 'next_cursor' => $cursor, 'done' => false, 'processed' => 0, 'hashes_found' => 0, 'skipped_no_tracker' => 0,
                'sent' => 0, 'added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0, 'error' => null, 'elapsed_ms' => 0, 'last_post_id' => $cursor,
                'describe' => null, 'wait' => 0, 'queued' => 0];
        $finish = function (array $out) use ($t0): array {
            $out['elapsed_ms'] = (int) ((microtime(true) - $t0) * 1000);
            return $out;
        };

        if (!$dryRun && ($wait = $this->client->paceDelay(self::BATCH_CALLS)) > 0) {
            $out['wait'] = $wait;
            return $finish($out);
        }
        if ($describe && $this->client->limitsStale()) $this->client->ping(null, 6, null);
        $paused = $describe && $this->client->describePaused();
        if ($describe) $out['describe'] = DescriptionPayload::tally([]) + ['off' => $paused];
        if ($firstPostsOnly && $paused) return $finish($out);

        $candidates = $describe && !$paused ? $this->sent->after($cursor) : [];
        $cap = count($candidates) >= SentTopics::STALE_LIMIT ? (int) $candidates[count($candidates) - 1]['post_id'] : null;
        $maxId = $firstPostsOnly ? $this->maxFirstPostId() : $this->maxPostId();
        $query = ($firstPostsOnly ? $this->firstPostQuery() : $this->baseQuery())->where('posts.id', '>', $cursor);
        if ($cap !== null) $query->where('posts.id', '<=', $cap);
        $rows = $query->orderBy('posts.id')->limit($batch)->get();
        if ($rows->isNotEmpty()) $rows->load(['discussion' => fn ($q) => $q->select('id', 'slug', 'first_post_id', 'is_private')]);

        $items = [];
        $skipped = [];
        $firstPosts = [];
        $processed = 0;
        $lastId = $cursor;
        $stopped = false;
        foreach ($rows as $post) {
            if ((microtime(true) - $t0) > self::SCAN_BUDGET_SEC) {
                $stopped = true;
                break;
            }
            $xml = self::raw($post);
            [$found, $notEligible] = $this->eligible(MagnetExtractor::extract($xml, $bare));
            $new = array_diff_key($found, $items);
            if ($processed > 0 && count($items) + count($new) > self::MAX_HASHES_PER_CALL) {
                $stopped = true;
                break;
            }
            foreach ($notEligible as $hash => $_) {
                if (!isset($items[$hash])) $skipped[$hash] = true;
            }
            if ($new) {
                $ref = $this->refFor($post);
                foreach ($new as $hash => $meta) {
                    unset($skipped[$hash]);
                    $items[$hash] = $this->buildItem($hash, $meta, $ref);
                }
            }
            if ($describe && !$paused && self::isFirstPost($post)) $firstPosts[(int) $post->discussion_id] = [$post, array_keys($found), $xml];
            $processed++;
            $lastId = (int) $post->id;
        }
        $walkedAll = !$stopped && (count($rows) < $batch || $lastId >= $maxId);
        $through = $walkedAll ? ($cap ?? PHP_INT_MAX) : $lastId;
        $out['next_cursor'] = $walkedAll && $cap !== null ? max($lastId, $cap) : $lastId;
        $out['last_post_id'] = $out['next_cursor'];
        $out['done'] = $walkedAll && $cap === null;
        $out['processed'] = $processed;
        $out['hashes_found'] = count($items);
        $out['skipped_no_tracker'] = count($skipped);

        if ($items && !$dryRun) {
            $remaining = self::WALL_BUDGET_SEC - (microtime(true) - $t0);
            $r = $this->submitAll(array_values($items), (int) max(3, min(15, floor($remaining))));
            if (!$r['ok']) {
                $out = ['next_cursor' => $cursor, 'last_post_id' => $cursor, 'done' => false, 'processed' => 0, 'hashes_found' => 0, 'skipped_no_tracker' => 0] + $out;
                if (self::throttled($r)) {
                    $out['wait'] = max(1, (int) ($r['retry_after'] ?? 60));
                } else {
                    $out['ok'] = false;
                    $out['error'] = $r['error'] ?? 'tracker_error';
                }
                return $finish($out);
            }
            $out['sent'] = count($items);
            foreach (['added', 'exists', 'banned', 'invalid'] as $k) $out[$k] = (int) ($r['summary'][$k] ?? 0);
        }

        if (!$describe || $paused) return $finish($out);

        $candidates = array_values(array_filter($candidates, fn ($c) => $c['post_id'] <= $through));
        $limits = $this->client->describeLimits();
        $maxChars = $this->fieldLength->contentMaxLength();
        $contentLimit = min($maxChars, $limits['chars'] ?? $maxChars);
        $sentRows = $this->sent->many(array_keys($firstPosts));
        $firstPosts = array_filter($firstPosts, fn ($fp, $did) => $fp[1] || isset($sentRows[$did]), ARRAY_FILTER_USE_BOTH);
        $postIds = array_map(fn ($fp) => (int) $fp[0]->id, $firstPosts);
        $rows->filter(fn ($post) => in_array((int) $post->id, $postIds, true))->load('user');

        $plans = [];
        foreach ($firstPosts as $did => [$post, $found, $xml]) {
            $plans[] = $this->plan($did, $post, true, false, $sentRows[$did] ?? null, array_keys(MagnetExtractor::extract($xml, true)), $found, $contentLimit, $limits, (int) $post->id);
        }
        foreach ($this->staleOf($candidates, $firstPosts) as [$row, $discussion]) {
            $plans[] = $this->stalePlan($row, $discussion, $bare, $contentLimit, $limits);
        }
        $plans = array_values(array_filter($plans, fn ($plan) => $plan['topics']));
        usort($plans, fn ($a, $b) => [$a['position'], $a['discussion_id']] <=> [$b['position'], $b['discussion_id']]);
        $topics = [];
        $positions = [];
        $owners = [];
        foreach ($plans as $i => $plan) {
            foreach ($plan['topics'] as $topic) {
                $topics[] = $restore && !isset($topic['visible']) ? $topic + ['restore' => true] : $topic;
                $positions[] = $plan['position'];
                $owners[] = $i;
            }
        }
        if (!$topics) return $finish($out);

        $d = $this->describeTopics($topics, $maxChars, $t0);
        $out['describe'] = $d['counters'] + ['off' => $d['off']];
        $total = array_count_values($owners);
        $handled = array_count_values(array_slice($owners, 0, $d['sent']));
        foreach ($plans as $i => $plan) {
            if (($handled[$i] ?? 0) < $total[$i]) continue;
            $did = $plan['discussion_id'];
            if (isset($d['failed'][$did])) {
                $this->queue->defer($did, 'describe: ' . $d['failed'][$did]);
                $out['queued']++;
            } elseif ($this->record($plan, $d['results'])) {
                $this->queue->defer($did, 'describe: error');
                $out['queued']++;
            }
        }
        if ($d['sent'] < count($topics) && (!$d['off'] || $firstPostsOnly)) {
            $through = DescriptionPayload::sentThrough($positions, $d['sent'], $cursor);
            $out['next_cursor'] = $through;
            $out['last_post_id'] = $through;
            $out['done'] = false;
            $out['processed'] = $rows->take($processed)->filter(fn ($post) => (int) $post->id <= $through)->count();
        }
        if ($d['throttled']) $out['wait'] = max(1, (int) ($d['retry_after'] ?? 60));
        return $finish($out);
    }

    public function syncDiscussion(int $discussionId, string $reason, ?int $postId): array
    {
        $discussion = Discussion::query()->find($discussionId);
        if ($discussion && $reason !== TrackerQueue::DESCRIBE) {
            $items = $this->magnetItems($discussion, $reason === TrackerQueue::POST ? $postId : null);
            if ($items) {
                $r = $this->submitAll($items);
                if (!$r['ok']) return self::throttled($r)
                    ? ['ok' => false, 'throttled' => true, 'retry_after' => (int) ($r['retry_after'] ?? 60), 'magnets_done' => false]
                    : ['ok' => false, 'error' => (string) ($r['error'] ?? 'tracker_error'), 'status' => (int) ($r['status'] ?? 0), 'magnets_done' => false];
            }
        }
        if (!$this->client->describeEnabled()) return ['ok' => true];
        $until = $this->client->describePausedUntil();
        if ($until !== null) return ['ok' => false, 'paused' => true, 'until' => $until, 'magnets_done' => true];

        $first = $this->firstPost($discussion);
        $visible = $first !== null && $this->visiblePosts()->whereKey($first->id)->exists();
        $private = $discussion !== null && (!empty($discussion->is_private) || ($first !== null && !empty($first->is_private)));
        $raw = $first !== null ? self::raw($first) : '';
        $present = $first !== null ? array_keys(MagnetExtractor::extract($raw, true)) : [];
        $eligible = $first !== null ? array_keys($this->eligible(MagnetExtractor::extract($raw, $this->client->detectBareHashes()))[0]) : [];
        $sent = $this->sent->get($discussionId);
        $limits = $this->client->describeLimits();
        $maxChars = $this->fieldLength->contentMaxLength();
        $plan = $this->plan($discussionId, $first, $visible, $private, $sent, $present, $eligible, min($maxChars, $limits['chars'] ?? $maxChars), $limits, 0);
        if (!$plan['topics'] || (!$plan['hidden'] && $sent !== null && $sent['sha'] === $plan['sha'])) return ['ok' => true];

        $d = $this->describeTopics($plan['topics'], $maxChars);
        if ($d['off']) return ['ok' => false, 'paused' => true, 'until' => $this->client->describePausedUntil() ?? time() + 600, 'magnets_done' => true];
        if ($d['throttled']) return ['ok' => false, 'throttled' => true, 'retry_after' => (int) ($d['retry_after'] ?? 60), 'magnets_done' => true];
        if (isset($d['failed'][$discussionId])) return ['ok' => false, 'error' => 'describe: ' . $d['failed'][$discussionId], 'status' => $d['status'], 'magnets_done' => true];
        if ($this->record($plan, $d['results'])) return ['ok' => false, 'error' => 'describe: error', 'magnets_done' => true];
        return ['ok' => true];
    }

    public function rememberFirstPost(int $discussionId, ?CommentPost $post, bool $private): void
    {
        if ($post === null || $private || !$this->client->describeEnabled()) return;
        [$found] = $this->eligible(MagnetExtractor::extract(self::raw($post), $this->client->detectBareHashes()));
        if ($found) $this->sent->remember($discussionId, (int) $post->id, array_keys($found));
    }

    private function plan(int $discussionId, ?CommentPost $first, bool $visible, bool $private, ?array $sent, array $present, array $eligible, int $contentLimit, array $limits, int $position): array
    {
        $p = DescriptionPayload::plan($sent, $visible, $private, $present, $eligible);
        $postId = $first !== null ? (int) $first->id : (int) ($sent['post_id'] ?? 0);
        $withdraw = $limits['withdraw'] === true && $postId > 0;
        $topics = $p['describe'] && $first !== null ? $this->topicsFor($first, $p['describe'], $contentLimit, $limits['hashes']) : [];
        if ($withdraw && $p['withdraw']) array_push($topics, ...DescriptionPayload::hidden($discussionId, $postId, $p['withdraw'], false, $limits['hashes']));
        if ($withdraw && $p['gone']) array_push($topics, ...DescriptionPayload::hidden($discussionId, $postId, $p['gone'], true, $limits['hashes']));
        return [
            'discussion_id' => $discussionId,
            'post_id' => $postId,
            'position' => $position,
            'visible' => $visible,
            'sent' => $sent,
            'topics' => $topics,
            'hidden' => $withdraw && ($p['withdraw'] || $p['gone']),
            'keep' => $withdraw ? $p['keep'] : array_values(array_unique(array_merge($sent['hashes'] ?? [], $p['describe']))),
            'sha' => DescriptionPayload::fingerprint($topics),
        ];
    }

    private function staleOf(array $candidates, array $planned): array
    {
        $candidates = array_values(array_filter($candidates, fn ($c) => !isset($planned[$c['discussion_id']])));
        if (!$candidates) return [];
        $ids = array_column($candidates, 'discussion_id');
        $discussions = Discussion::query()->whereIn('id', $ids)->get()->keyBy('id');
        $visible = array_flip(array_map('intval', self::firstPostOnly($this->visiblePosts())->whereIn('posts.discussion_id', $ids)->pluck('posts.discussion_id')->all()));
        $out = [];
        foreach ($candidates as $row) {
            $discussion = $discussions->get($row['discussion_id']);
            if ($discussion !== null && (!$row['visible'] || isset($visible[$row['discussion_id']]))) continue;
            $out[] = [$row, $discussion];
        }
        return $out;
    }

    private function stalePlan(array $row, ?Discussion $discussion, bool $bare, int $contentLimit, array $limits): array
    {
        $first = $this->firstPost($discussion);
        $private = $discussion !== null && (!empty($discussion->is_private) || ($first !== null && !empty($first->is_private)));
        $raw = $first !== null ? self::raw($first) : '';
        $present = $first !== null ? array_keys(MagnetExtractor::extract($raw, true)) : [];
        $eligible = $first !== null ? array_keys($this->eligible(MagnetExtractor::extract($raw, $bare))[0]) : [];
        return $this->plan($row['discussion_id'], $first, false, $private, $row, $present, $eligible, $contentLimit, $limits, $row['post_id']);
    }

    private function record(array $plan, array $results): bool
    {
        $did = $plan['discussion_id'];
        $mine = array_values(array_filter($results, fn ($r) => $r['discussion_id'] === $did));
        if (!$mine) {
            foreach ($plan['topics'] as $topic) {
                foreach ($topic['hashes'] as $hash) {
                    $hidden = isset($topic['visible']);
                    $mine[] = ['discussion_id' => $did, 'hash' => $hash, 'status' => $hidden ? (empty($topic['gone']) ? 'withdrawn' : 'released') : 'unchanged', 'detail' => null, 'hidden' => $hidden];
                }
            }
        }
        $sent = $plan['sent'];
        $postId = $plan['post_id'] > 0 ? $plan['post_id'] : (int) ($sent['post_id'] ?? 0);
        if ($postId < 1) return false;
        [$state, $status, $detail] = DescriptionPayload::topicState($mine, $sent['state'] ?? null);
        if (array_filter($mine, fn ($r) => $r['status'] === 'error')) {
            $this->sent->record($did, $postId, array_values(array_unique(array_merge($sent['hashes'] ?? [], $plan['keep']))), $sent['visible'] ?? true, $sent['sha'] ?? null, $state, $status, $detail);
            return true;
        }
        $this->sent->record($did, $postId, $plan['keep'], $plan['visible'], $plan['sha'], $state, $status, $detail);
        return false;
    }

    private function magnetItems(Discussion $discussion, ?int $postId): array
    {
        $query = $this->visiblePosts()->where('posts.discussion_id', $discussion->id)->select('posts.id', 'posts.number', 'posts.discussion_id', 'posts.content');
        if ($postId !== null) $query->whereKey($postId);
        $bare = $this->client->detectBareHashes();
        $items = [];
        $query->chunkById(self::CHUNK_POSTS, function ($posts) use (&$items, $discussion, $bare) {
            foreach ($posts as $post) {
                [$found] = $this->eligible(MagnetExtractor::extract(self::raw($post), $bare));
                if (!$found) continue;
                $post->setRelation('discussion', $discussion);
                $ref = $this->refFor($post);
                foreach ($found as $hash => $meta) $items[$hash] ??= $this->buildItem($hash, $meta, $ref);
            }
        }, 'posts.id', 'id');
        return array_values($items);
    }

    private function submitAll(array $items, ?int $timeout = null): array
    {
        $summary = [];
        foreach (array_chunk($items, self::MAX_HASHES_PER_CALL) as $chunk) {
            $r = $this->client->submit($chunk, 'forum', $timeout);
            if (!$r['ok']) return $r + ['summary' => $summary];
            foreach ((array) ($r['json']['summary'] ?? []) as $k => $v) $summary[$k] = ($summary[$k] ?? 0) + (int) $v;
        }
        return ['ok' => true, 'summary' => $summary];
    }

    private static function throttled(array $r): bool
    {
        return ($r['error'] ?? null) === 'paced' || (int) ($r['status'] ?? 0) === 429;
    }

    private function firstPost(?Discussion $discussion): ?CommentPost
    {
        $id = (int) ($discussion?->first_post_id ?? 0);
        if ($id < 1) return null;
        $post = CommentPost::query()->where('posts.type', 'comment')->whereKey($id)->first();
        $post?->setRelation('discussion', $discussion);
        return $post;
    }

    private static function isFirstPost($post): bool
    {
        return (int) ($post->discussion?->first_post_id ?? 0) === (int) $post->id;
    }

    private static function raw($post): string
    {
        return (string) ($post->getRawOriginal('content') ?? $post->getAttributes()['content'] ?? '');
    }

    private function describeTopics(array $topics, int $maxChars, ?float $t0 = null): array
    {
        $limits = $this->client->describeLimits();
        $out = ['ok' => true, 'sent' => 0, 'off' => false, 'throttled' => false, 'retry_after' => null, 'error' => null, 'status' => 0,
                'counters' => DescriptionPayload::tally([]), 'results' => [], 'failed' => []];
        $copyImages = $this->client->copyImages();
        $queue = DescriptionPayload::pack($topics, $maxChars, $copyImages, $limits['topics'], $limits['bytes']);
        while ($queue) {
            $timeout = null;
            if ($t0 !== null) {
                $remaining = self::WALL_BUDGET_SEC - (microtime(true) - $t0);
                if ($remaining < 3 && DescriptionPayload::sentThrough($topics, $out['sent'], 0) > 0) break;
                $timeout = (int) max(3, min(TrackerWhitelistClient::DESCRIBE_TIMEOUT, floor($remaining)));
            }
            $call = array_shift($queue);
            $r = $this->client->describe($call, $maxChars, $timeout);
            if (!$r['ok'] && $r['status'] === 413) {
                if (count($call) > 1) {
                    $half = intdiv(count($call), 2);
                    array_unshift($queue, array_slice($call, 0, $half), array_slice($call, $half));
                    continue;
                }
                $bytes = strlen((string) json_encode(DescriptionPayload::body($call, $maxChars, $copyImages), TrackerWhitelistClient::JSON_FLAGS));
                foreach ($call[0]['hashes'] as $hash) {
                    $out['results'][] = ['discussion_id' => (int) $call[0]['discussion_id'], 'hash' => $hash, 'status' => 'too_big', 'detail' => (string) $bytes, 'hidden' => isset($call[0]['visible'])];
                }
                $out['counters'] = DescriptionPayload::tally(['too_big' => count($call[0]['hashes'])], $out['counters']);
                $out['sent']++;
                continue;
            }
            if (!$r['ok']) {
                $error = (string) ($r['error'] ?? 'tracker_error');
                $out['ok'] = false;
                $out['error'] = $error;
                $out['status'] = (int) $r['status'];
                if ($error === 'describe_off') {
                    $out['off'] = true;
                    break;
                }
                if (self::throttled($r)) {
                    $out['throttled'] = true;
                    $out['retry_after'] = (int) ($r['retry_after'] ?? 60);
                    break;
                }
                $calls = $r['status'] >= 400 && $r['status'] < 500 ? [$call] : array_merge([$call], $queue);
                foreach ($calls as $failed) {
                    foreach ($failed as $topic) {
                        $out['failed'][(int) $topic['discussion_id']] = $error;
                        $out['counters'] = DescriptionPayload::tally(['error' => count($topic['hashes'])], $out['counters']);
                        $out['sent']++;
                    }
                }
                if (count($calls) > 1) break;
                continue;
            }
            $out['sent'] += count($call);
            $out['counters'] = DescriptionPayload::tally((array) ($r['json']['summary'] ?? []), $out['counters']);
            foreach ((array) ($r['json']['results'] ?? []) as $result) {
                $i = is_array($result) ? ($result['topic'] ?? null) : null;
                if (!is_int($i) || !isset($call[$i])) continue;
                $out['results'][] = [
                    'discussion_id' => (int) $call[$i]['discussion_id'],
                    'hash' => is_string($result['hash'] ?? null) ? $result['hash'] : null,
                    'status' => (string) ($result['status'] ?? ''),
                    'detail' => is_scalar($result['detail'] ?? null) ? (string) $result['detail'] : null,
                    'hidden' => isset($call[$i]['visible']),
                ];
            }
        }
        return $out;
    }

    private function topicsFor($post, array $hashes, int $maxChars, int $maxHashes = DescriptionPayload::MAX_HASHES): array
    {
        $user = $post->user;
        $audience = $this->client->audience();
        return array_map(fn (array $topic) => $topic + ['audience' => $audience], DescriptionPayload::topics(
            (int) $post->discussion_id,
            (int) $post->id,
            $this->topicUrl($post),
            $user ? ['id' => (string) $user->id, 'name' => (string) $user->display_name] : null,
            $post->edited_at ?? $post->created_at,
            $post->content,
            $maxChars,
            $hashes,
            $maxHashes,
        ));
    }

    private function topicUrl($post): ?string
    {
        $discussionId = (int) $post->discussion_id;
        $slug = (string) ($post->discussion->slug ?? '');
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
     * and the forum scan (scanBatch), so counters only ever see eligible hashes.
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
        $slug = (string) ($post->discussion->slug ?? '');
        $ref = ['post_id' => (int) $post->id, 'discussion_id' => $discussionId];
        if (self::isFirstPost($post)) $ref['first'] = true;
        try {
            $ref['url'] = $this->url->to('forum')->route('discussion', ['id' => $discussionId . ($slug !== '' ? '-' . $slug : ''), 'near' => (int) $post->number]);
        } catch (\Throwable $e) {
            // URL is optional
        }
        return $ref;
    }
}
