<?php

namespace TryHackX\HomepageBlocks\Service;

use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

final class DescriptionPayload
{
    public const MAX_HASHES = 20;
    public const MAX_TOPICS = 25;
    public const MAX_BYTES = 3500000;
    public const MAX_URL = 500;
    public const MAX_TAGS = 30;
    public const COUNTERS = [
        'published' => ['created', 'updated', 'replaced'],
        'waiting' => ['queued'],
        'proposed' => ['proposed'],
        'unchanged' => ['unchanged'],
        'kept' => ['kept'],
        'withdrawn' => ['withdrawn', 'released'],
        'too_long' => ['too_long', 'too_big'],
        'invalid' => ['invalid'],
        'declined' => ['declined'],
        'skipped' => ['skipped'],
        'errors' => ['error'],
    ];
    public const SKIPPED = ['not_registered', 'registered_elsewhere', 'banned', 'other_topic', 'removed', 'declined', 'stale'];
    public const FAILED = ['error', 'invalid', 'too_long', 'too_big'];
    public const STATES = ['published', 'waiting', 'proposed', 'unchanged', 'withdrawn', 'declined', 'error'];
    public const DECLINED = ['declined', 'removed'];

    public static function topics(int $discussionId, int $postId, ?string $url, ?array $author, ?\DateTimeInterface $editedAt, ?string $content, int $maxChars, array $hashes, int $maxHashes = self::MAX_HASHES, ?array $tags = null): array
    {
        $content = (string) $content;
        $chars = mb_strlen($content);
        $topic = ['discussion_id' => $discussionId, 'post_id' => $postId];
        if ($url !== null && $url !== '' && strlen($url) <= self::MAX_URL) $topic['url'] = $url;
        $topic['author'] = $author;
        $topic['edited_at'] = $editedAt?->format(DATE_ATOM);
        $topic['format'] = 'flarum';
        $topic['content'] = $chars > $maxChars ? null : $content;
        $topic['chars'] = $chars;
        if ($tags !== null) $topic['tags'] = $tags;

        $out = [];
        foreach (array_chunk(array_values(array_unique($hashes)), max(1, $maxHashes)) as $chunk) {
            $out[] = $topic + ['hashes' => $chunk];
        }
        return $out;
    }

    public static function tagList(array $tags): array
    {
        $key = fn (array $t) => [($t['position'] ?? null) === null ? 1 : 0, (int) ($t['position'] ?? 0), mb_strtolower((string) ($t['name'] ?? ''))];
        usort($tags, fn ($a, $b) => $key($a) <=> $key($b));
        $out = [];
        foreach ($tags as $t) {
            $slug = trim((string) ($t['slug'] ?? ''));
            if ($slug === '' || isset($out[$slug])) continue;
            $color = trim((string) ($t['color'] ?? ''));
            $parent = trim((string) ($t['parent'] ?? ''));
            $out[$slug] = ['slug' => $slug, 'name' => (string) ($t['name'] ?? $slug), 'color' => $color !== '' ? $color : null, 'parent' => $parent !== '' ? $parent : null];
            if (count($out) >= self::MAX_TAGS) break;
        }
        return array_values($out);
    }

    public static function hidden(int $discussionId, int $postId, array $hashes, bool $gone, int $maxHashes = self::MAX_HASHES): array
    {
        $topic = ['discussion_id' => $discussionId, 'post_id' => $postId, 'visible' => false];
        if ($gone) $topic['gone'] = true;
        $out = [];
        foreach (array_chunk(array_values(array_unique($hashes)), max(1, $maxHashes)) as $chunk) {
            $out[] = $topic + ['hashes' => $chunk];
        }
        return $out;
    }

    public static function plan(?array $sent, bool $visible, bool $private, array $present, array $eligible): array
    {
        $sentHashes = array_values(array_unique($sent['hashes'] ?? []));
        $here = array_flip($present);
        $gone = array_values(array_filter($sentHashes, fn ($h) => !isset($here[$h])));
        $kept = array_values(array_filter($sentHashes, fn ($h) => isset($here[$h])));
        $eligible = array_values(array_unique($eligible));
        if ($visible) {
            $describe = $eligible;
            $withdraw = [];
        } else {
            $describe = [];
            $withdraw = $sent === null ? ($private ? [] : $eligible) : (!empty($sent['visible']) ? $kept : []);
        }
        return [
            'describe' => $describe,
            'withdraw' => $withdraw,
            'gone' => $gone,
            'keep' => array_values(array_unique(array_merge($kept, $describe, $withdraw))),
        ];
    }

    public static function fingerprint(array $topics): string
    {
        return sha1((string) json_encode(array_values($topics), TrackerWhitelistClient::JSON_FLAGS));
    }

    public static function topicState(array $results, ?string $previous = null): array
    {
        $problem = null;
        $declined = null;
        $seen = [];
        foreach ($results as $r) {
            $status = (string) ($r['status'] ?? '');
            $seen[$status] = true;
            if (empty($r['hidden']) && in_array($status, self::DECLINED, true)) {
                $declined ??= $r;
                continue;
            }
            $bad = in_array($status, self::FAILED, true)
                || (empty($r['hidden']) && $status !== 'stale' && in_array($status, self::SKIPPED, true));
            if ($bad && ($problem === null || ($status === 'error' && $problem['status'] !== 'error'))) $problem = $r;
        }
        if ($problem !== null) {
            $detail = trim((string) ($problem['detail'] ?? ''));
            return ['error', (string) $problem['status'], $detail !== '' ? $detail : ($problem['hash'] ?? null)];
        }
        if ($declined !== null) return ['declined', (string) $declined['status'], null];
        if (isset($seen['proposed'])) return ['proposed', 'proposed', null];
        if (isset($seen['queued'])) return ['waiting', 'queued', null];
        foreach (self::COUNTERS['published'] as $status) {
            if (isset($seen[$status])) return ['published', $status, null];
        }
        if (array_filter($results, fn ($r) => empty($r['hidden']))) {
            return [in_array($previous, ['published', 'waiting', 'proposed'], true) ? $previous : 'unchanged', isset($seen['kept']) ? 'kept' : 'unchanged', null];
        }
        foreach (['withdrawn', 'released'] as $status) {
            if (isset($seen[$status])) return ['withdrawn', $status, null];
        }
        return ['withdrawn', $results ? (string) ($results[0]['status'] ?? '') : null, null];
    }

    public static function body(array $topics, int $maxChars, bool $copyImages): array
    {
        return ['topics' => array_values($topics), 'max_chars' => $maxChars, 'copy_images' => $copyImages];
    }

    public static function pack(array $topics, int $maxChars, bool $copyImages, int $maxTopics = self::MAX_TOPICS, int $maxBytes = self::MAX_BYTES): array
    {
        $envelope = strlen((string) json_encode(self::body([], $maxChars, $copyImages), TrackerWhitelistClient::JSON_FLAGS));
        $calls = [];
        $call = [];
        $bytes = $envelope;
        foreach ($topics as $topic) {
            $size = self::bytes($topic) + ($call ? 1 : 0);
            if ($call && (count($call) >= $maxTopics || $bytes + $size > $maxBytes)) {
                $calls[] = $call;
                $call = [];
                $bytes = $envelope;
                $size--;
            }
            $call[] = $topic;
            $bytes += $size;
        }
        if ($call) $calls[] = $call;
        return $calls;
    }

    public static function bytes(array $topic): int
    {
        return strlen((string) json_encode($topic, TrackerWhitelistClient::JSON_FLAGS));
    }

    public static function tally(array $summary, array $totals = []): array
    {
        foreach (self::COUNTERS as $counter => $statuses) {
            $totals[$counter] = (int) ($totals[$counter] ?? 0);
            foreach ($statuses as $status) $totals[$counter] += (int) ($summary[$status] ?? 0);
        }
        return $totals;
    }

    public static function sentThrough(array $topics, int $sent, int $fallback): int
    {
        $at = fn ($topic) => is_array($topic) ? (int) $topic['post_id'] : (int) $topic;
        $next = isset($topics[$sent]) ? $at($topics[$sent]) : null;
        $through = $fallback;
        foreach (array_slice($topics, 0, $sent) as $topic) {
            if ($at($topic) !== $next) $through = max($through, $at($topic));
        }
        return $through;
    }
}
