<?php

namespace TryHackX\HomepageBlocks\Listener;

use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\Discussion\Event\Hidden as DiscussionHidden;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Event\Revised;
use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\MagnetExtractor;
use TryHackX\HomepageBlocks\Service\TrackerQueue;
use TryHackX\HomepageBlocks\Service\TrackerSync;
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
 *  - Skipped entirely while the client is in failure cooldown — a dead tracker holds a PHP worker
 *    for at most one attempt per window.
 *  - NEVER throws into the request.
 *  - Posted covers the first post of a new discussion (Discussion\Event\Started is not needed).
 */
class SyncPostMagnetsToTracker
{
    private const DISCUSSION_EVENTS = [
        'Flarum\Tags\Event\DiscussionWasTagged',
        'FoF\Byobu\Events\RecipientsChanged',
        'FoF\Byobu\Events\DiscussionMadePublic',
        'FoF\Byobu\Events\RemovedSelf',
        'FoF\Byobu\Events\AllRecipientsLeft',
    ];

    private static bool $loggedThisRequest = false;

    public function __construct(
        private TrackerWhitelistClient $client,
        private TrackerQueue $queue,
        private TrackerSync $sync,
        private WhitelistScanner $scanner,
        private LoggerInterface $logger,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, [$this, 'handle']);
        $events->listen(Revised::class, [$this, 'handle']);
        $events->listen(PostRestored::class, [$this, 'handle']);
        if (class_exists('Flarum\Approval\Event\PostWasApproved')) {
            $events->listen('Flarum\Approval\Event\PostWasApproved', [$this, 'handle']);
        }
        $events->listen(PostHidden::class, [$this, 'postHidden']);
        $events->listen(PostDeleted::class, [$this, 'postDeleted']);
        $events->listen(DiscussionHidden::class, [$this, 'discussionHidden']);
        $events->listen(DiscussionDeleted::class, [$this, 'discussionHidden']);
        $events->listen(DiscussionDeleting::class, [$this, 'discussionDeleting']);
        $events->listen(DiscussionRestored::class, [$this, 'discussionChanged']);
        foreach (self::DISCUSSION_EVENTS as $class) {
            if (class_exists($class)) $events->listen($class, [$this, 'discussionChanged']);
        }
        $events->listen(Saved::class, [$this, 'settingsSaved']);
    }

    public function handle(object $event): void
    {
        $this->safely(function () use ($event) {
            $post = $event->post ?? null;
            if (!$post instanceof CommentPost || !$this->client->isEnabled()) return;
            if (!$this->isFirstPost($post) && !MagnetExtractor::extract(self::raw($post), $this->client->detectBareHashes())) return;
            $this->queue->enqueue((int) $post->discussion_id, TrackerQueue::POST, (int) $post->id);
            $this->sync->schedule();
        });
    }

    public function postHidden(PostHidden $event): void
    {
        $this->safely(function () use ($event) {
            if (!$this->client->isEnabled() || !$this->isFirstPost($event->post)) return;
            $this->queue->enqueue((int) $event->post->discussion_id, TrackerQueue::DESCRIBE);
            $this->sync->schedule();
        });
    }

    public function postDeleted(PostDeleted $event): void
    {
        $this->safely(function () use ($event) {
            $post = $event->post;
            if (!$post instanceof CommentPost || !$this->client->isEnabled() || !$this->isFirstPost($post)) return;
            $discussion = Discussion::query()->find($post->discussion_id);
            $this->scanner->rememberFirstPost((int) $post->discussion_id, $post, !empty($post->is_private) || !empty($discussion?->is_private));
            $this->queue->enqueue((int) $post->discussion_id, TrackerQueue::DESCRIBE);
            $this->sync->schedule();
        });
    }

    public function discussionDeleting(DiscussionDeleting $event): void
    {
        $this->safely(function () use ($event) {
            $discussion = $event->discussion;
            if (!$this->client->isEnabled() || !$discussion->exists) return;
            $first = $discussion->first_post_id
                ? CommentPost::query()->where('posts.type', 'comment')->whereKey($discussion->first_post_id)->first()
                : null;
            $this->scanner->rememberFirstPost((int) $discussion->id, $first, !empty($discussion->is_private) || !empty($first?->is_private));
            $this->queue->enqueue((int) $discussion->id, TrackerQueue::DESCRIBE);
        });
    }

    public function discussionHidden(object $event): void
    {
        $this->enqueueDiscussion($event->discussion ?? null, TrackerQueue::DESCRIBE);
    }

    public function discussionChanged(object $event): void
    {
        $this->enqueueDiscussion($event->discussion ?? null, TrackerQueue::ALL);
    }

    public function settingsSaved(Saved $event): void
    {
        $this->safely(function () use ($event) {
            $prefix = TrackerWhitelistClient::S . '.whitelist_';
            $keys = array_keys($event->settings);
            if (array_intersect($keys, [$prefix . 'api_url', $prefix . 'api_key_id'])) $this->sync->forgetConsent();
            if (!array_intersect($keys, [$prefix . 'image_copy', $prefix . 'sync_enabled', $prefix . 'api_url', $prefix . 'api_key_id', $prefix . 'audience'])) return;
            $this->sync->schedule();
        });
    }

    private function enqueueDiscussion(mixed $discussion, string $reason): void
    {
        $this->safely(function () use ($discussion, $reason) {
            if (!$discussion instanceof Discussion || !$this->client->isEnabled()) return;
            $this->queue->enqueue((int) $discussion->id, $reason);
            $this->sync->schedule();
        });
    }

    private function isFirstPost(CommentPost $post): bool
    {
        if ($post->number !== null && (int) $post->number === 1) return true;
        $firstPostId = $post->discussion?->first_post_id;
        return $firstPostId === null || (int) $firstPostId === (int) $post->id;
    }

    private static function raw(CommentPost $post): string
    {
        return (string) ($post->getRawOriginal('content') ?? $post->getAttributes()['content'] ?? '');
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
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
