<?php

namespace TryHackX\HomepageBlocks\Listener;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\WhitelistScanner;

/**
 * Live sync: every created / edited (and, with flarum/approval, approved) comment post is scanned
 * for magnet links which are pushed to the tracker whitelist.
 *
 *  - Runs INSIDE the post-save request (queue driver is usually `sync`), so it is bounded by the
 *    configured timeout (1–10 s) and skipped entirely while the client is in failure cooldown —
 *    a dead tracker never slows the forum by more than one attempt per cooldown window.
 *  - NEVER throws into the post save; failures are logged once per cooldown window.
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
    ) {
    }

    /** @param Posted|Revised|object $event  (also \Flarum\Approval\Event\PostWasApproved) */
    public function handle($event): void
    {
        try {
            $post = $event->post ?? null;
            if (!$post instanceof CommentPost) return;
            if (!$this->client->isEnabled() || $this->client->inCooldown() || !$this->client->isConfigured()) return;
            if ($post->hidden_at !== null || !empty($post->is_private)) return;
            $discussion = $post->discussion;
            if ($discussion && ($discussion->hidden_at !== null || !empty($discussion->is_private))) return;

            $r = $this->scanner->syncPost($post);
            if ($r !== null && !$r['ok'] && !self::$loggedThisRequest) {
                self::$loggedThisRequest = true;
                $this->logger->warning('[tryhackx-homepage-blocks] whitelist sync failed for post ' . $post->id . ': ' . ($r['error'] ?? 'unknown')
                    . ($r['status'] === 403 || $r['status'] === 401 ? ' — check the API key/secret; the tracker may have banned this server IP' : ''));
            }
        } catch (\Throwable $e) {
            if (!self::$loggedThisRequest) {
                self::$loggedThisRequest = true;
                try { $this->logger->warning('[tryhackx-homepage-blocks] whitelist sync error: ' . $e->getMessage()); } catch (\Throwable $ignored) {}
            }
        }
    }
}
