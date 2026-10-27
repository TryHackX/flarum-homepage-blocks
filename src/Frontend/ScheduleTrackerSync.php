<?php

namespace TryHackX\HomepageBlocks\Frontend;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;
use TryHackX\HomepageBlocks\Service\TrackerSync;

class ScheduleTrackerSync
{
    public function __construct(private TrackerSync $sync)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        try {
            $this->sync->schedule(false);
        } catch (\Throwable $e) {
        }
    }
}
