<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\HomepageBlocks\Meta\MetaIndexer;

/**
 * The index follows the forum through the real API (events fire only there, not for
 * rows inserted by prepareDatabase): start → edit the first post → rename → delete.
 */
class AdvancedFiltersIndexingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');
        $this->setting('tryhackx-homepage-blocks.adv_enabled', '1');
        $this->setting('tryhackx-homepage-blocks.adv_f_size', '1');
        $this->setting('tryhackx-homepage-blocks.adv_f_type', '1');
        // Let the admin start a discussion without picking tags.
        $this->setting('flarum-tags.min_primary_tags', '0');
        $this->setting('flarum-tags.min_secondary_tags', '0');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
    }

    protected function row(int $discussionId): ?object
    {
        return $this->database()->table(MetaIndexer::TABLE)->where('discussion_id', $discussionId)->first();
    }

    protected function startDiscussion(string $title, string $content): array
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => $content]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true)['data'];

        return [(int) $data['id'], (int) $data['relationships']['firstPost']['data']['id']];
    }

    #[Test]
    public function the_index_follows_start_edit_rename_and_delete(): void
    {
        [$id, $postId] = $this->startDiscussion('Utopia (2020) [26 GiB]', "[b]Serial:[/b] TV Series\n[b]Rozmiar:[/b] 26 GiB, [b]Czas trwania:[/b] \\~51 min");

        $row = $this->row($id);
        $this->assertNotNull($row, 'indexed when started');
        $this->assertSame([26 * 1024 ** 3, 1, 51], [(int) $row->size_bytes, (int) $row->is_series, (int) $row->duration_min]);

        // Editing the first post re-reads it.
        $response = $this->send($this->request('PATCH', "/api/posts/$postId", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['content' => '[b]Rozmiar:[/b] 30 GiB']]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(30 * 1024 ** 3, (int) $this->row($id)->size_bytes);

        // Renaming re-reads the title (year).
        $response = $this->send($this->request('PATCH', "/api/discussions/$id", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => (string) $id, 'attributes' => ['title' => 'Utopia (2013 - 2014) [30 GiB]']]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([2013, 2014], [(int) $this->row($id)->year_start, (int) $this->row($id)->year_end]);

        // Deleting removes the row.
        $response = $this->send($this->request('DELETE', "/api/discussions/$id", ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($this->row($id));
    }

    #[Test]
    public function a_reply_does_not_touch_the_index(): void
    {
        [$id] = $this->startDiscussion('Film (2019) [5 GB]', 'Rozmiar: 5 GB');
        $before = $this->row($id);

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Rozmiar: 99 GB'],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $id]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame((int) $before->size_bytes, (int) $this->row($id)->size_bytes);
    }

    #[Test]
    public function nothing_is_indexed_while_no_index_filter_is_enabled(): void
    {
        $this->setting('tryhackx-homepage-blocks.adv_f_size', '0');
        $this->setting('tryhackx-homepage-blocks.adv_f_type', '0');

        [$id] = $this->startDiscussion('Film (2019) [5 GB]', 'Rozmiar: 5 GB');

        $this->assertNull($this->row($id));
    }
}
