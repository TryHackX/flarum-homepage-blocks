<?php

namespace TryHackX\HomepageBlocks\Service;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

final class SentTopics
{
    public const TABLE = 'homepage_tracker_topics';
    public const STALE_LIMIT = 100;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public static function decode(object $row): array
    {
        $hashes = json_decode((string) $row->hashes, true);
        return [
            'discussion_id' => (int) $row->discussion_id,
            'post_id' => (int) $row->post_id,
            'hashes' => is_array($hashes) ? array_values(array_filter($hashes, fn ($h) => is_string($h) && preg_match('/^[0-9a-f]{40}$/', $h) === 1)) : [],
            'visible' => (bool) $row->visible,
            'sha' => $row->sha !== null ? (string) $row->sha : null,
            'state' => $row->state !== null ? (string) $row->state : null,
            'status' => $row->status !== null ? (string) $row->status : null,
            'detail' => $row->detail !== null ? (string) $row->detail : null,
            'sent_at' => $row->sent_at !== null ? Carbon::parse($row->sent_at)->getTimestamp() : null,
        ];
    }

    public function get(int $discussionId): ?array
    {
        $row = $this->db->table(self::TABLE)->where('discussion_id', $discussionId)->first();
        return $row ? self::decode($row) : null;
    }

    public function many(array $discussionIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $discussionIds))), 500) as $chunk) {
            foreach ($this->db->table(self::TABLE)->whereIn('discussion_id', $chunk)->get() as $row) {
                $out[(int) $row->discussion_id] = self::decode($row);
            }
        }
        return $out;
    }

    public function remember(int $discussionId, int $postId, array $hashes): void
    {
        if (!$hashes) return;
        $sent = $this->get($discussionId);
        $merged = array_values(array_unique(array_merge($sent['hashes'] ?? [], $hashes)));
        if ($sent !== null && $merged === $sent['hashes']) return;
        $this->write($discussionId, $sent['post_id'] ?? $postId, $merged, $sent['visible'] ?? true, $sent['sha'] ?? null, $sent['state'] ?? null, $sent['status'] ?? null, $sent['detail'] ?? null);
    }

    public function record(int $discussionId, int $postId, array $hashes, bool $visible, ?string $sha, ?string $state, ?string $status, ?string $detail): void
    {
        if (!$hashes) {
            $this->forget($discussionId);
            return;
        }
        $this->write($discussionId, $postId, $hashes, $visible, $sha, $state, $status, $detail);
    }

    public function forget(int $discussionId): void
    {
        $this->db->table(self::TABLE)->where('discussion_id', $discussionId)->delete();
    }

    private function write(int $discussionId, int $postId, array $hashes, bool $visible, ?string $sha, ?string $state, ?string $status, ?string $detail): void
    {
        $values = [
            'post_id' => $postId,
            'hashes' => (string) json_encode(array_values(array_unique($hashes))),
            'visible' => $visible,
            'sha' => $sha,
            'state' => $state,
            'status' => $status !== null ? mb_substr($status, 0, 32) : null,
            'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null,
            'sent_at' => Carbon::now(),
        ];
        $this->db->table(self::TABLE)->upsert([['discussion_id' => $discussionId] + $values], ['discussion_id'], array_keys($values));
    }

    public function after(int $postId, int $limit = self::STALE_LIMIT): array
    {
        return $this->db->table(self::TABLE)
            ->where('post_id', '>', $postId)
            ->orderBy('post_id')
            ->orderBy('discussion_id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => self::decode($row))
            ->all();
    }

    public function counts(): array
    {
        $out = array_fill_keys(DescriptionPayload::STATES, 0);
        $rows = $this->db->table(self::TABLE)->select('state', $this->db->raw('COUNT(*) as n'))->whereNotNull('state')->groupBy('state')->get();
        foreach ($rows as $row) {
            if (isset($out[(string) $row->state])) $out[(string) $row->state] += (int) $row->n;
        }
        return $out;
    }

    public function problems(int $limit): array
    {
        return $this->db->table(self::TABLE)->where('state', 'error')->orderByDesc('sent_at')->limit($limit)->get()
            ->map(fn ($row) => self::decode($row))->all();
    }
}
