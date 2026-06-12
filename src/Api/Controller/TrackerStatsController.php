<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Model\MagnetLink;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

class TrackerStatsController implements RequestHandlerInterface
{
    use BuildsGuardResponse;

    /** Czas życia cache statystyk wewnętrznych (sekundy). Krótki — staty mają być „żywe”. */
    protected const INTERNAL_STATS_TTL = 10;

    public function __construct(
        protected RecaptchaGuard $guard,
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths
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
        $stats = $this->getCachedInternalStats(self::INTERNAL_STATS_TTL);
        if ($stats === null) {
            $stats = $this->computeInternalStats();
            $this->setCachedInternalStats($stats);
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
        $lockPath = $this->getCacheFilePath() . '.lock';
        $fp = @fopen($lockPath, 'c');

        if ($fp === false) {
            // Nie da się założyć locka — fetch best-effort bez ochrony.
            $data = $isNative ? $this->fetchNativeOpenTracker($fetchUrl) : $this->fetchExternalStats($fetchUrl);
            if ($data !== null) {
                $this->setCachedExternalStats($data);
            }
            return $data;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            // Inny worker już odświeża — nie czekamy (serwujemy stale wyżej).
            fclose($fp);
            return null;
        }

        try {
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
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ────────────── Cache plikowy ──────────────

    protected function getCacheFilePath(): string
    {
        return $this->paths->storage . '/cache/tryhackx_external_stats.json';
    }

    protected function getInternalCacheFilePath(): string
    {
        return $this->paths->storage . '/cache/tryhackx_internal_stats.json';
    }

    protected function getCachedExternalStats(int $maxAgeSeconds): ?array
    {
        return $this->readCacheFile($this->getCacheFilePath(), $maxAgeSeconds);
    }

    protected function setCachedExternalStats(array $data): void
    {
        $this->atomicWrite($this->getCacheFilePath(), json_encode($data));
    }

    protected function getCachedInternalStats(int $maxAgeSeconds): ?array
    {
        return $this->readCacheFile($this->getInternalCacheFilePath(), $maxAgeSeconds);
    }

    protected function setCachedInternalStats(array $data): void
    {
        $this->atomicWrite($this->getInternalCacheFilePath(), json_encode($data));
    }

    /**
     * Odczyt cache plikowego z kontrolą wieku. Zwraca null gdy brak/wygasł/uszkodzony.
     */
    protected function readCacheFile(string $file, int $maxAgeSeconds): ?array
    {
        if (!file_exists($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if ($mtime === false || (time() - $mtime) > $maxAgeSeconds) {
            return null; // wygasł
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Atomowy zapis: zapisz do pliku tymczasowego i podmień przez rename (atomowy),
     * żeby czytelnik nie trzymający locka nie trafił na rozerwany plik (audyt D2).
     */
    protected function atomicWrite(string $file, string $payload): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) {
                @file_put_contents($file, $payload, LOCK_EX);
                @unlink($tmp);
            }
        }
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
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 12);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
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
            } catch (\Exception $e) {
                // przejdź do fallbacku
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 12,
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
