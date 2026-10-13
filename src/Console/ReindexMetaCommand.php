<?php

namespace TryHackX\HomepageBlocks\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * php flarum homepage-blocks:reindex [--only-stale] [--chunk=500]
 *
 * Rebuilds the Advanced Filters index (the same work as the admin "Rebuild index"
 * button, without the request time limit).
 */
class ReindexMetaCommand extends AbstractCommand
{
    public function __construct(
        protected MetaIndexer $indexer,
        protected AdvancedSettings $adv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('homepage-blocks:reindex')
            ->setDescription('Rebuild the Advanced Filters index (size, series, seasons, year, … read from each discussion)')
            ->addOption('only-stale', null, InputOption::VALUE_NONE, 'Only discussions that are missing or were indexed with other parser settings')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Discussions per batch', '500');
    }

    protected function fire(): int
    {
        $chunk = max(1, min(MetaIndexer::MAX_BATCH, (int) $this->input->getOption('chunk')));
        $onlyStale = (bool) $this->input->getOption('only-stale');
        if (! $this->adv->indexingEnabled()) {
            $this->info('Note: no index-based filter or sort is enabled — the index is built anyway, but not kept current until one is.');
        }

        $cursor = 0;
        $total = 0;
        $t0 = microtime(true);
        do {
            $r = $this->indexer->indexBatch($cursor, $chunk, $onlyStale);
            $cursor = $r['next_cursor'];
            $total += $r['processed'];
            $this->info(sprintf('… %d discussions (up to id %d)', $total, $cursor));
        } while (! $r['done']);

        $s = $this->indexer->status();
        $this->info(sprintf(
            'Done in %.1f s: %d indexed of %d discussions — %d with a size, %d series, %d movies, %d missing, %d stale.',
            microtime(true) - $t0, $s['indexed'], $s['total'], $s['with_size'], $s['series'], $s['movies'], $s['missing'], $s['stale']
        ));

        return 0;
    }
}
