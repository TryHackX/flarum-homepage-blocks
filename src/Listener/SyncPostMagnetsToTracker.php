<?php

namespace TryHackX\HomepageBlocks\Listener;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\WhitelistScanner;

/**
 * Live sync: every created / edited (and, with flarum/approval, approved) comment post is scanned
 * for magnet links which are pushed to the tracker whitelist.
 *
 *  - Runs AFTER the database transaction that saved the post commits. Flarum dispatches `Posted`
 *    for a new discussion's first post inside the discussion-create transaction; calling the
 *    tracker there would keep that transaction (and its row locks) open for up to the configured
 *    timeout, and would whitelist hashes of a save that is later rolled back. Deferred through
 *    Connection::afterCommit(): immediate when no transaction is open, dropped on rollback.
 *  - Bounded by the configured timeout (1–10 s) and skipped entirely while the client is in
 *    failure cooldown — a dead tracker never slows the forum by more than one attempt per window.
 *  - NEVER throws into the request; failures are logged once per cooldown window.
 *  - Posted covers the first post of a new discussion (Discussion\Event\Started is not needed).
 *    Deleted/Hidden/Restored are intentionally NOT handled: the tracker API is additive-only —
 *    removing a hash from the whitelist is a moderation decision made in the tracker panel.
 */
class SyncPostMagnetsToTracker
{
    private static bool $loggedThisRequest = false;

    public function __construct(
        private TrackerWhitelistClient $client,
        private WhitelistScanner $scanner,
        private LoggerInterface $logger,
        private ConnectionInterface $db,
    ) {
    }

    /** @param Posted|Revised|object $event  (also \Flarum\Approval\Event\PostWasApproved) */
    public function handle($event): void
    {
        try {
            $post = $event->post ?? null;
            if (!$post instanceof CommentPost || !$this->client->isEnabled()) return;
            $this->afterCommit(fn () => $this->sync($post));
        } catch (\Throwable $e) {
            $this->logOnce('whitelist sync error: ' . $e->getMessage());
        }
    }

    /**
     * Run $fn once the current transaction commits (now, when none is open). Cores without a
     * transactions manager (Flarum < 2.0 stable) throw from afterCommit() — then run it now,
     * as before. sync() never throws, so a fallback can't run it twice.
     */
    private function afterCommit(callable $fn): void
    {
        if (method_exists($this->db, 'afterCommit')) {
            try {
                $this->db->afterCommit($fn);
                return;
            } catch (\RuntimeException $e) {
                // "Transactions Manager has not been set."
            }
        }
        $fn();
    }

    private function sync(CommentPost $post): void
    {
        try {
            if ($this->client->inCooldown() || !$this->client->isConfigured()) return;
            if ($post->hidden_at !== null || !empty($post->is_private)) return;
            $discussion = $post->discussion;
            if ($discussion && ($discussion->hidden_at !== null || !empty($discussion->is_private))) return;

            $r = $this->scanner->syncPost($post);
            if ($r !== null && !$r['ok']) {
                $this->logOnce('whitelist sync failed for post ' . $post->id . ': ' . ($r['error'] ?? 'unknown')
                    . (($r['status'] ?? 0) === 403 || ($r['status'] ?? 0) === 401 ? ' — check the API key/secret; the tracker may have banned this server IP' : ''));
            }
        } catch (\Throwable $e) {
            $this->logOnce('whitelist sync error: ' . $e->getMessage());
        }
    }

    private function logOnce(string $message): void
    {
        if (self::$loggedThisRequest) return;
        self::$loggedThisRequest = true;
        try { $this->logger->warning('[tryhackx-homepage-blocks] ' . $message); } catch (\Throwable $ignored) {}
    }
}
