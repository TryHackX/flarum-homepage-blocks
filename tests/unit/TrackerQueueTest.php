<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\HomepageBlocks\Service\TrackerQueue;

class TrackerQueueTest extends TestCase
{
    #[Test]
    public function the_same_post_twice_stays_one_post(): void
    {
        $this->assertSame([TrackerQueue::POST, 7], TrackerQueue::merge(TrackerQueue::POST, 7, TrackerQueue::POST, 7));
    }

    #[Test]
    public function two_posts_of_one_discussion_need_the_whole_discussion(): void
    {
        $this->assertSame([TrackerQueue::ALL, null], TrackerQueue::merge(TrackerQueue::POST, 7, TrackerQueue::POST, 8));
    }

    #[Test]
    public function a_description_change_keeps_the_post_it_joins(): void
    {
        $this->assertSame([TrackerQueue::POST, 7], TrackerQueue::merge(TrackerQueue::DESCRIBE, null, TrackerQueue::POST, 7));
        $this->assertSame([TrackerQueue::POST, 7], TrackerQueue::merge(TrackerQueue::POST, 7, TrackerQueue::DESCRIBE, null));
        $this->assertSame([TrackerQueue::DESCRIBE, null], TrackerQueue::merge(TrackerQueue::DESCRIBE, 3, TrackerQueue::DESCRIBE, null));
    }

    #[Test]
    public function the_whole_discussion_wins_over_everything(): void
    {
        foreach ([[TrackerQueue::POST, 7], [TrackerQueue::DESCRIBE, null], [TrackerQueue::ALL, null]] as [$reason, $post]) {
            $this->assertSame([TrackerQueue::ALL, null], TrackerQueue::merge(TrackerQueue::ALL, null, $reason, $post));
            $this->assertSame([TrackerQueue::ALL, null], TrackerQueue::merge($reason, $post, TrackerQueue::ALL, null));
        }
    }

    #[Test]
    public function a_post_without_an_id_or_an_unknown_reason_means_the_whole_discussion(): void
    {
        $this->assertSame([TrackerQueue::ALL, null], TrackerQueue::merge(TrackerQueue::POST, null, TrackerQueue::DESCRIBE, null));
        $this->assertSame([TrackerQueue::ALL, null], TrackerQueue::merge('something', 4, TrackerQueue::DESCRIBE, null));
    }

    #[Test]
    public function retries_back_off_up_to_six_hours(): void
    {
        $this->assertSame([60, 60, 120, 300, 600, 1800, 3600, 7200, 21600, 21600], array_map(fn ($n) => TrackerQueue::backoff($n), range(0, 9)));
    }

    #[Test]
    public function a_longer_retry_after_is_honoured_but_capped(): void
    {
        $this->assertSame(900, TrackerQueue::backoff(1, 900));
        $this->assertSame(120, TrackerQueue::backoff(2, 30));
        $this->assertSame(21600, TrackerQueue::backoff(1, 86400));
    }
}
