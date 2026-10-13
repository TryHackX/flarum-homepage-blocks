<?php

namespace TryHackX\HomepageBlocks\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Heavy facet requests (title/user filter) go through the points limiter like the
 * search itself — and a pre-flight with `facets=1` covers both the list and the
 * facets request, so the UI pays once per search.
 */
class FacetsRateLimitTest extends TestCase
{
    protected string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-homepage-blocks');

        foreach ([
            'ratelimit_enabled' => '1',
            'ratelimit_start' => '6',
            'ratelimit_cost_search' => '3',
            'ratelimit_guest_extra' => '0',
            'ratelimit_refill_amount' => '1',
            'ratelimit_refill_seconds' => '3600',
            'ratelimit_block_seconds' => '60',
        ] as $key => $value) {
            $this->setting('tryhackx-homepage-blocks.' . $key, $value);
        }

        // A fresh address per run: the limiter's file store outlives a test.
        $this->ip = '198.51.100.' . random_int(1, 254);
    }

    protected function call(string $method, string $path, array $query = []): ResponseInterface
    {
        $request = (new ServerRequest(['REMOTE_ADDR' => $this->ip], [], $path, $method))
            ->withQueryParams($query)
            ->withAttribute('bypassCsrfToken', true);

        return $this->send($request);
    }

    protected function heavyFacets(): ResponseInterface
    {
        return $this->call('GET', '/api/tryhackx/homepage/facets', ['filter' => ['title' => 'matrix']]);
    }

    #[Test]
    public function a_pre_flight_with_facets_covers_the_list_and_the_counts(): void
    {
        $this->assertSame(200, $this->call('POST', '/api/tryhackx/homepage/points/check', ['action' => 'search', 'facets' => '1'])->getStatusCode());

        // Both requests of the search ride on the pre-flight's grace…
        $this->assertSame(200, $this->call('GET', '/api/discussions', ['filter' => ['title' => 'matrix']])->getStatusCode());
        $this->assertSame(200, $this->heavyFacets()->getStatusCode());

        // …so 3 of the 6 points are left: one more direct heavy request fits, the next is blocked.
        $this->assertSame(200, $this->heavyFacets()->getStatusCode());
        $this->assertSame(429, $this->heavyFacets()->getStatusCode());
    }

    #[Test]
    public function direct_heavy_requests_are_charged(): void
    {
        $this->assertSame(200, $this->heavyFacets()->getStatusCode());
        $this->assertSame(200, $this->heavyFacets()->getStatusCode());

        $blocked = $this->heavyFacets();
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame('rate_limited', json_decode((string) $blocked->getBody(), true)['error']);
    }

    #[Test]
    public function light_requests_are_never_charged(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->call('GET', '/api/tryhackx/homepage/facets', ['filter' => ['dateInterval' => '1w'], 'category' => 'x']);
            $this->assertSame(200, $response->getStatusCode());
        }
    }
}
