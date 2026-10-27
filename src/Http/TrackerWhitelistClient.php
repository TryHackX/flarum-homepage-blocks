<?php

namespace TryHackX\HomepageBlocks\Http;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use TryHackX\HomepageBlocks\Cache\Store;
use TryHackX\HomepageBlocks\Service\DescriptionPayload;
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
 * IPv4 forced, hard timeouts, response capped. Failure backoff lives in the shared Store (60 s transport
 * failure, 600 s after 403 = wrong key or banned IP). Never logs headers or the secret.
 *
 * Credentials are validated locally before any request: the tracker bans the source IP for 30 days
 * on ANY malformed or unknown auth attempt, so a typo must never reach the wire.
 *
 * Not final: the integration tests replace the transport with a recording double.
 */
class TrackerWhitelistClient
{
    public const S = 'tryhackx-homepage-blocks';
    public const KEY_ID_RE = '/^[a-f0-9]{16}$/i';
    public const SECRET_RE = '/^[a-f0-9]{64}$/i';
    public const TOKEN_RE = '/^([a-f0-9]{16})\.([a-f0-9]{64})$/i';
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    public const DESCRIBE_TIMEOUT = 20;
    public const PACE_CALLS = 40;
    public const PACE_WINDOW = 60;
    public const LIMITS_FRESH = 21600;
    private const DESCRIBE_OFF_SECONDS = 600;
    private const BACKOFF_KEY = 'whitelist:backoff';
    private const LAST_ERROR_KEY = 'whitelist:last_error';
    private const DESCRIBE_OFF_KEY = 'whitelist:describe_off';
    private const LIMITS_KEY = 'whitelist:limits';
    private const PACE_KEY = 'whitelist:pace';
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

    public static function truthy(mixed $v): bool
    {
        return $v === true || $v === '1' || $v === 1 || $v === 'true';
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

    public function describeEnabled(): bool
    {
        $v = $this->settings->get(self::S . '.whitelist_describe_enabled');
        return $this->isEnabled() && ($v === true || $v === '1' || $v === 1 || $v === 'true');
    }

    public function audience(): string
    {
        return $this->settings->get(self::S . '.whitelist_audience') === 'members' ? 'members' : 'guests';
    }

    public function copyImages(): bool
    {
        $v = $this->settings->get(self::S . '.whitelist_image_copy');
        return $this->isEnabled() && ($v === true || $v === '1' || $v === 1 || $v === 'true');
    }

    public function describePaused(): bool
    {
        return $this->describePausedUntil() !== null;
    }

    public function describePausedUntil(): ?int
    {
        $r = $this->store->read(self::DESCRIBE_OFF_KEY);
        $until = (int) ($r['value']['until'] ?? 0);
        return $until > time() ? $until : null;
    }

    public function resumeDescribe(): void
    {
        try { $this->store->write(self::DESCRIBE_OFF_KEY, ['until' => 0], 5); } catch (\Throwable $e) {}
    }

    private function pauseDescribe(): void
    {
        try { $this->store->write(self::DESCRIBE_OFF_KEY, ['until' => time() + self::DESCRIBE_OFF_SECONDS], self::DESCRIBE_OFF_SECONDS + 5); } catch (\Throwable $e) {}
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
                // NO_RES_RANGE alone (loopback, link-local, 0/8, 240/4) let a hostname resolve into 10/8,
                // 172.16/12 or 192.168/16 — the private ranges this guard and its error message promise to refuse.
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) return false;
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
        return $this->cooldown() !== null;
    }

    public function cooldown(): ?array
    {
        $r = $this->store->read(self::BACKOFF_KEY);
        $until = (int) ($r['value']['until'] ?? 0);
        if ($until <= time()) return null;
        return ['until' => $until, 'retry_after' => $until - time(), 'reason' => (string) ($r['value']['reason'] ?? '')];
    }

    public function paceDelay(int $calls = 1): int
    {
        $now = time();
        $log = $this->paceLog($now);
        $calls = max(1, min(self::PACE_CALLS, $calls));
        if (count($log) + $calls <= self::PACE_CALLS) return 0;
        return max(1, $log[count($log) + $calls - self::PACE_CALLS - 1] + self::PACE_WINDOW - $now);
    }

    private function paceLog(int $now): array
    {
        $r = $this->store->read(self::PACE_KEY);
        $log = array_values(array_filter((array) ($r['value']['t'] ?? []), fn ($t) => is_int($t) && $t > $now - self::PACE_WINDOW && $t <= $now));
        sort($log);
        return $log;
    }

    private function paceTake(): bool
    {
        try {
            return (bool) $this->store->withLock(self::PACE_KEY, function (): bool {
                $now = time();
                $log = $this->paceLog($now);
                if (count($log) >= self::PACE_CALLS) return false;
                $log[] = $now;
                $this->store->write(self::PACE_KEY, ['t' => $log], self::PACE_WINDOW + 5);
                return true;
            }, true, true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function describeLimits(): array
    {
        $r = $this->store->read(self::LIMITS_KEY);
        $v = is_array($r['value'] ?? null) ? $r['value'] : [];
        return [
            'chars' => isset($v['chars']) ? (int) $v['chars'] : null,
            'bytes' => isset($v['bytes']) ? (int) $v['bytes'] : DescriptionPayload::MAX_BYTES,
            'topics' => isset($v['topics']) ? (int) $v['topics'] : DescriptionPayload::MAX_TOPICS,
            'hashes' => isset($v['hashes']) ? (int) $v['hashes'] : DescriptionPayload::MAX_HASHES,
            'withdraw' => isset($v['withdraw']) ? (bool) $v['withdraw'] : null,
            'at' => isset($v['at']) ? (int) $v['at'] : null,
        ];
    }

    public function limitsStale(): bool
    {
        $at = $this->describeLimits()['at'];
        return $at === null || time() - $at > self::LIMITS_FRESH;
    }

    public static function limitsFrom(array $json, bool $ping, array $current): array
    {
        $fields = $ping
            ? ['chars' => 'describe_max_chars', 'bytes' => 'describe_max_bytes', 'topics' => 'describe_max_topics', 'hashes' => 'describe_max_hashes']
            : ['bytes' => 'max_bytes', 'topics' => 'max_topics', 'hashes' => 'max_hashes'];
        $ranges = ['chars' => [1, 16000000], 'bytes' => [65536, 67108864], 'topics' => [1, 1000], 'hashes' => [1, 1000]];
        $out = array_intersect_key($current, array_flip(['chars', 'bytes', 'topics', 'hashes', 'withdraw', 'at']));
        foreach ($fields as $key => $field) {
            if (isset($json[$field]) && is_numeric($json[$field])) {
                $out[$key] = max($ranges[$key][0], min($ranges[$key][1], (int) $json[$field]));
            }
        }
        if ($ping) {
            $out['withdraw'] = isset($json['describe_max_hashes']);
            $out['at'] = time();
        } elseif (is_array($json['summary'] ?? null) && array_key_exists('withdrawn', $json['summary'])) {
            $out['withdraw'] = true;
        }
        return $out;
    }

    private function noteLimits(array $json, bool $ping): void
    {
        try {
            $r = $this->store->read(self::LIMITS_KEY);
            $current = is_array($r['value'] ?? null) ? $r['value'] : [];
            $next = self::limitsFrom($json, $ping, $current);
            if ($next !== $current) $this->store->write(self::LIMITS_KEY, $next, 7 * 86400);
        } catch (\Throwable $e) {
        }
    }

    public static function consentEchoed(array $json, bool $sent): bool
    {
        if (array_key_exists('copy_images', $json) && is_bool($json['copy_images'])) return $json['copy_images'] === $sent;
        $images = $json['images'] ?? null;
        if ($images === 'copy') return $sent;
        if ($images === 'no_consent') return !$sent;
        return true;
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
        return $this->request('POST', 'v1/whitelist/submit', ['items' => array_values($items), 'source' => $source, 'copy_images' => $this->copyImages()], $timeout ?? $this->timeoutSeconds());
    }

    public function describe(array $topics, int $maxChars, ?int $timeout = null): array
    {
        if (!$topics) return ['ok' => true, 'status' => 0, 'json' => ['results' => [], 'summary' => []], 'error' => null, 'elapsed_ms' => 0];
        if ($this->describePaused()) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'describe_off', 'elapsed_ms' => 0];
        $r = $this->request('POST', 'v1/whitelist/describe', DescriptionPayload::body($topics, $maxChars, $this->copyImages()), $timeout ?? self::DESCRIBE_TIMEOUT, null, ['soft' => true]);
        if ($r['ok'] && ($r['json']['ok'] ?? true) === false) {
            $r['ok'] = false;
            $r['error'] = (string) ($r['json']['error'] ?? 'describe_failed');
            if ($r['error'] === 'describe_off') $this->pauseDescribe();
        }
        if ($r['ok'] && is_array($r['json'])) $this->noteLimits($r['json'], false);
        return $r;
    }

    /** Health check; $override = ['api_url','api_key_id','api_secret'] for unsaved admin values. */
    public function ping(?array $override = null, int $timeout = 6, ?bool $consent = null): array
    {
        $endpoint = 'v1/whitelist/ping' . ($consent === null ? '' : '&copy_images=' . ($consent ? '1' : '0'));
        $r = $this->request('GET', $endpoint, null, $timeout, $override, ['pace' => false]);
        if ($r['ok'] && is_array($r['json']) && ($r['json']['ok'] ?? true) !== false && $this->sameAsSaved($override)) {
            $this->noteLimits($r['json'], true);
        }
        return $r;
    }

    private function sameAsSaved(?array $override): bool
    {
        if (!$override) return true;
        if (isset($override['api_secret'])) return false;
        if (isset($override['api_url']) && self::validateUrl((string) $override['api_url']) !== $this->apiUrl()) return false;
        return !isset($override['api_key_id'])
            || strtolower(trim((string) $override['api_key_id'])) === strtolower(trim((string) $this->settings->get(self::S . '.whitelist_api_key_id')));
    }

    public static function retryAfterSeconds(?string $header, int $default = 30): int
    {
        $header = trim((string) $header);
        if (preg_match('/^\d+$/', $header)) {
            $seconds = (int) $header;
        } else {
            $at = $header !== '' ? strtotime($header) : false;
            $seconds = $at !== false ? $at - time() : $default;
        }
        return max(1, min(3600, $seconds));
    }

    protected function request(string $method, string $endpoint, ?array $body, int $timeout, ?array $override = null, array $flags = []): array
    {
        $t0 = microtime(true);
        $soft = !empty($flags['soft']);
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
        if (($flags['pace'] ?? true) && !$this->paceTake()) {
            return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'paced', 'elapsed_ms' => 0, 'retry_after' => $this->paceDelay()];
        }

        $url = $base . '/api.php?endpoint=' . $endpoint;
        $ch = curl_init($url);
        if ($ch === false) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'curl_init_failed', 'elapsed_ms' => 0];
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $keyId . '.' . $secret, 'Expect:'];
        $retryAfter = null;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => max(1, min(5, $timeout)), // a congested host needs more than 2 s to accept; the cooldown bounds the cost
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Flarum/2.0 HomepageBlocks-WhitelistSync',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) $retryAfter = trim(substr($line, 12));
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
        elseif (defined('CURLOPT_PROTOCOLS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        if (defined('CURLOPT_MAXFILESIZE')) $opts[CURLOPT_MAXFILESIZE] = self::MAX_RESPONSE_BYTES;
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, self::JSON_FLAGS);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = $raw === false ? curl_error($ch) : '';
        unset($ch); // frees the handle (curl_close() is a deprecated no-op since PHP 8.5)
        $elapsed = (int) ((microtime(true) - $t0) * 1000);

        if ($raw === false || $raw === null) {
            if (!$soft) $this->setCooldown(60, 'transport');
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
        if ($status === 413) {
            return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => 'http_413' . ($json && isset($json['error']) ? ':' . $json['error'] : ''), 'elapsed_ms' => $elapsed];
        }
        if ($status === 429) {
            $wait = self::retryAfterSeconds($retryAfter ?? (isset($json['retry_after']) && is_numeric($json['retry_after']) ? (string) (int) $json['retry_after'] : null), 60);
            $this->setCooldown($wait, 'http_429');
            $this->noteError('Tracker returned HTTP 429' . ($json && isset($json['error']) ? ' (' . $json['error'] . ')' : '') . ' — waiting ' . $wait . ' s');
            return ['ok' => false, 'status' => 429, 'json' => $json, 'error' => 'http_429' . ($json && isset($json['error']) ? ':' . $json['error'] : ''), 'elapsed_ms' => $elapsed, 'retry_after' => $wait];
        }
        if ($status < 200 || $status >= 300) {
            if (!$soft) $this->setCooldown($status >= 500 ? 60 : 30, 'http_' . $status);
            $this->noteError('Tracker returned HTTP ' . $status . ($json && isset($json['error']) ? ' (' . $json['error'] . ')' : ''));
            return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => 'http_' . $status . ($json && isset($json['error']) ? ':' . $json['error'] : ''), 'elapsed_ms' => $elapsed];
        }
        if ($json === null) {
            if (!$soft) $this->setCooldown(60, 'invalid_json');
            $this->noteError('Tracker returned a non-JSON response');
            return ['ok' => false, 'status' => $status, 'json' => null, 'error' => 'invalid_json', 'elapsed_ms' => $elapsed];
        }
        $this->clearCooldown();
        $this->noteSuccess();
        return ['ok' => true, 'status' => $status, 'json' => $json, 'error' => null, 'elapsed_ms' => $elapsed];
    }
}
