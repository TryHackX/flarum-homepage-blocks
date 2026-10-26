<?php

namespace TryHackX\HomepageBlocks\Tests\unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\HomepageBlocks\Http\TrackerWhitelistClient;
use TryHackX\HomepageBlocks\Service\DescriptionPayload;

class DescriptionPayloadTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef01234567';
    private const LIMIT = 500000;

    private function hash(int $i): string
    {
        return str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
    }

    private function topic(int $postId, string $content = 'text', int $hashes = 1): array
    {
        $list = [];
        for ($i = 0; $i < $hashes; $i++) $list[] = $this->hash($postId * 100 + $i);
        return DescriptionPayload::topics($postId, $postId, null, null, new \DateTimeImmutable('@1793000000'), $content, self::LIMIT, $list)[0];
    }

    #[Test]
    public function a_topic_carries_every_field_of_the_contract(): void
    {
        $content = "Zażółć gęślą jaźń\n[b]Opis[/b] magnet:?xt=urn:btih:" . self::HASH;
        $topics = DescriptionPayload::topics(
            160,
            162,
            'https://forum.example.org/d/160-some-slug',
            ['id' => '7', 'name' => 'kasia'],
            new \DateTimeImmutable('2026-10-27 10:11:12', new \DateTimeZone('UTC')),
            $content,
            self::LIMIT,
            [self::HASH],
        );

        $this->assertSame([[
            'discussion_id' => 160,
            'post_id' => 162,
            'url' => 'https://forum.example.org/d/160-some-slug',
            'author' => ['id' => '7', 'name' => 'kasia'],
            'edited_at' => '2026-10-27T10:11:12+00:00',
            'format' => 'flarum',
            'content' => $content,
            'chars' => mb_strlen($content),
            'hashes' => [self::HASH],
        ]], $topics);
    }

    #[Test]
    public function a_deleted_author_is_null_and_an_overlong_url_is_left_out(): void
    {
        $topic = DescriptionPayload::topics(1, 1, 'https://forum.example.org/d/1-' . str_repeat('a', 480), null, null, 'x', self::LIMIT, [self::HASH])[0];

        $this->assertArrayNotHasKey('url', $topic);
        $this->assertNull($topic['author']);
        $this->assertNull($topic['edited_at']);
    }

    #[Test]
    public function content_over_the_forum_limit_is_sent_as_null_with_its_length(): void
    {
        foreach ([63000, self::LIMIT] as $limit) {
            $atLimit = DescriptionPayload::topics(1, 1, null, null, null, str_repeat('ą', $limit), $limit, [self::HASH])[0];
            $overLimit = DescriptionPayload::topics(1, 1, null, null, null, str_repeat('ą', $limit + 1), $limit, [self::HASH])[0];

            $this->assertSame($limit, $atLimit['chars']);
            $this->assertSame(str_repeat('ą', $limit), $atLimit['content']);
            $this->assertNull($overLimit['content']);
            $this->assertSame($limit + 1, $overLimit['chars']);
        }
    }

    #[Test]
    public function the_request_body_carries_the_limit_at_the_top_level(): void
    {
        $topics = [$this->topic(1), $this->topic(2)];

        $this->assertSame(['topics' => $topics, 'max_chars' => 63000], DescriptionPayload::body($topics, 63000));
        $this->assertSame(['topics' => [], 'max_chars' => self::LIMIT], DescriptionPayload::body([], self::LIMIT));
    }

    #[Test]
    public function hashes_are_deduplicated_and_split_into_topics_of_twenty(): void
    {
        $hashes = [];
        for ($i = 0; $i < 45; $i++) $hashes[] = $this->hash($i);
        $hashes[] = $this->hash(3);

        $topics = DescriptionPayload::topics(5, 9, null, null, null, 'x', self::LIMIT, $hashes);

        $this->assertSame([20, 20, 5], array_map(fn ($t) => count($t['hashes']), $topics));
        $this->assertSame([9, 9, 9], array_column($topics, 'post_id'));
        $this->assertSame(array_slice($hashes, 0, 45), array_merge(...array_column($topics, 'hashes')));
    }

    #[Test]
    public function a_post_without_eligible_hashes_has_no_topic(): void
    {
        $this->assertSame([], DescriptionPayload::topics(1, 1, null, null, null, 'x', self::LIMIT, []));
    }

    #[Test]
    public function calls_hold_at_most_twenty_five_topics(): void
    {
        $topics = [];
        for ($i = 1; $i <= 60; $i++) $topics[] = $this->topic($i);

        $calls = DescriptionPayload::pack($topics, self::LIMIT);

        $this->assertSame([25, 25, 10], array_map('count', $calls));
        $this->assertSame($topics, array_merge(...$calls));
    }

    #[Test]
    public function calls_stay_under_the_byte_cap_and_are_filled_up_to_it(): void
    {
        $topics = [];
        for ($i = 1; $i <= 7; $i++) $topics[] = $this->topic($i, str_repeat('ą', 400000 + $i * 1000));

        $calls = DescriptionPayload::pack($topics, self::LIMIT);

        $this->assertSame($topics, array_merge(...$calls));
        foreach ($calls as $n => $call) {
            $body = json_encode(DescriptionPayload::body($call, self::LIMIT), TrackerWhitelistClient::JSON_FLAGS);
            $this->assertLessThanOrEqual(DescriptionPayload::MAX_BYTES, strlen($body));
            if (isset($calls[$n + 1])) {
                $more = json_encode(DescriptionPayload::body(array_merge($call, [$calls[$n + 1][0]]), self::LIMIT), TrackerWhitelistClient::JSON_FLAGS);
                $this->assertGreaterThan(DescriptionPayload::MAX_BYTES, strlen($more));
            }
        }
    }

    #[Test]
    public function the_byte_estimate_matches_the_request_body(): void
    {
        $topics = [$this->topic(1, "a\"b\\c\nż"), $this->topic(2, '€'), $this->topic(3)];
        foreach ([63000, self::LIMIT, 16000000] as $limit) {
            $size = strlen(json_encode(DescriptionPayload::body($topics, $limit), TrackerWhitelistClient::JSON_FLAGS));

            $this->assertCount(1, DescriptionPayload::pack($topics, $limit, 25, $size));
            $this->assertCount(2, DescriptionPayload::pack($topics, $limit, 25, $size - 1));
        }
    }

    #[Test]
    public function a_single_topic_over_the_cap_still_gets_a_call(): void
    {
        $calls = DescriptionPayload::pack([$this->topic(1, str_repeat('x', 2000))], self::LIMIT, 25, 100);

        $this->assertCount(1, $calls);
    }

    #[Test]
    public function counters_add_up_the_statuses_of_a_summary(): void
    {
        $summary = ['created' => 1, 'queued' => 2, 'updated' => 3, 'replaced' => 4, 'proposed' => 5, 'kept' => 6,
            'unchanged' => 7, 'too_long' => 8, 'invalid' => 9, 'skipped' => 10];

        $once = DescriptionPayload::tally($summary);
        $twice = DescriptionPayload::tally($summary, $once);

        $this->assertSame(['described' => 15, 'unchanged' => 7, 'kept' => 6, 'too_long' => 8, 'invalid' => 9, 'skipped' => 10], $once);
        $this->assertSame(['described' => 30, 'unchanged' => 14, 'kept' => 12, 'too_long' => 16, 'invalid' => 18, 'skipped' => 20], $twice);
        $this->assertSame(['described' => 0, 'unchanged' => 0, 'kept' => 0, 'too_long' => 0, 'invalid' => 0, 'skipped' => 0], DescriptionPayload::tally([]));
    }

    #[Test]
    public function sent_through_names_the_last_post_whose_topics_all_went_out(): void
    {
        $topics = [['post_id' => 10], ['post_id' => 10], ['post_id' => 12], ['post_id' => 15]];

        $this->assertSame(7, DescriptionPayload::sentThrough($topics, 0, 7));
        $this->assertSame(7, DescriptionPayload::sentThrough($topics, 1, 7));
        $this->assertSame(10, DescriptionPayload::sentThrough($topics, 2, 7));
        $this->assertSame(12, DescriptionPayload::sentThrough($topics, 3, 7));
        $this->assertSame(15, DescriptionPayload::sentThrough($topics, 4, 7));
    }
}
