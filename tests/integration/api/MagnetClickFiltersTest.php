<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\HomepageBlocks\Advanced\AdvancedSettings;
use TryHackX\HomepageBlocks\Search\Advanced\AbstractAdvancedFilter;
use TryHackX\HomepageBlocks\Search\Advanced\HasMagnetFilter;
use TryHackX\HomepageBlocks\Search\Advanced\MagnetClickedRangeFilter;
use TryHackX\HomepageBlocks\Search\Advanced\MagnetClicksRangeFilter;
use TryHackX\HomepageBlocks\Search\Advanced\MagnetTopClicksRangeFilter;

/**
 * The magnet-click ranges against tryhackx/flarum-magnet-link's click log. That
 * extension isn't installed here, so its `magnet_clicks` table is created by the test
 * and the filters are applied directly (with an extension manager that reports
 * magnet-link as enabled) to the same visibility-scoped query the discussion list uses.
 *
 *   1  magnet A × 3 clicks + magnet B × 1 click   (total 4, top 3), last click 1 day ago
 *   2  magnet C × 2 clicks                         (total 2, top 2), last click 20 days ago
 *   3  no clicks
 */
class MagnetClickFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');
        foreach (['adv_enabled', 'adv_f_clicks', 'adv_f_clicks_top', 'adv_f_clicked', 'adv_f_magnet'] as $key) {
            $this->setting('tryhackx-homepage-blocks.' . $key, '1');
        }

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Popular', 'user_id' => 1, 'comment_count' => 1, 'first_post_id' => 1],
                ['id' => 2, 'title' => 'Some', 'user_id' => 1, 'comment_count' => 1, 'first_post_id' => 2],
                ['id' => 3, 'title' => 'None', 'user_id' => 1, 'comment_count' => 1, 'first_post_id' => 3],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t>a</t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t>b</t>'],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t>c</t>'],
            ],
        ]);
    }

    protected function seedClicks(): void
    {
        $schema = $this->database()->getSchemaBuilder();
        if (! $schema->hasTable('magnet_clicks')) {
            $schema->create('magnet_clicks', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('magnet_link_id');
                $t->string('ip_address', 45)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('post_id');
                $t->timestamp('click_time')->nullable();
            });
        }
        // On MySQL/MariaDB the CREATE TABLE above commits the test's transaction, so rows a
        // previous test inserted are still there — start from an empty log every time.
        $this->database()->table('magnet_clicks')->delete();
        $now = Carbon::now();
        $rows = [];
        foreach ([[1, 1, 1], [1, 1, 2], [1, 1, 3], [2, 1, 1], [3, 2, 20], [3, 2, 25]] as [$magnet, $post, $daysAgo]) {
            $rows[] = ['magnet_link_id' => $magnet, 'post_id' => $post, 'click_time' => $now->copy()->subDays($daysAgo)];
        }
        $this->database()->table('magnet_clicks')->insert($rows);
    }

    /** Apply one filter the way the discussion searcher does. */
    protected function ids(string $class, string $value): array
    {
        $container = $this->app()->getContainer();
        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('isEnabled')->willReturn(true);
        $adv = new AdvancedSettings($container->make(SettingsRepositoryInterface::class), $extensions);
        /** @var AbstractAdvancedFilter $filter */
        $filter = $class === HasMagnetFilter::class ? new $class($adv) : new $class($adv, $this->database());

        $actor = User::find(1);
        $state = new DatabaseSearchState($actor, false);
        $state->setQuery(Discussion::whereVisibleTo($actor));
        $filter->filter($state, $value, false);

        $ids = $state->getQuery()->pluck('discussions.id')->map(fn ($id) => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    #[Test]
    public function total_clicks(): void
    {
        $this->seedClicks();

        $this->assertSame([1], $this->ids(MagnetClicksRangeFilter::class, '3..'));
        $this->assertSame([1, 2], $this->ids(MagnetClicksRangeFilter::class, '2..4'));
        $this->assertSame([2, 3], $this->ids(MagnetClicksRangeFilter::class, '..2'), 'a range from 0 includes never-clicked');
        $this->assertSame([1, 2, 3], $this->ids(MagnetClicksRangeFilter::class, '0..'), '"0.." admits everything');
    }

    #[Test]
    public function with_or_without_a_magnet_link(): void
    {
        // magnet-link's denormalised flag on discussions (created here: the extension isn't installed).
        $schema = $this->database()->getSchemaBuilder();
        if (! $schema->hasColumn('discussions', 'has_magnet_links')) {
            $schema->table('discussions', fn (Blueprint $t) => $t->boolean('has_magnet_links')->default(false));
        }
        $this->database()->table('discussions')->whereIn('id', [1, 2])->update(['has_magnet_links' => 1]);

        $this->assertSame([1, 2], $this->ids(HasMagnetFilter::class, 'yes'));
        $this->assertSame([3], $this->ids(HasMagnetFilter::class, 'no'));
        $this->assertSame([1, 2], $this->ids(HasMagnetFilter::class, '1'));
        $this->assertSame([1, 2, 3], $this->ids(HasMagnetFilter::class, 'maybe'), 'unknown value → ignored');
    }

    #[Test]
    public function top_magnet_clicks(): void
    {
        $this->seedClicks();

        $this->assertSame([1], $this->ids(MagnetTopClicksRangeFilter::class, '3..'));
        $this->assertSame([2], $this->ids(MagnetTopClicksRangeFilter::class, '2..2'));
        $this->assertSame([2, 3], $this->ids(MagnetTopClicksRangeFilter::class, '0..2'));
    }

    #[Test]
    public function last_click_date(): void
    {
        $this->seedClicks();

        $weekAgo = Carbon::now()->subWeek()->getTimestamp();
        $this->assertSame([1], $this->ids(MagnetClickedRangeFilter::class, $weekAgo . '..'));
        $this->assertSame([2], $this->ids(MagnetClickedRangeFilter::class, '..' . $weekAgo), 'never clicked → no match');
    }
}
