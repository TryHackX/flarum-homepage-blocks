<?php

namespace TryHackX\HomepageBlocks\Service;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class TrackerQueue
{
    public const TABLE = 'homepage_tracker_queue';
    public const POST = 'post';
    public const ALL = 'all';
    public const DESCRIBE = 'describe';
    public const BACKOFF = [60, 120, 300, 600, 1800, 3600, 7200, 21600];

    public function __construct(private ConnectionInterface $db)
    {
    }

    public static function merge(string $reason, ?int $postId, string $otherReason, ?int $otherPostId): array
    {
        $a = self::normal($reason, $postId);
        $b = self::normal($otherReason, $otherPostId);
        if ($a[0] === self::ALL || $b[0] === self::ALL) return [self::ALL, null];
        if ($a[0] === self::DESCRIBE) return $b;
        if ($b[0] === self::DESCRIBE) return $a;
        return $a[1] === $b[1] ? $a : [self::ALL, null];
    }

    private static function normal(string $reason, ?int $postId): array
    {
        if ($reason === self::DESCRIBE) return [self::DESCRIBE, null];
        if ($reason === self::POST && $postId !== null && $postId > 0) return [self::POST, $postId];
        return [self::ALL, null];
    }

    public static function backoff(int $attempts, ?int $retryAfter = null): int
    {
        $step = self::BACKOFF[max(0, min(count(self::BACKOFF), $attempts) - 1)];
        return max($step, min(self::BACKOFF[count(self::BACKOFF) - 1], (int) $retryAfter));
    }

    public function enqueue(int $discussionId, string $reason, ?int $postId = null): void
    {
        if ($discussionId < 1) return;
        [$reason, $postId] = self::normal($reason, $postId);
        $now = Carbon::now();
        for ($try = 0; $try < 3; $try++) {
            $row = $this->db->table(self::TABLE)->where('discussion_id', $discussionId)->first();
            if (!$row) {
                $inserted = $this->db->table(self::TABLE)->insertOrIgnore([
                    'discussion_id' => $discussionId,
                    'post_id' => $postId,
                    'reason' => $reason,
                    'seq' => 1,
                    'attempts' => 0,
                    'next_at' => $now,
                    'last_error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($inserted) return;
                continue;
            }
            [$merged, $mergedPost] = self::merge((string) $row->reason, $row->post_id !== null ? (int) $row->post_id : null, $reason, $postId);
            $updated = $this->db->table(self::TABLE)
                ->where('discussion_id', $discussionId)
                ->where('seq', (int) $row->seq)
                ->update(['reason' => $merged, 'post_id' => $mergedPost, 'seq' => (int) $row->seq + 1, 'next_at' => $now, 'updated_at' => $now]);
            if ($updated) return;
        }
        $this->db->table(self::TABLE)->where('discussion_id', $discussionId)
            ->update(['reason' => self::ALL, 'post_id' => null, 'seq' => $this->db->raw('seq + 1'), 'next_at' => $now, 'updated_at' => $now]);
    }

    public function defer(int $discussionId, string $error, ?int $retryAfter = null): void
    {
        $this->enqueue($discussionId, self::DESCRIBE);
        $row = $this->db->table(self::TABLE)->where('discussion_id', $discussionId)->first();
        if ($row) $this->retry($row, $error, true, false, $retryAfter);
    }

    public function due(int $limit): Collection
    {
        return $this->db->table(self::TABLE)
            ->where('next_at', '<=', Carbon::now())
            ->orderBy('next_at')
            ->orderBy('discussion_id')
            ->limit($limit)
            ->get();
    }

    public function done(object $row): void
    {
        $this->db->table(self::TABLE)->where('discussion_id', (int) $row->discussion_id)->where('seq', (int) $row->seq)->delete();
    }

    public function retry(object $row, ?string $error, bool $countAttempt, bool $magnetsDone, ?int $retryAfter = null, ?int $until = null): void
    {
        $attempts = (int) $row->attempts + ($countAttempt ? 1 : 0);
        $delay = $until !== null ? max(1, $until - time()) : ($countAttempt ? self::backoff($attempts, $retryAfter) : max(1, (int) $retryAfter));
        $set = [
            'attempts' => $attempts,
            'next_at' => Carbon::now()->addSeconds($delay),
            'last_error' => $error !== null ? mb_substr($error, 0, 300) : $row->last_error,
            'updated_at' => Carbon::now(),
        ];
        if ($magnetsDone) {
            $set['reason'] = self::DESCRIBE;
            $set['post_id'] = null;
        }
        $this->db->table(self::TABLE)->where('discussion_id', (int) $row->discussion_id)->where('seq', (int) $row->seq)->update($set);
    }

    public function stats(): array
    {
        $now = Carbon::now();
        $next = $this->db->table(self::TABLE)->min('next_at');
        return [
            'pending' => (int) $this->db->table(self::TABLE)->count(),
            'due' => (int) $this->db->table(self::TABLE)->where('next_at', '<=', $now)->count(),
            'failing' => (int) $this->db->table(self::TABLE)->where('attempts', '>', 0)->count(),
            'next_at' => $next !== null ? Carbon::parse($next)->getTimestamp() : null,
        ];
    }

    public function failing(int $limit): Collection
    {
        return $this->db->table(self::TABLE)->where('attempts', '>', 0)->orderByDesc('updated_at')->limit($limit)->get();
    }
}
