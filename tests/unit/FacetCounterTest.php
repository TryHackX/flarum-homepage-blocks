<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\HomepageBlocks\Search\FacetCounter;

class FacetCounterTest extends TestCase
{
    #[Test]
    public function only_the_panels_filters_survive_normalisation(): void
    {
        $this->assertSame(
            ['title' => 'matrix', 'dateInterval' => '1w'],
            FacetCounter::normaliseFilters([
                'title' => '  matrix ',
                'user' => '',
                'dateInterval' => '1w',
                'ratingInterval' => '0',      // "All" in the UI
                'author' => 'admin',          // not a panel filter
                'tag' => ['x'],
                'q' => 'search',
            ])
        );

        $this->assertSame([], FacetCounter::normaliseFilters('nope'));
        $this->assertSame([], FacetCounter::normaliseFilters(['title' => ['array']]));
        $this->assertSame(100, mb_strlen(FacetCounter::normaliseFilters(['title' => str_repeat('a', 500)])['title']));
    }

    #[Test]
    public function all_and_malformed_slugs_mean_no_pick(): void
    {
        $this->assertNull(FacetCounter::normaliseSlug('all'));
        $this->assertNull(FacetCounter::normaliseSlug(''));
        $this->assertNull(FacetCounter::normaliseSlug(null));
        $this->assertNull(FacetCounter::normaliseSlug(['pl']));
        $this->assertNull(FacetCounter::normaliseSlug(str_repeat('x', 101)));
        $this->assertSame('full-hd-1080p', FacetCounter::normaliseSlug('full-hd-1080p'));
    }

    #[Test]
    public function heavy_means_a_title_or_user_of_three_characters_or_more(): void
    {
        $this->assertFalse(FacetCounter::isHeavy([]));
        $this->assertFalse(FacetCounter::isHeavy(['title' => 'ab', 'dateInterval' => '1w']));
        $this->assertTrue(FacetCounter::isHeavy(['title' => 'abc']));
        $this->assertTrue(FacetCounter::isHeavy(['user' => 'żół']));
    }
}
