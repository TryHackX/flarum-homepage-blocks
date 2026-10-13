<?php

namespace TryHackX\HomepageBlocks\Meta;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Keeps the `homepage_discussion_meta` table (the Advanced Filters index) in step with
 * the discussions: one discussion at a time from the listener, or the whole forum in
 * batches (admin "Rebuild index" / `php flarum homepage-blocks:reindex`).
 *
 * Every discussion is indexed — hidden, private and unapproved ones too: visibility is
 * enforced by the discussion search itself (`whereVisibleTo`), and a discussion that is
 * restored or approved later must already have its row.
 */
final class MetaIndexer
{
    public const TABLE = 'homepage_discussion_meta';
    public const BATCH = 200;
    public const MAX_BATCH = 1000;
    /** Wall-clock budget of one batch (php-fpm usually allows 30 s). */
    public const BUDGET_SEC = 12.0;

    private const COLUMNS = ['size_bytes', 'size_source', 'is_series', 'seasons', 'episodes', 'year_start', 'year_end',
        'duration_min', 'imdb_rating', 'filmweb_rating', 'signature', 'indexed_at'];

    private ?ExtractorConfig $config = null;

    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function config(): ExtractorConfig
    {
        return $this->config ??= ExtractorConfig::fromSettings($this->settings);
    }

    public function signature(): string
    {
        return $this->config()->signature();
    }

    /** (Re)index one discussion from its current title and first post. */
    public function indexDiscussion(int $discussionId): void
    {
        $row = $this->source()->where('discussions.id', $discussionId)->first();
        if ($row === null) {
            $this->forget($discussionId);

            return;
        }
        $this->write([$this->rowFor($row)]);
    }

    public function forget(int $discussionId): void
    {
        $this->db->table(self::TABLE)->where('discussion_id', $discussionId)->delete();
    }

    /**
     * One batch of the full rebuild: discussions with id > $cursor, in id order.
     *
     * @return array{next_cursor: int, done: bool, processed: int, elapsed_ms: int}
     */
    public function indexBatch(int $cursor, int $limit = self::BATCH, bool $onlyStale = false): array
    {
        $t0 = microtime(true);
        $started = Carbon::now();
        $limit = max(1, min(self::MAX_BATCH, $limit));

        $query = $this->source()->where('discussions.id', '>', $cursor)->orderBy('discussions.id')->limit($limit);
        if ($onlyStale) {
            $signature = $this->signature();
            $query->leftJoin(self::TABLE, self::TABLE . '.discussion_id', '=', 'discussions.id')
                ->where(function (Builder $q) use ($signature) {
                    $q->whereNull(self::TABLE . '.discussion_id')->orWhere(self::TABLE . '.signature', '!=', $signature);
                });
        }
        $rows = $query->get();

        $out = [];
        $last = $cursor;
        $stopped = false;
        foreach ($rows as $row) {
            // Always at least one row per batch, so a slow SELECT can't stall the cursor.
            if ($out && (microtime(true) - $t0) > self::BUDGET_SEC) {
                $stopped = true;
                break;
            }
            $out[] = $this->rowFor($row);
            $last = (int) $row->id;
        }
        $this->write($this->stillCurrent($out, $started));

        return [
            'next_cursor' => $last,
            'done' => ! $stopped && count($rows) < $limit,
            'processed' => count($out),
            'elapsed_ms' => (int) ((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * @return array{total: int, indexed: int, missing: int, stale: int, with_size: int, series: int, movies: int, signature: string}
     */
    public function status(): array
    {
        $t = self::TABLE;
        $signature = $this->signature();
        $total = (int) $this->db->table('discussions')->count();
        $indexed = (int) $this->db->table($t)->count();
        $count = fn (callable $where) => (int) $where($this->db->table($t))->count();

        return [
            'total' => $total,
            'indexed' => $indexed,
            'missing' => (int) $this->db->table('discussions')
                ->leftJoin($t, "$t.discussion_id", '=', 'discussions.id')
                ->whereNull("$t.discussion_id")->count(),
            'stale' => $count(fn ($q) => $q->where('signature', '!=', $signature)),
            'with_size' => $count(fn ($q) => $q->whereNotNull('size_bytes')),
            'series' => $count(fn ($q) => $q->where('is_series', 1)),
            'movies' => $count(fn ($q) => $q->where('is_series', 0)),
            'signature' => $signature,
        ];
    }

    /**
     * What the parser reads from one discussion right now (admin preview while tuning
     * the labels), next to what the index currently holds.
     *
     * @return array{id: int, title: string, extracted: array, stored: ?array}|null
     */
    public function preview(int $discussionId): ?array
    {
        $row = $this->source()->where('discussions.id', $discussionId)->first();
        if ($row === null) {
            return null;
        }
        $stored = $this->db->table(self::TABLE)->where('discussion_id', $discussionId)->first();

        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'extracted' => (new MetaExtractor($this->config()))->extract((string) $row->title, $row->xml),
            'stored' => $stored ? (array) $stored : null,
        ];
    }

    /**
     * Rows read before $started may be outdated by now: drop those the live listener
     * re-indexed meanwhile (fresher data) and those whose discussion was deleted
     * (the foreign key would reject the whole chunk).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function stillCurrent(array $rows, Carbon $started): array
    {
        if (! $rows) {
            return [];
        }
        $ids = array_column($rows, 'discussion_id');
        $existing = array_flip(array_map('intval', $this->db->table('discussions')->whereIn('id', $ids)->pluck('id')->all()));
        $fresher = array_flip(array_map('intval', $this->db->table(self::TABLE)->whereIn('discussion_id', $ids)
            ->where('indexed_at', '>=', $started)->pluck('discussion_id')->all()));

        return array_values(array_filter($rows, fn (array $r) => isset($existing[$r['discussion_id']]) && ! isset($fresher[$r['discussion_id']])));
    }

    /** Discussion id + title + the first post's stored (s9e XML) content. */
    private function source(): Builder
    {
        return $this->db->table('discussions')
            ->leftJoin('posts', 'posts.id', '=', 'discussions.first_post_id')
            ->select('discussions.id', 'discussions.title', 'posts.content as xml');
    }

    private function rowFor(object $row): array
    {
        $meta = (new MetaExtractor($this->config()))->extract((string) $row->title, is_string($row->xml ?? null) ? $row->xml : null);

        return ['discussion_id' => (int) $row->id] + $meta + [
            'signature' => $this->signature(),
            'indexed_at' => Carbon::now(),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function write(array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->db->table(self::TABLE)->upsert($chunk, ['discussion_id'], self::COLUMNS);
        }
    }
}
