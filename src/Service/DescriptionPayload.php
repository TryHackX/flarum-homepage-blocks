<?php

namespace TryHackX\HomepageBlocks\Service;

use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;

final class DescriptionPayload
{
    public const MAX_HASHES = 20;
    public const MAX_TOPICS = 25;
    public const MAX_BYTES = 3500000;
    public const MAX_URL = 500;
    public const DESCRIBED = ['created', 'queued', 'updated', 'replaced', 'proposed'];
    public const COUNTED = ['unchanged', 'kept', 'too_long', 'invalid', 'skipped'];

    public static function topics(int $discussionId, int $postId, ?string $url, ?array $author, ?\DateTimeInterface $editedAt, ?string $content, int $maxChars, array $hashes): array
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

        $out = [];
        foreach (array_chunk(array_values(array_unique($hashes)), self::MAX_HASHES) as $chunk) {
            $out[] = $topic + ['hashes' => $chunk];
        }
        return $out;
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
        $totals['described'] = (int) ($totals['described'] ?? 0);
        foreach (self::DESCRIBED as $k) $totals['described'] += (int) ($summary[$k] ?? 0);
        foreach (self::COUNTED as $k) $totals[$k] = (int) ($totals[$k] ?? 0) + (int) ($summary[$k] ?? 0);
        return $totals;
    }

    public static function sentThrough(array $topics, int $sent, int $fallback): int
    {
        $next = $topics[$sent]['post_id'] ?? null;
        $through = $fallback;
        foreach (array_slice($topics, 0, $sent) as $topic) {
            if ($topic['post_id'] !== $next) $through = max($through, (int) $topic['post_id']);
        }
        return $through;
    }
}
