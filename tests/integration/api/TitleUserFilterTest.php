<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * filter[title] / filter[user] (LIKE '%…%') take what was typed literally — `%`, `_`
 * and `\` included — on every database (Search\LikeContains: `escape '!'`; SQLite has
 * no default escape character, MySQL/PostgreSQL treat a backslash as one).
 */
class TitleUserFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');

        $now = Carbon::now();
        $d = fn (int $id, string $title, int $user) => [
            'id' => $id, 'title' => $title, 'user_id' => $user, 'created_at' => $now, 'last_posted_at' => $now,
            'comment_count' => 1, 'last_post_number' => 1,
        ];

        $this->prepareDatabase([
            User::class => [
                ['id' => 3, 'username' => 'per_cent', 'email' => 'per_cent@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'perXcent', 'email' => 'perxcent@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                $d(1, 'Back\\slash Movie (2020)', 3),
                $d(2, 'Backslash Movie (2020)', 3),
                $d(3, '100% Wolf (2020)', 3),
                $d(4, '1000 Wolf (2020)', 4),
                $d(5, 'snake_case notes', 4),
                $d(6, 'snakeXcase notes', 4),
                $d(7, 'Wow! Such title', 4),
                $d(8, 'Wow Such title', 4),
            ],
        ]);
    }

    /** Ids the discussion list returns for this filter (as a guest). */
    protected function ids(array $filter): array
    {
        $response = $this->send($this->request('GET', '/api/discussions')->withQueryParams(['filter' => $filter]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $ids = array_map(fn ($r) => (int) $r['id'], json_decode((string) $response->getBody(), true)['data']);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function wildcards_and_the_escape_character_are_literal_in_a_title_search(): void
    {
        $this->assertSame([3], $this->ids(['title' => '100%']), '% is not a wildcard');
        $this->assertSame([5], $this->ids(['title' => 'e_c']), '_ is not a wildcard');
        $this->assertSame([1], $this->ids(['title' => 'k\\s']), 'a backslash is just a character');
        $this->assertSame([7], $this->ids(['title' => 'Wow!']), 'nor is the escape character itself');
        $this->assertSame([1, 2], $this->ids(['title' => 'movie']), 'case-insensitive substring as before');
    }

    #[Test]
    public function wildcards_are_literal_in_a_user_search(): void
    {
        $this->assertSame([1, 2, 3], $this->ids(['user' => 'r_c']));
        $this->assertSame([4, 5, 6, 7, 8], $this->ids(['user' => 'rXc']));
    }

    #[Test]
    public function fewer_than_three_characters_never_filter(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $this->ids(['title' => '1%']));
    }
}
