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
use TryHackX\HomepageBlocks\Model\MagnetLink;

class TrackerStatsController implements RequestHandlerInterface
{
    /** Czas życia cache statystyk wewnętrznych (sekundy). Krótki — staty mają być „żywe”. */
    protected const INTERNAL_STATS_TTL = 10;

    /** Klucze magazynu cache statystyk. */
    protected const INTERNAL_KEY = 'stats:internal';
    protected const EXTERNAL_KEY = 'stats:external';
    /** Marker świeżej PORAŻKI fetcha zewnętrznego — negatywny cache. */
    protected const EXTERNAL_FAIL_KEY = 'stats:external:fail';
    /** Górne okno serwowania „stale" (sekundy) — i TTL wpisu w magazynie. */
    protected const STALE_TTL = 3600;
    /** Domyślne (gdy ustawienie puste) i twarde granice ustawień zewnętrznych. */
    protected const EXTERNAL_CACHE_TTL_DEFAULT = 30;
    protected const EXTERNAL_CACHE_TTL_MAX = 3600;
    protected const EXTERNAL_MAX_TIME_DEFAULT = 30;
    protected const EXTERNAL_MAX_TIME_MAX = 120;
    /** Po nieudanym fetchu nie ponawiamy do trackera przez tyle sekund (anty-hammering). */
    protected const EXTERNAL_FAIL_COOLDOWN = 30;

    public function __construct(
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

    // ────────────── Statystyki zewnętrzne (natywny OpenTracker, XML) ──────────────

    protected function handleExternal(ServerRequestInterface $request): ResponseInterface
    {
        $externalEnabled = (bool) $this->settings->get('tryhackx-homepage-blocks.external_stats_enabled');
        $nativeUrl = (string) ($this->settings->get('tryhackx-homepage-blocks.external_stats_native_url') ?: '');

        if (!$externalEnabled || $nativeUrl === '') {
            return new JsonResponse(['external' => null]);
        }

        $cacheTtl = $this->externalCacheTtl();
        $maxTime = $this->externalMaxTime();

        // 1) Świeży cache — serwuj od razu, BEZ dotykania trackera. Współdzielony
        //    cache + single-flight => do trackera idzie najwyżej JEDEN fetch na TTL,
        //    globalnie — niezależnie od liczby odświeżających userów.
        $cached = $this->getCachedExternalStats($cacheTtl);
        if ($cached !== null) {
            return new JsonResponse(['external' => $cached, 'cached' => true]);
        }

        // 2) Single-flight: TYLKO jeden worker odpytuje tracker (do max_time), reszta
        //    dostaje null natychmiast (lock nieblokujący) i serwuje stale/pending.
        $fresh = $this->refreshExternalSingleFlight($nativeUrl, $cacheTtl, $maxTime);
        if ($fresh !== null) {
            return new JsonResponse(['external' => $fresh]);
        }

        // 3) Fetch trwa u innego workera / cooldown po porażce — podaj stale (do STALE_TTL).
        $stale = $this->getCachedExternalStats(self::STALE_TTL);
        if ($stale !== null) {
            return new JsonResponse(['external' => $stale, 'cached' => true, 'stale' => true]);
        }

        // 4) Zimny cache i fetch trwa gdzie indziej — sygnał „poczekaj i odpytaj ponownie"
        //    (frontend trzyma spinner do rozgrzania cache — patrz TrackerStats.js).
        return new JsonResponse(['external' => null, 'external_pending' => true]);
    }

    /** Czas życia cache statystyk zewnętrznych (sekundy), z twardymi granicami. */
    protected function externalCacheTtl(): int
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.external_stats_cache_ttl');
        $ttl = ($raw === null || $raw === '') ? self::EXTERNAL_CACHE_TTL_DEFAULT : (int) $raw;
        return max(1, min(self::EXTERNAL_CACHE_TTL_MAX, $ttl));
    }

    /** Maksymalny czas pobierania statystyk z trackera (sekundy), z twardymi granicami. */
    protected function externalMaxTime(): int
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.external_stats_max_time');
        $t = ($raw === null || $raw === '') ? self::EXTERNAL_MAX_TIME_DEFAULT : (int) $raw;
        return max(1, min(self::EXTERNAL_MAX_TIME_MAX, $t));
    }

    /**
     * Odśwież cache statystyk zewnętrznych pod blokadą flock (pojedynczy fetch).
     * Zwraca świeże dane, albo null gdy lock zajęty / cooldown po porażce / fetch zawiódł.
     */
    protected function refreshExternalSingleFlight(string $url, int $cacheTtl, int $maxTime): ?array
    {
        // Lock nieblokujący (wait=false): tylko właściciel locka pobiera; pozostali
        // dostają null bez czekania i serwują stale/pending (audyt B1/#3).
        return $this->store->withLock(self::EXTERNAL_KEY, function () use ($url, $cacheTtl, $maxTime) {
            // Double-check: ktoś mógł zapełnić cache zanim dostaliśmy lock.
            $again = $this->getCachedExternalStats($cacheTtl);
            if ($again !== null) {
                return $again;
            }

            // Negatywny cache: po świeżej porażce NIE waliMY do (wolnego/martwego)
            // trackera przy każdym pollu — czekamy cooldown, by nie zjadać workerów.
            if ($this->readFresh(self::EXTERNAL_FAIL_KEY, self::EXTERNAL_FAIL_COOLDOWN) !== null) {
                return null;
            }

            $data = $this->fetchNativeOpenTracker($url, $maxTime);
            if ($data !== null) {
                $this->setCachedExternalStats($data);
            } else {
                // Zapamiętaj porażkę (cooldown) — analogicznie do sukcesu w cache.
                $this->store->write(self::EXTERNAL_FAIL_KEY, ['at' => time()], self::STALE_TTL);
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
    protected function fetchNativeOpenTracker(string $url, int $maxTime): ?array
    {
        $rawXml = $this->fetchRaw($url, $maxTime);
        if ($rawXml === null) {
            return null;
        }

        // LIBXML_NONET wyłącza sieć podczas parsowania (obrona w głąb przed XXE, gdyby
        // źródło zwróciło spreparowany XML z encjami zewnętrznymi). Ładowanie encji
        // zewnętrznych jest w libxml 2.9+ i tak domyślnie wyłączone.
        $xml = @simplexml_load_string($rawXml, \SimpleXMLElement::class, LIBXML_NONET);
        if (!$xml) {
            return null;
        }

        // OpenTracker liczy „peers" jako WSZYSTKICH klientów w roju (seedery + leechery),
        // a „seeds" to sami seederzy. Leecherów wyprowadzamy więc jako peers − seeds
        // (clamp do 0 na wypadek chwilowej niespójności migawki), tak samo jak robi to
        // tracker.tryhackx.org. Dzięki temu frontend dostaje gotową wartość.
        $seeds = (int) ($xml->seeds->count ?? 0);
        $peers = (int) ($xml->peers->count ?? 0);

        return [
            'torrents' => (string) ($xml->torrents->count_mutex ?? 0),
            'seeds' => (string) $seeds,
            'leechers' => (string) max(0, $peers - $seeds),
            'peers' => (string) $peers,
            'completed' => (string) ($xml->completed->count ?? 0),
            'uptime' => (int) ($xml->uptime ?? 0),
        ];
    }

    /**
     * Pobierz surową treść z URL: cURL (z wymuszonym IPv4, limitem connect/total
     * i podążaniem za przekierowaniami), a gdy cURL niedostępny — fallback na
     * file_get_contents ze stream context.
     *
     * $maxTime (sekundy) to CAŁKOWITY limit pobierania — konfigurowalny, bo duży
     * tracker liczy statystyki nawet ~minutę. Connect-timeout jest osobny i krótki:
     * martwy host ma paść szybko, a $maxTime obejmuje wolną, ale ŻYWĄ odpowiedź.
     * Single-flight (lock) wpuszcza tu najwyżej jednego workera naraz.
     */
    protected function fetchRaw(string $url, int $maxTime): ?string
    {
        $userAgent = 'Flarum/2.0 HomepageBlocks';
        $connectTimeout = max(2, min($maxTime, 10));

        // Preferuj cURL. Gdy jest dostępny, to ON JEDYNY decyduje o wyniku — po jego
        // porażce/timeoucie NIE dokładamy fallbacku, bo to podwajało oczekiwanie
        // (2× max_time) na wolnym/martwym trackerze. file_get_contents niżej służy
        // WYŁĄCZNIE środowiskom bez cURL.
        if (function_exists('curl_init')) {
            $ch = curl_init();
            // Guard na false (np. wyczerpanie zasobów) — bez tego curl_setopt(false, …)
            // rzuca TypeError. Gdy init padnie, spadamy do fallbacku (audyt #1).
            if ($ch !== false) {
                $response = false;
                $httpCode = 0;
                try {
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, $maxTime);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                    if (defined('CURLOPT_IPRESOLVE')) {
                        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    }
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                } catch (\Throwable $e) {
                    // cURL zepsuty w locie — potraktuj jak porażkę fetcha (null niżej).
                } finally {
                    @curl_close($ch);
                }

                return ($response !== false && $httpCode >= 200 && $httpCode < 400) ? $response : null;
            }
        }

        // Fallback tylko dla środowisk bez cURL.
        $context = stream_context_create([
            'http' => [
                'timeout' => $maxTime,
                'method' => 'GET',
                'header' => 'User-Agent: ' . $userAgent . "\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return $response !== false ? $response : null;
    }
}
