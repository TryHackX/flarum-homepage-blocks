<?php

namespace TryHackX\HomepageBlocks\Http;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Service\MagnetExtractor;

/**
 * HTTP client for the tracker's server-to-server whitelist API (tryhackx-tracker ≥ 1.2.0):
 *
 *   POST {api_url}/api.php?endpoint=v1/whitelist/submit   {"items":[…],"source":"forum"}
 *   GET  {api_url}/api.php?endpoint=v1/whitelist/ping
 *   Authorization: Bearer <key_id>.<secret>
 *
 * Raw cURL like TrackerStatsController::fetchRaw (deliberately no HTTP-client dependency):
 * http/https only, no redirects (a redirect could leak the bearer to another host), TLS verified,
 * IPv4 forced, hard timeouts, response capped. Failure backoff lives in the shared Store so a dead
 * tracker slows post saves by at most one attempt per cooldown window (60 s transport failure,
 * 600 s after 403 = wrong key or banned IP). Never logs headers or the secret.
 *
 * Credentials are validated locally before any request: the tracker bans the source IP for 30 days
 * on ANY malformed or unknown auth attempt, so a typo must never reach the wire.
 */
final class TrackerWhitelistClient
{
    public const S = 'tryhackx-homepage-blocks';
    public const KEY_ID_RE = '/^[a-f0-9]{16}$/i';
    public const SECRET_RE = '/^[a-f0-9]{64}$/i';
    public const TOKEN_RE = '/^([a-f0-9]{16})\.([a-f0-9]{64})$/i';
    private const BACKOFF_KEY = 'whitelist:backoff';
    private const LAST_ERROR_KEY = 'whitelist:last_error';
    private const MAX_RESPONSE_BYTES = 262144;

    /** Base URL that already passed the DNS / reserved-range check in this process (scan batches reuse it). */
    private ?string $resolvedBase = null;

    /** noteEmptyTrackerHosts() already written in this request (the client is a per-request singleton). */
    private bool $notedEmptyHosts = false;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger,
        private Store $store,
    ) {
    }

    public function isEnabled(): bool
    {
        $v = $this->settings->get(self::S . '.whitelist_sync_enabled');
        return $v === true || $v === '1' || $v === 1 || $v === 'true';
    }

    /** Pure settings/syntax check (no DNS, no I/O): URL syntax + key id + secret present. */
    public function isConfigured(): bool
    {
        return $this->apiUrl() !== null
            && trim((string) $this->settings->get(self::S . '.whitelist_api_key_id')) !== ''
            && trim((string) $this->settings->get(self::S . '.whitelist_api_secret')) !== '';
    }

    public function timeoutSeconds(): int
    {
        $raw = $this->settings->get(self::S . '.whitelist_timeout_seconds');
        $t = ($raw === null || $raw === '') ? 3 : (int) $raw;
        return max(1, min(10, $t));
    }

    public function detectBareHashes(): bool
    {
        $v = $this->settings->get(self::S . '.whitelist_detect_bare_hashes');
        return $v === true || $v === '1' || $v === 1 || $v === 'true';
    }

    /** "Only sync magnets that point at our tracker" (default off). */
    public function requireTracker(): bool
    {
        $v = $this->settings->get(self::S . '.whitelist_require_tracker');
        return $v === true || $v === '1' || $v === 1 || $v === 'true';
    }

    /** Configured tracker hosts for requireTracker() — lowercase hostnames / IPs (empty when unset). @return string[] */
    public function trackerHosts(): array
    {
        return MagnetExtractor::parseHostList((string) $this->settings->get(self::S . '.whitelist_tracker_hosts'));
    }

    /**
     * requireTracker() is on but trackerHosts() is empty → nothing can ever match. Surfaces the
     * misconfiguration through the admin last-error line (once per request; the store write is cheap
     * but there is no point repeating it for every post of a scan batch).
     */
    public function noteEmptyTrackerHosts(): void
    {
        if ($this->notedEmptyHosts) return;
        $this->notedEmptyHosts = true;
        $this->noteError('Nothing sent: "Only sync magnets that point at our tracker" is on but the tracker host list is empty — add your tracker hostname(s) / IP(s) in the whitelist settings, or turn the option off.');
    }

    /** Syntactically valid base URL (scheme http/https, non-empty host) or null. No DNS here. */
    public function apiUrl(?string $override = null): ?string
    {
        $url = trim((string) ($override ?? $this->settings->get(self::S . '.whitelist_api_url')));
        return self::validateUrl($url);
    }

    /** Syntax-only check; the DNS / reserved-range guard runs in request() right before cURL. */
    public static function validateUrl(string $url): ?string
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) return null;
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) return null;
        return rtrim($url, '/');
    }

    /**
     * SSRF guard (blocking DNS lookup, so only right before an actual request and memoized per object):
     * admin-trusted setting; still refuse hostnames resolving to loopback/private/reserved ranges unless
     * the host is a literal IP the admin typed on purpose (a local tracker on the same box IS a valid
     * setup, e.g. 127.0.0.1).
     */
    private function resolvesPublic(string $base): bool
    {
        if ($this->resolvedBase === $base) return true;
        $host = (string) (parse_url($base, PHP_URL_HOST) ?? '');
        if ($host === '') return false;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = @gethostbynamel($host) ?: [];
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) return false;
            }
        }
        $this->resolvedBase = $base;
        return true;
    }

    /**
     * Key id + secret from settings (or admin overrides), normalised to lowercase hex. A composite
     * "key_id.secret" token in the secret slot is split. Returns [key_id, secret] or an error code
     * ('missing_credentials' | 'invalid_credentials') — malformed values never reach the wire.
     */
    private function credentials(?array $override): array|string
    {
        $keyId = trim((string) ($override['api_key_id'] ?? $this->settings->get(self::S . '.whitelist_api_key_id')));
        $secret = trim((string) ($override['api_secret'] ?? $this->settings->get(self::S . '.whitelist_api_secret')));
        if (preg_match(self::TOKEN_RE, $secret, $m)) {
            $keyId = $m[1];
            $secret = $m[2];
        }
        if ($keyId === '' || $secret === '') return 'missing_credentials';
        if (!preg_match(self::KEY_ID_RE, $keyId) || !preg_match(self::SECRET_RE, $secret)) return 'invalid_credentials';
        return [strtolower($keyId), strtolower($secret)];
    }

    public function inCooldown(): bool
    {
        $r = $this->store->read(self::BACKOFF_KEY);
        if (!$r || empty($r['value']['until'])) return false;
        return (int) $r['value']['until'] > time();
    }

    private function setCooldown(int $seconds, string $reason): void
    {
        try {
            $this->store->write(self::BACKOFF_KEY, ['until' => time() + $seconds, 'reason' => $reason], $seconds + 5);
        } catch (\Throwable $e) {
        }
    }

    public function clearCooldown(): void
    {
        try { $this->store->write(self::BACKOFF_KEY, ['until' => 0, 'reason' => ''], 5); } catch (\Throwable $e) {}
    }

    /** Last error shown in the admin UI: ['at' => ts, 'message' => string] or null. */
    public function lastError(): ?array
    {
        $r = $this->store->read(self::LAST_ERROR_KEY);
        return ($r && !empty($r['value']['message'])) ? $r['value'] : null;
    }

    private function noteError(string $message): void
    {
        try { $this->store->write(self::LAST_ERROR_KEY, ['at' => time(), 'message' => mb_substr($message, 0, 300)], 86400 * 7); } catch (\Throwable $e) {}
    }

    private function noteSuccess(): void
    {
        try { $this->store->write(self::LAST_ERROR_KEY, ['at' => time(), 'message' => '', 'ok_at' => time()], 86400 * 7); } catch (\Throwable $e) {}
    }

    /**
     * Submit items to the whitelist. $items = [['magnet'=>…, 'name'=>…, 'ref'=>[…]] | ['hash'=>…]].
     * Returns ['ok'=>bool, 'status'=>int, 'json'=>?array, 'error'=>?string, 'elapsed_ms'=>int].
     */
    public function submit(array $items, string $source = 'forum', ?int $timeout = null): array
    {
        if (!$items) return ['ok' => true, 'status' => 0, 'json' => ['results' => [], 'summary' => ['added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0]], 'error' => null, 'elapsed_ms' => 0];
        return $this->request('POST', 'v1/whitelist/submit', ['items' => array_values($items), 'source' => $source], $timeout ?? $this->timeoutSeconds());
    }

    /** Health check; $override = ['api_url','api_key_id','api_secret'] for unsaved admin values. */
    public function ping(?array $override = null, int $timeout = 6): array
    {
        return $this->request('GET', 'v1/whitelist/ping', null, $timeout, $override);
    }

    private function request(string $method, string $endpoint, ?array $body, int $timeout, ?array $override = null): array
    {
        $t0 = microtime(true);
        $base = $this->apiUrl($override['api_url'] ?? null);
        if ($base === null) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'invalid_url', 'elapsed_ms' => 0];
        $creds = $this->credentials($override);
        if (!is_array($creds)) {
            if ($creds === 'invalid_credentials') {
                $this->noteError('Credentials not sent: the key ID must be 16 hex characters and the secret 64 hex characters (or one key_id.secret token). Fix them before testing — the tracker bans the IP for 30 days on a bad attempt.');
            }
            return ['ok' => false, 'status' => 0, 'json' => null, 'error' => $creds, 'elapsed_ms' => 0];
        }
        [$keyId, $secret] = $creds;
        if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'curl_missing', 'elapsed_ms' => 0];
        if (!$this->resolvesPublic($base)) {
            $this->noteError('Tracker URL refused: the host resolves to a private/reserved address (use a public hostname or a literal IP).');
            return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'invalid_url', 'elapsed_ms' => (int) ((microtime(true) - $t0) * 1000)];
        }

        $url = $base . '/api.php?endpoint=' . $endpoint;
        $ch = curl_init($url);
        if ($ch === false) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'curl_init_failed', 'elapsed_ms' => 0];
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $keyId . '.' . $secret, 'Expect:'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => max(1, min(2, $timeout)),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Flarum/2.0 HomepageBlocks-WhitelistSync',
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
        elseif (defined('CURLOPT_PROTOCOLS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        if (defined('CURLOPT_MAXFILESIZE')) $opts[CURLOPT_MAXFILESIZE] = self::MAX_RESPONSE_BYTES;
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        if ($raw === false || $raw === null) {
            $this->setCooldown(60, 'transport');
            $this->noteError('Tracker unreachable: ' . ($curlErr ?: 'transport error'));
            return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'transport: ' . mb_substr($curlErr, 0, 120), 'elapsed_ms' => $elapsed];
        }
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) $raw = substr($raw, 0, self::MAX_RESPONSE_BYTES);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = null;

        if ($status === 403 || $status === 401) {
            $this->setCooldown(600, 'auth');
            $this->noteError('Tracker returned HTTP ' . $status . ' — check the key ID / secret in settings; the tracker may also have banned this server\'s IP (see its API bans panel).');
            return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => 'forbidden', 'elapsed_ms' => $elapsed];
        }
        if ($status < 200 || $status >= 300) {
            $this->setCooldown($status >= 500 ? 60 : 30, 'http_' . $status);
            $this->noteError('Tracker returned HTTP ' . $status . ($json && isset($json['error']) ? ' (' . $json['error'] . ')' : ''));
            return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => 'http_' . $status . ($json && isset($json['error']) ? ':' . $json['error'] : ''), 'elapsed_ms' => $elapsed];
        }
        if ($json === null) {
            $this->setCooldown(60, 'invalid_json');
            $this->noteError('Tracker returned a non-JSON response');
            return ['ok' => false, 'status' => $status, 'json' => null, 'error' => 'invalid_json', 'elapsed_ms' => $elapsed];
        }
        $this->clearCooldown();
        $this->noteSuccess();
        return ['ok' => true, 'status' => $status, 'json' => $json, 'error' => null, 'elapsed_ms' => $elapsed];
    }
}
