<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Search\FacetCounter;
use TryHackX\HomepageBlocks\Security\RateLimiter;

/**
 * GET /api/tryhackx/homepage/facets — live counts for the Advanced Filters
 * "Category" / "Resolution" lists under the current filters ({@see FacetCounter}).
 *
 * Query (same values the panel sends to /api/discussions):
 *   filter[title], filter[user], filter[dateInterval], filter[ratingInterval],
 *   category=<tag slug|all>, resolution=<tag slug|all>
 *
 * Response:
 *   { category:   { total, counts: { tagId: n } },
 *     resolution: { total, counts: { tagId: n } } }
 *
 * With a heavy title/user filter this is as expensive as the search itself, so it
 * goes through the same rate limiter as SearchRateLimitMiddleware: it consumes
 * the grace a pre-flight granted (`/points/check?action=search&facets=1` grants
 * one for the list and one for this request), or is charged like a direct hit.
 */
class FacetsController implements RequestHandlerInterface
{
    use BuildsGuardResponse;

    public function __construct(
        protected FacetCounter $facets,
        protected RateLimiter $limiter
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filters = FacetCounter::normaliseFilters($params['filter'] ?? []);

        if (FacetCounter::isHeavy($filters)) {
            if ($error = $this->guardError($this->limiter->verifyRequest($request))) {
                return $error;
            }
        }

        return new JsonResponse($this->facets->count(
            RequestUtil::getActor($request),
            $filters,
            FacetCounter::normaliseSlug($params['category'] ?? null),
            FacetCounter::normaliseSlug($params['resolution'] ?? null)
        ));
    }
}
