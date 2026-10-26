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
 *  - Under PHP-FPM the call runs AFTER the response has been sent (fastcgi_finish_request() in a
 *    shutdown function): the member never waits for the tracker, even a slow one. CLI, tests and
 *    other SAPIs run it right away, as before.
 *  - Bounded by the configured timeout (1–10 s) and skipped entirely while the client is in
 *    failure cooldown — a dead tracker holds a PHP worker for at most one attempt per window.
 *  - NEVER throws into the request; failures are logged once per cooldown window.
 *  - Posted covers the first post of a new discussion (Discussion\Event\Started is not needed).
 *    Deleted/Hidden/Restored are intentionally NOT handled: the tracker API is additive-only —
 *    removing a hash from the whitelist is a moderation decision made in the tracker panel.
 */
class SyncPostMagnetsToTracker
{
    private static bool $loggedThisRequest = false;

    /** @var callable[] syncs waiting for the response to be sent ({@see later()}) */
    private static array $deferred = [];

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
            $this->afterCommit(fn () => $this->later(fn () => $this->sync($post)));
        } catch (\Throwable $e) {
            $this->logOnce('whitelist sync error: ' . $e->getMessage());
        }
    }

    /**
     * Run $fn once the response is on its way. Under PHP-FPM the first deferred sync registers a
     * shutdown function that finishes the request and then drains the queue; anywhere else
     * (CLI, tests, mod_php) it runs now. sync() never throws, so one failure can't skip the rest.
     */
    private function later(callable $fn): void
    {
        if (PHP_SAPI !== 'fpm-fcgi' || !function_exists('fastcgi_finish_request')) {
            $fn();
            return;
        }
        if (!self::$deferred) {
            register_shutdown_function(static function (): void {
                fastcgi_finish_request();
                $queue = self::$deferred;
                self::$deferred = [];
                foreach ($queue as $deferred) {
                    $deferred();
                }
            });
        }
        self::$deferred[] = $fn;
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
            if ($post->hidden_at !== null || !empty($post->is_private) || !($post->is_approved ?? true)) return;
            $discussion = $post->discussion;
            if ($discussion && ($discussion->hidden_at !== null || !empty($discussion->is_private))) return;

            $r = $this->scanner->syncPost($post);
            if ($r !== null && !$r['ok']) {
                $this->logOnce('whitelist sync failed for post ' . $post->id . ': ' . ($r['error'] ?? 'unknown')
                    . (($r['status'] ?? 0) === 403 || ($r['status'] ?? 0) === 401 ? ' — check the API key/secret; the tracker may have banned this server IP' : ''));
                return;
            }
            if ($r === null || !$discussion || !$this->client->describeEnabled() || !$this->isFirstPost($post, $discussion)) return;

            $d = $this->scanner->describePost($post, $r['hashes'] ?? []);
            if ($d !== null && !$d['ok'] && !$d['off']) {
                $this->logOnce('whitelist describe failed for discussion ' . $post->discussion_id . ': ' . ($d['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            $this->logOnce('whitelist sync error: ' . $e->getMessage());
        }
    }

    private function isFirstPost(CommentPost $post, $discussion): bool
    {
        $firstPostId = $discussion->first_post_id ?? $discussion->newQuery()->whereKey($discussion->getKey())->value('first_post_id');
        return (int) $firstPostId === (int) $post->id;
    }

    private function logOnce(string $message): void
    {
        if (self::$loggedThisRequest) return;
        self::$loggedThisRequest = true;
        try { $this->logger->warning('[tryhackx-homepage-blocks] ' . $message); } catch (\Throwable $ignored) {}
    }
}
