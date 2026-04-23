<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Security\PointsManager;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

class TrackerStatsController implements RequestHandlerInterface
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var ConnectionInterface
     */
    protected $db;

    /**
     * @var Paths
     */
    protected $paths;

    public function __construct(
        SettingsRepositoryInterface $settings,
        ConnectionInterface $db,
        Paths $paths
    ) {
        $this->settings = $settings;
        $this->db = $db;
        $this->paths = $paths;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $source = $request->getQueryParams()['source'] ?? null;
        $scope = ($source === 'external') ? 'external_stats' : 'stats';

        // Verify reCAPTCHA per-scope with optional points-based gating
        $points = new PointsManager($this->settings, $this->paths);
        $guard = new RecaptchaGuard($this->settings, $points);
        $result = $guard->verify($request, $scope);

        if (empty($result['ok'])) {
            if (!empty($result['captcha_required'])) {
                return new JsonResponse([
                    'error' => 'captcha_required',
                    'captcha_required' => true,
                    'balance' => $result['balance'] ?? 0,
                    'cost' => $result['cost'] ?? null,
                ], 403);
            }
            return new JsonResponse(['error' => 'reCAPTCHA verification failed'], 403);
        }

        $response = [
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
            'refilled' => $result['refilled'] ?? false,
        ];

        // If requesting external stats
        if ($source === 'external') {
            $externalEnabled = (bool) $this->settings->get('tryhackx-homepage-blocks.external_stats_enabled');
            $mode = $this->settings->get('tryhackx-homepage-blocks.external_stats_mode') ?: 'native';
            $nativeUrl = $this->settings->get('tryhackx-homepage-blocks.external_stats_native_url');
            $proxyUrl = $this->settings->get('tryhackx-homepage-blocks.external_stats_url');

            // Determine which URL to use based on mode
            $fetchUrl = ($mode === 'native' && $nativeUrl) ? $nativeUrl : $proxyUrl;
            $isNative = ($mode === 'native' && $nativeUrl);

            if ($externalEnabled && $fetchUrl) {
                $refreshInterval = max(1, (int) ($this->settings->get('tryhackx-homepage-blocks.external_stats_refresh') ?: 5));

                // Try to serve from file cache first (instant response)
                $cached = $this->getCachedExternalStats($refreshInterval);
                if ($cached !== null) {
                    $response['external'] = $cached;
                    $response['cached'] = true;
                    return new JsonResponse($response);
                }

                // Cache miss or expired — fetch fresh data
                $externalStats = $isNative
                    ? $this->fetchNativeOpenTracker($fetchUrl)
                    : $this->fetchExternalStats($fetchUrl);

                if ($externalStats !== null) {
                    $this->setCachedExternalStats($externalStats);
                    $response['external'] = $externalStats;
                } else {
                    // Serve stale cache if fresh fetch failed
                    $stale = $this->getCachedExternalStats(3600); // accept up to 1 hour stale
                    if ($stale !== null) {
                        $response['external'] = $stale;
                        $response['cached'] = true;
                        $response['stale'] = true;
                    } else {
                        $response['external'] = null;
                        $response['external_error'] = 'Failed to fetch external stats';
                    }
                }
            }

            return new JsonResponse($response);
        }

        // Default: return internal stats
        $stats = [];
        $stats['discussions'] = Discussion::whereNull('hidden_at')->count();
        $stats['users'] = User::count();

        // Magnet stats (from tryhackx/flarum-magnet-link if installed)
        try {
            $stats['magnets'] = $this->db->table('magnet_links')->count();
            $stats['magnet_clicks'] = (int) $this->db->table('magnet_links')->sum('click_count');
        } catch (\Exception $e) {
            $stats['magnets'] = 0;
            $stats['magnet_clicks'] = 0;
        }

        // View stats (from fof/discussion-views if installed)
        try {
            $stats['total_views'] = (int) Discussion::sum('view_count');
        } catch (\Exception $e) {
            $stats['total_views'] = 0;
        }

        // Average rating (from tryhackx/flarum-topic-rating if installed)
        try {
            $stats['avg_rating'] = round((float) Discussion::where('rating_count', '>', 0)->avg('rating_average'), 2);
            $stats['rated_count'] = (int) Discussion::where('rating_count', '>', 0)->count();
        } catch (\Exception $e) {
            $stats['avg_rating'] = 0;
            $stats['rated_count'] = 0;
        }

        $response['data'] = $stats;

        return new JsonResponse($response);
    }

    // ────────────── File-based cache ──────────────

    protected function getCacheFilePath()
    {
        return $this->paths->storage . '/cache/tryhackx_external_stats.json';
    }

    protected function getCachedExternalStats($maxAgeSeconds)
    {
        $file = $this->getCacheFilePath();
        if (!file_exists($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if ($mtime === false || (time() - $mtime) > $maxAgeSeconds) {
            return null; // expired
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    protected function setCachedExternalStats(array $data)
    {
        $file = $this->getCacheFilePath();
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    // ────────────── Native OpenTracker fetch (XML) ──────────────

    /**
     * Fetch stats directly from OpenTracker's XML endpoint.
     * URL: http://IP:6969/stats?mode=everything
     * Parses XML and extracts: torrents, seeds, peers, completed, uptime.
     */
    protected function fetchNativeOpenTracker($url)
    {
        $rawXml = $this->fetchRawContent($url);
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
     * Fetch raw content from a URL using cURL or file_get_contents.
     */
    protected function fetchRawContent($url)
    {
        if (function_exists('curl_init')) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Flarum/1.0 HomepageBlocks');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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
                // Fall through
            }
        }

        $context = stream_context_create([
            'http' => ['timeout' => 15, 'method' => 'GET'],
        ]);
        $response = @file_get_contents($url, false, $context);
        return $response !== false ? $response : null;
    }

    // ────────────── Proxy fetch (JSON) ──────────────

    /**
     * Fetch stats from external proxy URL.
     * Expected JSON: {"torrents":"1844213","seeds":"1842707","peers":"3538585","completed":"4629049","uptime":333031}
     */
    protected function fetchExternalStats($url)
    {
        // Try cURL first (more reliable on Windows/WAMP)
        if (function_exists('curl_init')) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Flarum/1.0 HomepageBlocks');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                // WAMP/Windows often has SSL certificate issues
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                // Force IPv4 to avoid IPv6 timeout issues common on Windows
                if (defined('CURLOPT_IPRESOLVE')) {
                    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                }
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
                    $data = json_decode($response, true);
                    if (is_array($data)) {
                        return $this->normalizeExternalData($data);
                    }
                }
            } catch (\Exception $e) {
                // Fall through to file_get_contents
            }
        }

        // Fallback: file_get_contents
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'method' => 'GET',
                    'header' => "User-Agent: Flarum/1.0 HomepageBlocks\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    return $this->normalizeExternalData($data);
                }
            }
        } catch (\Exception $e) {
            // ignore
        }

        return null;
    }

    protected function normalizeExternalData(array $data)
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
