<?php

namespace TryHackX\HomepageBlocks\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Security\RateLimiter;

/**
 * Twarda, SERWEROWA bramka limitera dla wyszukiwania po liście dyskusji.
 *
 * Faktyczne wyszukiwanie idzie w RDZENIOWE `GET /api/discussions` (z filtrem
 * `filter[title]` / `filter[user]` — LIKE '%…%'), którego rozszerzenie nie
 * kontroluje przez własny endpoint. Bez tego middleware klient pomijający
 * pre-flight (`POST /points/check`) — bot, curl, scraper, floder — mógłby
 * zalewać bazę ciężkimi skanami bez żadnego limitu.
 *
 * Middleware ląduje w stosie `api` PO rdzeniowym `ResolveRoute`/`PopulateWithActor`
 * (patrz {@see \Flarum\Extend\Middleware}), więc ma dostęp do `routeName`, aktora
 * i `ipAddress`. Nalicza kubełek per-IP i przy wyczerpaniu ODRZUCA żądanie (429 +
 * Retry-After) ZANIM ciężkie zapytanie dotknie bazy.
 *
 * Aby NIE naliczać podwójnie legalnego ruchu z UI (który już zapłacił w pre-flighcie),
 * {@see RateLimiter::verifyRequest()} najpierw konsumuje „grace" przyznany przez
 * {@see \TryHackX\HomepageBlocks\Api\Controller\CheckPointsController}. Ruch bez grace
 * (bezpośrednie uderzenia) jest naliczany tutaj.
 */
class SearchRateLimitMiddleware implements MiddlewareInterface
{
    use BuildsGuardResponse;

    /** Próg długości filtra — lustrzany do TitleFilter/UserFilter (krótsze i tak są ignorowane, więc bez kosztu). */
    protected const MIN_FILTER_LEN = 3;

    public function __construct(
        protected RateLimiter $limiter
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isHeavySearch($request)) {
            $result = $this->limiter->verifyRequest($request);
            if ($error = $this->guardError($result)) {
                // 429 zanim rdzeniowy kontroler odpali skan LIKE po całej tabeli.
                return $error;
            }
        }

        return $handler->handle($request);
    }

    /**
     * Czy to KOLEKCJA dyskusji (lista/wyszukiwanie) z ciężkim filtrem title/user?
     * Tylko wtedy bramkujemy — zwykłe przeglądanie i inne trasy przechodzą bez kosztu.
     */
    protected function isHeavySearch(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        // routeName ustawia rdzeniowy ResolveRoute PRZED tym middleware; ścieżka to
        // fallback, gdyby nazwa trasy zmieniła się między wersjami rdzenia. Kotwica
        // `…/discussions$` celowo NIE łapie pojedynczego zasobu `…/discussions/{id}`.
        $routeName = $request->getAttribute('routeName');
        $path = $request->getUri()->getPath();
        $isCollection = $routeName === 'discussions.index'
            || (bool) preg_match('~(^|/)discussions/?$~', $path);
        if (!$isCollection) {
            return false;
        }

        // Bramkujemy WYŁĄCZNIE ciężkie filtry LIKE tego rozszerzenia (title/user),
        // z tym samym progiem ≥3 znaki co filtry po stronie serwera.
        $filter = $request->getQueryParams()['filter'] ?? null;
        if (!is_array($filter)) {
            return false;
        }

        $title = is_string($filter['title'] ?? null) ? trim($filter['title']) : '';
        $user = is_string($filter['user'] ?? null) ? trim($filter['user']) : '';

        return mb_strlen($title) >= self::MIN_FILTER_LEN
            || mb_strlen($user) >= self::MIN_FILTER_LEN;
    }
}
