<?php

namespace TryHackX\HomepageBlocks\Listener;

use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Renamed;
use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * Keeps the Advanced Filters index current:
 *
 *  - Started  — a new discussion. Dispatched after the discussion + first post are
 *               committed (the first post's own `Posted` fires inside that transaction,
 *               before `first_post_id` is set, so it is deliberately not used here).
 *  - Revised  — only when the edited post is the discussion's first post.
 *  - Renamed  — the title is a source of the size and the year.
 *  - Deleted  — drop the row (the foreign key cascades too; this covers databases
 *               running without foreign keys).
 *
 * Does nothing unless an index-backed filter or sort is enabled, and never throws into
 * the request that triggered it.
 */
class IndexDiscussionMeta
{
    public function __construct(
        private AdvancedSettings $adv,
        private MetaIndexer $indexer,
        private LoggerInterface $logger,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Started::class, fn (Started $e) => $this->index((int) $e->discussion->id));
        $events->listen(Renamed::class, fn (Renamed $e) => $this->index((int) $e->discussion->id));
        $events->listen(Revised::class, function (Revised $e) {
            $post = $e->post;
            $discussion = $post->discussion;
            if ($discussion && ((int) $discussion->first_post_id === (int) $post->id || (int) $post->number === 1)) {
                $this->index((int) $discussion->id);
            }
        });
        $events->listen(Deleted::class, function (Deleted $e) {
            $this->safely(fn () => $this->indexer->forget((int) $e->discussion->id));
        });
    }

    private function index(int $discussionId): void
    {
        if (! $this->adv->indexingEnabled()) {
            return;
        }
        $this->safely(fn () => $this->indexer->indexDiscussion($discussionId));
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            try {
                $this->logger->warning('[tryhackx-homepage-blocks] advanced filters index: ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }
}
