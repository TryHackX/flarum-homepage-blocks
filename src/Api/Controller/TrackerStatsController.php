<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Model\MagnetLink;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

class TrackerStatsController implements RequestHandlerInterface
{
    use BuildsGuardResponse;

    /** Czas życia cache statystyk wewnętrznych (sekundy). Krótki — staty mają być „żywe”. */
    protected const INTERNAL_STATS_TTL = 10;

    /** Klucze magazynu cache statystyk. */
    protected const INTERNAL_KEY = 'stats:internal';
    protected const EXTERNAL_KEY = 'stats:external';
    /** Górne okno serwowania „stale" (sekundy) — i TTL wpisu w magazynie. */
    protected const STALE_TTL = 3600;

    public function __construct(
        protected RecaptchaGuard $guard,
        protected SettingsRepositoryInterface $settings,
        protected Store $store
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $source = $request->getQueryParams()['source'] ?? null;

        if ($source === 'external') {
            return $this->handleExternal($request);
        }

        return $this->handleInternal($request);
    }

    // ────────────── Statystyki wewnętrzne (baza) ──────────────

    protected function handleInternal(ServerRequestInterface $request): ResponseInterface
    {
        // W trybie punktowym statystyki nie są mierzone (przepuszczane); w trybie
        // klasycznej reCAPTCHA wymagany jest token — to rozstrzyga bramka.
        $result = $this->guard->verify($request, 'stats');
        if ($error = $this->guardError($result)) {
            return $error;
        }

        // Statystyki wewnętrzne to globalne agregaty (count/sum/avg) — identyczne
        // dla każdego widza, więc krótko cache'ujemy je w pliku. Bez tego 5–7
        // zapytań agregujących szło do bazy przy KAŻDYM zimnym żądaniu (audyt C1).
        // Przy cache-miss odświeżamy pod single-flight (flock), żeby równoległe
        // workery nie liczyły wszystkich agregatów naraz (audyt #3).
        $stats = $this->getCachedInternalStats(self::INTERNAL_STATS_TTL);
        if ($stats === null) {
            $stats = $this->refreshInternalSingleFlight();
        }

        return new JsonResponse([
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
            'refilled' => $result['refilled'] ?? false,
            'data' => $stats,
        ]);
    }

    /**
     * Policz globalne statystyki wewnętrzne (baza). Każda gałąź zależna od innego
     * rozszerzenia jest w try/catch, więc brak magnet-link / discussion-views /
     * topic-rating daje 0 zamiast wywrócić zapytanie.
     */
    protected function computeInternalStats(): array
    {
        $stats = [];
        $stats['discussions'] = Discussion::whereNull('hidden_at')->count();
        $stats['users'] = User::count();

        // Magnety (z tryhackx/flarum-magnet-link, jeśli zainstalowane).
        try {
            $stats['magnets'] = MagnetLink::count();
            $stats['magnet_clicks'] = (int) MagnetLink::sum('click_count');
        } catch (\Throwable $e) {
            $stats['magnets'] = 0;
            $stats['magnet_clicks'] = 0;
        }

        // Wyświetlenia (z fof/discussion-views, jeśli zainstalowane).
        try {
            $stats['total_views'] = (int) Discussion::sum('view_count');
        } catch (\Throwable $e) {
            $stats['total_views'] = 0;
        }

        // Średnia ocena (z tryhackx/flarum-topic-rating, jeśli zainstalowane).
        try {
            $stats['avg_rating'] = round((float) Discussion::where('rating_count', '>', 0)->avg('rating_average'), 2);
            $stats['rated_count'] = (int) Discussion::where('rating_count', '>', 0)->count();
        } catch (\Throwable $e) {
            $stats['avg_rating'] = 0;
            $stats['rated_count'] = 0;
        }

        return $stats;
    }

    /**
     * Policz statystyki wewnętrzne pod blokadą flock (pojedynczy „compute") —
     * mirror {@see refreshExternalSingleFlight}. Bez tego przy zimnym/wygasłym
     * cache każdy worker odpalałby 5–7 agregatów naraz (audyt #3). Zawsze zwraca
     * tablicę (UI wymaga danych): worker bez locka serwuje stale (do 1h), a gdy
     * stale brak (pierwszy zimny load) — liczy best-effort.
     */
    protected function refreshInternalSingleFlight(): array
    {
        // Single-flight przez Store: tylko jeden worker liczy agregaty na interwał
        // (globalnie, gdy magazyn jest współdzielony). Gdy locka nie dostaniemy,
        // withLock zwróci null — wtedy serwujemy stale (do 1h), a przy pierwszym
        // zimnym ładowaniu liczymy best-effort, by UI nie dostało pustki (audyt #3).
        $data = $this->store->withLock(self::INTERNAL_KEY, function () {
            // Double-check: ktoś mógł odświeżyć cache zanim dostaliśmy lock.
            $again = $this->getCachedInternalStats(self::INTERNAL_STATS_TTL);
            if ($again !== null) {
                return $again;
            }

            $fresh = $this->computeInternalStats();
            $this->setCachedInternalStats($fresh);
            return $fresh;
        }, false, null);

        if ($data !== null) {
            return $data;
        }

        return $this->getCachedInternalStats(self::STALE_TTL) ?? $this->computeInternalStats();
    }

    // ────────────── Statystyki zewnętrzne (OpenTracker / proxy) ──────────────

    protected function handleExternal(ServerRequestInterface $request): ResponseInterface
    {
        $externalEnabled = (bool) $this->settings->get('tryhackx-homepage-blocks.external_stats_enabled');
        $mode = $this->settings->get('tryhackx-homepage-blocks.external_stats_mode') ?: 'native';
        $nativeUrl = $this->settings->get('tryhackx-homepage-blocks.external_stats_native_url');
        $proxyUrl = $this->settings->get('tryhackx-homepage-blocks.external_stats_url');

        $fetchUrl = ($mode === 'native' && $nativeUrl) ? $nativeUrl : $proxyUrl;
        $isNative = ($mode === 'native' && $nativeUrl);

        if (!$externalEnabled || !$fetchUrl) {
            return new JsonResponse(['external' => null]);
        }

        $refreshInterval = max(1, (int) ($this->settings->get('tryhackx-homepage-blocks.external_stats_refresh') ?: 5));

        // 1) Serwuj z cache jako pierwsze — BEZ naliczania punktów. Auto-odświeżanie
        //    UI jest legalne; dzielony cache + pojedynczy fetch (flock) sprawiają, że
        //    do źródła idzie najwyżej jedno zapytanie na interwał, globalnie.
        $cached = $this->getCachedExternalStats($refreshInterval);
        if ($cached !== null) {
            return new JsonResponse(['external' => $cached, 'cached' => true]);
        }

        // 2) Pudło w cache — przepuść przez bramkę (tryb klasyczny wymaga tokenu;
        //    tryb punktowy przepuszcza, bo statystyki nie są mierzone).
        $result = $this->guard->verify($request, 'external_stats');
        if ($error = $this->guardError($result)) {
            return $error;
        }

        // 3) Pojedynczy fetch (flock): tylko jeden worker odświeża, reszta dostaje
        //    stale/pending — zapobiega thundering herd przy zimnym cache (audyt B1).
        $fresh = $this->refreshExternalSingleFlight($isNative, $fetchUrl, $refreshInterval);
        if ($fresh !== null) {
            return new JsonResponse(['external' => $fresh]);
        }

        // 4) Świeży fetch się nie udał lub trwa u innego workera — podaj stale (do 1h).
        $stale = $this->getCachedExternalStats(3600);
        if ($stale !== null) {
            return new JsonResponse(['external' => $stale, 'cached' => true, 'stale' => true]);
        }

        return new JsonResponse(['external' => null, 'external_pending' => true]);
    }

    /**
     * Odśwież cache statystyk zewnętrznych pod blokadą flock (pojedynczy fetch).
     * Zwraca świeże dane, albo null gdy lock zajęty lub fetch zawiódł.
     */
    protected function refreshExternalSingleFlight(bool $isNative, string $fetchUrl, int $refreshInterval): ?array
    {
        // Single-flight przez Store: tylko jeden worker odpytuje źródło na interwał
        // (globalnie, gdy magazyn jest współdzielony) — zapobiega thundering herd na
        // zimnym cache (audyt B1/#3). Gdy locka nie dostaniemy, zwracamy null i
        // wołający serwuje stale.
        return $this->store->withLock(self::EXTERNAL_KEY, function () use ($isNative, $fetchUrl, $refreshInterval) {
            // Double-check: ktoś mógł odświeżyć cache zanim dostaliśmy lock.
            $again = $this->getCachedExternalStats($refreshInterval);
            if ($again !== null) {
                return $again;
            }

            $data = $isNative ? $this->fetchNativeOpenTracker($fetchUrl) : $this->fetchExternalStats($fetchUrl);
            if ($data !== null) {
                $this->setCachedExternalStats($data);
            }
            return $data;
        }, false, null);
    }

    // ────────────── Cache statystyk (przez Store) ──────────────
    // Magazyn jest wymienny: domyślnie plikowy (atomowy zapis + flock), a przy
    // współdzielonym cache (Redis…) automatycznie cross-node. Cała mechanika
    // plik/lock/atomic-write żyje teraz w Store, nie w kontrolerze (audyt #2/#5).

    protected function getCachedExternalStats(int $maxAgeSeconds): ?array
    {
        return $this->readFresh(self::EXTERNAL_KEY, $maxAgeSeconds);
    }

    protected function setCachedExternalStats(array $data): void
    {
        $this->store->write(self::EXTERNAL_KEY, $data, self::STALE_TTL);
    }

    protected function getCachedInternalStats(int $maxAgeSeconds): ?array
    {
        return $this->readFresh(self::INTERNAL_KEY, $maxAgeSeconds);
    }

    protected function setCachedInternalStats(array $data): void
    {
        $this->store->write(self::INTERNAL_KEY, $data, self::STALE_TTL);
    }

    /**
     * Odczyt z magazynu z kontrolą wieku. Zwraca null, gdy brak / starsze niż
     * $maxAgeSeconds / uszkodzone.
     */
    protected function readFresh(string $key, int $maxAgeSeconds): ?array
    {
        $read = $this->store->read($key);
        if ($read === null || $read['age'] > $maxAgeSeconds) {
            return null;
        }
        return $read['value'];
    }

    // ────────────── Natywny OpenTracker (XML) ──────────────

    /**
     * Pobierz statystyki wprost z endpointu XML OpenTrackera.
     * URL: http://IP:6969/stats?mode=everything
     */
    protected function fetchNativeOpenTracker(string $url): ?array
    {
        $rawXml = $this->fetchRaw($url);
        if ($rawXml === null) {
            return null;
        }

        $xml = @simplexml_load_string($rawXml);
        if (!$xml) {
            return null;
        }

        return [
            'torrents' => (string) ($xml->torrents->count_mutex ?? 0),
            'seeds' => (string) ($xml->seeds->count ?? 0),
            'peers' => (string) ($xml->peers->count ?? 0),
            'completed' => (string) ($xml->completed->count ?? 0),
            'uptime' => (int) ($xml->uptime ?? 0),
        ];
    }

    /**
     * Pobierz surową treść z URL: cURL (z wymuszonym IPv4, limitami connect/total
     * i podążaniem za przekierowaniami), a gdy cURL niedostępny — fallback na
     * file_get_contents ze stream context. Jedno miejsce konfiguracji transportu,
     * współdzielone przez ścieżkę natywną (XML) i proxy (JSON) — audyt #8.
     */
    protected function fetchRaw(string $url): ?string
    {
        $userAgent = 'Flarum/2.0 HomepageBlocks';

        if (function_exists('curl_init')) {
            try {
                $ch = curl_init();
                // Guard na false (np. wyczerpanie zasobów) — bez tego curl_setopt(false, …)
                // rzuca TypeError (\Error). Spójne z postSiteverify() (audyt #1).
                if ($ch !== false) {
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    // Krótkie, twarde limity: pojedynczy wolny/niedostępny tracker nie
                    // może trzymać workera FPM — przy dużym ruchu to wprost ucina pulę
                    // współbieżności. Total 5 s / connect 2 s (audyt #4). Single-flight
                    // (flock/lock) i tak wpuszcza tu najwyżej jednego workera na interwał.
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                    if (defined('CURLOPT_IPRESOLVE')) {
                        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    }
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
                        return $response;
                    }
                }
            } catch (\Throwable $e) {
                // przejdź do fallbacku (łapiemy też \Error, np. TypeError)
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'GET',
                'header' => 'User-Agent: ' . $userAgent . "\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return $response !== false ? $response : null;
    }

    // ────────────── Proxy (JSON) ──────────────

    /**
     * Pobierz statystyki z zewnętrznego URL proxy.
     * Oczekiwany JSON: {"torrents":"...","seeds":"...","peers":"...","completed":"...","uptime":...}
     */
    protected function fetchExternalStats(string $url): ?array
    {
        $raw = $this->fetchRaw($url);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $this->normalizeExternalData($data) : null;
    }

    protected function normalizeExternalData(array $data): array
    {
        return [
            'torrents' => $data['torrents'] ?? 0,
            'seeds' => $data['seeds'] ?? 0,
            'peers' => $data['peers'] ?? 0,
            'completed' => $data['completed'] ?? 0,
            'uptime' => $data['uptime'] ?? 0,
        ];
    }
}
