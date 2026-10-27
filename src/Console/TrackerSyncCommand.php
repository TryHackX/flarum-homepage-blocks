<?php

namespace TryHackX\HomepageBlocks\Console;

use Flarum\Console\AbstractCommand;
use TryHackX\HomepageBlocks\Service\TrackerQueue;
use TryHackX\HomepageBlocks\Service\TrackerSync;

class TrackerSyncCommand extends AbstractCommand
{
    public function __construct(
        protected TrackerSync $sync,
        protected TrackerQueue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('homepage-blocks:tracker-sync')
            ->setDescription('Send the topics waiting in the tracker queue (magnets and descriptions) to the tracker');
    }

    protected function fire(): int
    {
        $r = $this->sync->drain(50.0);
        $s = $this->queue->stats();
        $this->info(sprintf(
            '%s %d sent, %d to retry; %d topics in the queue.',
            $r['ran'] ? 'Done:' : 'Nothing run (the sync is off, not configured or already running):',
            $r['synced'],
            $r['retried'],
            $s['pending']
        ));

        return 0;
    }
}
