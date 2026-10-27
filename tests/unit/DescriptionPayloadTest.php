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
    public function a_topic_carries_the_discussion_tags_only_when_the_tracker_takes_them(): void
    {
        $tags = [['slug' => 'full-hd-1080p', 'name' => 'Full HD (1080p)', 'color' => null, 'parent' => null]];
        $without = DescriptionPayload::topics(1, 1, null, null, null, 'x', self::LIMIT, [self::HASH])[0];
        $with = DescriptionPayload::topics(1, 1, null, null, null, 'x', self::LIMIT, [self::HASH], DescriptionPayload::MAX_HASHES, $tags)[0];
        $none = DescriptionPayload::topics(1, 1, null, null, null, 'x', self::LIMIT, [self::HASH], DescriptionPayload::MAX_HASHES, [])[0];
        $this->assertArrayNotHasKey('tags', $without);
        $this->assertSame($tags, $with['tags']);
        $this->assertSame([], $none['tags']);
        $this->assertNotSame(DescriptionPayload::fingerprint([$without]), DescriptionPayload::fingerprint([$with]));
        $this->assertNotSame(DescriptionPayload::fingerprint([$with]), DescriptionPayload::fingerprint([$none]));
    }

    #[Test]
    public function the_tag_list_puts_primary_tags_first_once_each_without_empty_colours(): void
    {
        $list = DescriptionPayload::tagList([
            ['slug' => 'pl', 'name' => 'PL', 'color' => '', 'position' => null, 'parent' => null],
            ['slug' => 'dramatyczne', 'name' => 'Dramatyczne', 'color' => '#34495e', 'position' => 8, 'parent' => null],
            ['slug' => 'akcji', 'name' => 'Akcji', 'color' => '#e74c3c', 'position' => 1, 'parent' => null],
            ['slug' => 'bluray', 'name' => 'BluRay', 'color' => null, 'position' => null, 'parent' => 'zrodlo'],
            ['slug' => 'pl', 'name' => 'PL again', 'color' => null, 'position' => null, 'parent' => null],
            ['slug' => '', 'name' => 'Nothing'],
        ]);
        $this->assertSame([
            ['slug' => 'akcji', 'name' => 'Akcji', 'color' => '#e74c3c', 'parent' => null],
            ['slug' => 'dramatyczne', 'name' => 'Dramatyczne', 'color' => '#34495e', 'parent' => null],
            ['slug' => 'bluray', 'name' => 'BluRay', 'color' => null, 'parent' => 'zrodlo'],
            ['slug' => 'pl', 'name' => 'PL', 'color' => null, 'parent' => null],
        ], $list);
        $this->assertCount(DescriptionPayload::MAX_TAGS, DescriptionPayload::tagList(array_map(fn ($i) => ['slug' => 't' . $i, 'name' => 'T' . $i], range(1, 40))));
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

        $this->assertSame(['topics' => $topics, 'max_chars' => 63000, 'copy_images' => false], DescriptionPayload::body($topics, 63000, false));
        $this->assertSame(['topics' => [], 'max_chars' => self::LIMIT, 'copy_images' => true], DescriptionPayload::body([], self::LIMIT, true));
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

        $calls = DescriptionPayload::pack($topics, self::LIMIT, false);

        $this->assertSame([25, 25, 10], array_map('count', $calls));
        $this->assertSame($topics, array_merge(...$calls));
    }

    #[Test]
    public function calls_stay_under_the_byte_cap_and_are_filled_up_to_it(): void
    {
        $topics = [];
        for ($i = 1; $i <= 7; $i++) $topics[] = $this->topic($i, str_repeat('ą', 400000 + $i * 1000));

        $calls = DescriptionPayload::pack($topics, self::LIMIT, false);

        $this->assertSame($topics, array_merge(...$calls));
        foreach ($calls as $n => $call) {
            $body = json_encode(DescriptionPayload::body($call, self::LIMIT, false), TrackerWhitelistClient::JSON_FLAGS);
            $this->assertLessThanOrEqual(DescriptionPayload::MAX_BYTES, strlen($body));
            if (isset($calls[$n + 1])) {
                $more = json_encode(DescriptionPayload::body(array_merge($call, [$calls[$n + 1][0]]), self::LIMIT, false), TrackerWhitelistClient::JSON_FLAGS);
                $this->assertGreaterThan(DescriptionPayload::MAX_BYTES, strlen($more));
            }
        }
    }

    #[Test]
    public function the_byte_estimate_matches_the_request_body(): void
    {
        $topics = [$this->topic(1, "a\"b\\c\nż"), $this->topic(2, '€'), $this->topic(3)];
        foreach ([63000, self::LIMIT, 16000000] as $limit) {
            foreach ([false, true] as $copyImages) {
                $size = strlen(json_encode(DescriptionPayload::body($topics, $limit, $copyImages), TrackerWhitelistClient::JSON_FLAGS));

                $this->assertCount(1, DescriptionPayload::pack($topics, $limit, $copyImages, 25, $size));
                $this->assertCount(2, DescriptionPayload::pack($topics, $limit, $copyImages, 25, $size - 1));
            }
        }
    }

    #[Test]
    public function a_single_topic_over_the_cap_still_gets_a_call(): void
    {
        $calls = DescriptionPayload::pack([$this->topic(1, str_repeat('x', 2000))], self::LIMIT, false, 25, 100);

        $this->assertCount(1, $calls);
    }

    #[Test]
    public function counters_split_the_statuses_of_a_summary(): void
    {
        $summary = ['created' => 1, 'queued' => 2, 'updated' => 3, 'replaced' => 4, 'proposed' => 5, 'kept' => 6,
            'unchanged' => 7, 'too_long' => 8, 'invalid' => 9, 'declined' => 15, 'skipped' => 10, 'withdrawn' => 11, 'released' => 12, 'error' => 13, 'too_big' => 14];

        $once = DescriptionPayload::tally($summary);
        $twice = DescriptionPayload::tally($summary, $once);

        $this->assertSame(['published' => 8, 'waiting' => 2, 'proposed' => 5, 'unchanged' => 7, 'kept' => 6, 'withdrawn' => 23,
            'too_long' => 22, 'invalid' => 9, 'declined' => 15, 'skipped' => 10, 'errors' => 13], $once);
        $this->assertSame(16, $twice['published']);
        $this->assertSame(46, $twice['withdrawn']);
        $this->assertSame(array_fill_keys(array_keys(DescriptionPayload::COUNTERS), 0), DescriptionPayload::tally([]));
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
        $this->assertSame(10, DescriptionPayload::sentThrough([10, 10, 12, 15], 2, 7));
    }

    #[Test]
    public function hashes_are_split_by_the_limit_the_tracker_reported(): void
    {
        $hashes = [];
        for ($i = 0; $i < 9; $i++) $hashes[] = $this->hash($i);

        $this->assertSame([4, 4, 1], array_map(fn ($t) => count($t['hashes']), DescriptionPayload::topics(1, 2, null, null, null, 'x', self::LIMIT, $hashes, 4)));
        $this->assertSame([9], array_map(fn ($t) => count($t['hashes']), DescriptionPayload::topics(1, 2, null, null, null, 'x', self::LIMIT, $hashes)));
    }

    #[Test]
    public function a_withdrawal_carries_no_text_and_gone_only_with_it(): void
    {
        $withdraw = DescriptionPayload::hidden(5, 9, [$this->hash(1), $this->hash(1), $this->hash(2)], false);
        $gone = DescriptionPayload::hidden(5, 9, [$this->hash(3)], true);

        $this->assertSame([['discussion_id' => 5, 'post_id' => 9, 'visible' => false, 'hashes' => [$this->hash(1), $this->hash(2)]]], $withdraw);
        $this->assertSame([['discussion_id' => 5, 'post_id' => 9, 'visible' => false, 'gone' => true, 'hashes' => [$this->hash(3)]]], $gone);
        $this->assertCount(3, DescriptionPayload::hidden(5, 9, array_map(fn ($i) => $this->hash($i), range(1, 45)), true));
    }

    #[Test]
    public function a_visible_topic_is_described_and_removed_hashes_are_gone(): void
    {
        $sent = ['hashes' => [$this->hash(1), $this->hash(2), $this->hash(3)], 'visible' => true];

        $plan = DescriptionPayload::plan($sent, true, false, [$this->hash(1), $this->hash(3), $this->hash(4)], [$this->hash(1), $this->hash(4)]);

        $this->assertSame([$this->hash(1), $this->hash(4)], $plan['describe']);
        $this->assertSame([], $plan['withdraw']);
        $this->assertSame([$this->hash(2)], $plan['gone']);
        $this->assertSame([$this->hash(1), $this->hash(3), $this->hash(4)], $plan['keep']);
    }

    #[Test]
    public function a_topic_a_guest_cannot_see_withdraws_what_was_sent_visible(): void
    {
        $sent = ['hashes' => [$this->hash(1), $this->hash(2)], 'visible' => true];

        $plan = DescriptionPayload::plan($sent, false, true, [$this->hash(1), $this->hash(9)], [$this->hash(1), $this->hash(9)]);

        $this->assertSame([], $plan['describe']);
        $this->assertSame([$this->hash(1)], $plan['withdraw']);
        $this->assertSame([$this->hash(2)], $plan['gone']);
        $this->assertSame([$this->hash(1)], $plan['keep']);
    }

    #[Test]
    public function an_already_withdrawn_topic_is_not_withdrawn_again(): void
    {
        $plan = DescriptionPayload::plan(['hashes' => [$this->hash(1)], 'visible' => false], false, false, [$this->hash(1)], [$this->hash(1)]);

        $this->assertSame(['describe' => [], 'withdraw' => [], 'gone' => [], 'keep' => [$this->hash(1)]], $plan);
    }

    #[Test]
    public function an_unknown_hidden_topic_is_withdrawn_unless_it_is_private(): void
    {
        $public = DescriptionPayload::plan(null, false, false, [$this->hash(1)], [$this->hash(1)]);
        $private = DescriptionPayload::plan(null, false, true, [$this->hash(1)], [$this->hash(1)]);

        $this->assertSame([$this->hash(1)], $public['withdraw']);
        $this->assertSame(['describe' => [], 'withdraw' => [], 'gone' => [], 'keep' => []], $private);
    }

    #[Test]
    public function a_deleted_topic_releases_everything_it_sent(): void
    {
        $plan = DescriptionPayload::plan(['hashes' => [$this->hash(1), $this->hash(2)], 'visible' => false], false, false, [], []);

        $this->assertSame([$this->hash(1), $this->hash(2)], $plan['gone']);
        $this->assertSame([], $plan['keep']);
    }

    #[Test]
    public function the_fingerprint_follows_every_field(): void
    {
        $topic = $this->topic(1);

        $this->assertSame(DescriptionPayload::fingerprint([$topic]), DescriptionPayload::fingerprint([$topic]));
        $this->assertNotSame(DescriptionPayload::fingerprint([$topic]), DescriptionPayload::fingerprint([['content' => 'other'] + $topic]));
        $this->assertNotSame(DescriptionPayload::fingerprint([$topic]), DescriptionPayload::fingerprint(DescriptionPayload::hidden(1, 1, $topic['hashes'], false)));
        $this->assertSame(40, strlen(DescriptionPayload::fingerprint([])));
    }

    #[Test]
    public function the_topic_state_names_the_worst_answer_first(): void
    {
        $r = fn (string $status, bool $hidden = false, ?string $detail = null) => ['status' => $status, 'hidden' => $hidden, 'detail' => $detail, 'hash' => $this->hash(1)];

        $this->assertSame(['error', 'too_long', '600000/500000'], DescriptionPayload::topicState([$r('created'), $r('too_long', false, '600000/500000')]));
        $this->assertSame(['error', 'error', 'try again later'], DescriptionPayload::topicState([$r('other_topic'), $r('error', false, 'try again later')]));
        $this->assertSame(['error', 'other_topic', $this->hash(1)], DescriptionPayload::topicState([$r('other_topic'), $r('created')]));
        $this->assertSame(['error', 'registered_elsewhere', $this->hash(1)], DescriptionPayload::topicState([$r('registered_elsewhere')]));
        $this->assertSame(['proposed', 'proposed', null], DescriptionPayload::topicState([$r('created'), $r('proposed')]));
        $this->assertSame(['waiting', 'queued', null], DescriptionPayload::topicState([$r('unchanged'), $r('queued')]));
        $this->assertSame(['published', 'updated', null], DescriptionPayload::topicState([$r('updated'), $r('released', true)]));
        $this->assertSame(['waiting', 'unchanged', null], DescriptionPayload::topicState([$r('unchanged'), $r('stale')], 'waiting'));
        $this->assertSame(['unchanged', 'kept', null], DescriptionPayload::topicState([$r('kept')], 'error'));
        $this->assertSame(['withdrawn', 'withdrawn', null], DescriptionPayload::topicState([$r('withdrawn', true), $r('not_registered', true)]));
        $this->assertSame(['withdrawn', 'not_registered', null], DescriptionPayload::topicState([$r('not_registered', true)]));
        $this->assertSame(['error', 'invalid', $this->hash(1)], DescriptionPayload::topicState([$r('invalid', true)]));
        $this->assertSame(['declined', 'declined', null], DescriptionPayload::topicState([$r('declined')]));
        $this->assertSame(['declined', 'removed', null], DescriptionPayload::topicState([$r('removed'), $r('unchanged')]));
        $this->assertSame(['error', 'error', 'x'], DescriptionPayload::topicState([$r('declined'), $r('error', false, 'x')]));
        $this->assertContains('declined', DescriptionPayload::STATES);
    }
}
