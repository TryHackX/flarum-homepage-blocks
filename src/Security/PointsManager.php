<?php

namespace TryHackX\HomepageBlocks\Security;

use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-IP point bucket used for rate limiting.
 *
 * Storage: storage/cache/tryhackx_points/{hash}.json — one file per IP.
 * File contents: {"balance": <float>, "ts": <unix>}.
 *
 * Actions are charged against the bucket when the request arrives; if the
 * balance drops below the action's cost, a captcha is required to refill.
 */
class PointsManager
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_enabled');
    }

    /**
     * Resolve the cost for a given action scope (with guest surcharge applied).
     */
    public function getCost(string $scope, bool $isGuest): float
    {
        $defaults = [
            'random' => 0.5,
            'search' => 3.0,
            'stats' => 1.0,
            'external_stats' => 1.0,
        ];

        $key = 'tryhackx-homepage-blocks.recaptcha_points_cost_' . $scope;
        $raw = $this->settings->get($key);
        $cost = ($raw === null || $raw === '') ? ($defaults[$scope] ?? 1.0) : (float) $raw;

        if ($isGuest) {
            $extra = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_guest_extra');
            $extra = ($extra === null || $extra === '') ? 2.0 : (float) $extra;
            $cost += $extra;
        }

        return max(0.0, $cost);
    }

    public function getStart(): float
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_start');
        return ($raw === null || $raw === '') ? 10.0 : max(0.0, (float) $raw);
    }

    public function getRefillSeconds(): int
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_refill_seconds');
        return ($raw === null || $raw === '') ? 15 : max(1, (int) $raw);
    }

    public function getRefillAmount(): float
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_points_refill_amount');
        return ($raw === null || $raw === '') ? 1.0 : max(0.0, (float) $raw);
    }

    /**
     * Derive a stable IP for the caller. Honours X-Forwarded-For when present,
     * fallback to REMOTE_ADDR. If nothing is set, use a generic "unknown" bucket.
     */
    public function getIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        // Prefer the first entry from X-Forwarded-For if behind a proxy
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff) {
            $parts = explode(',', $xff);
            $candidate = trim($parts[0]);
            if ($candidate && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        $real = $request->getHeaderLine('X-Real-IP');
        if ($real && filter_var(trim($real), FILTER_VALIDATE_IP)) {
            return trim($real);
        }

        $remote = $server['REMOTE_ADDR'] ?? '';
        if ($remote && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return 'unknown';
    }

    public function isGuest(ServerRequestInterface $request): bool
    {
        try {
            $actor = RequestUtil::getActor($request);
            if ($actor && $actor->exists && !$actor->isGuest()) {
                return false;
            }
        } catch (\Throwable $e) {
            // treat as guest on failure
        }
        return true;
    }

    /**
     * Load the current balance for an IP, applying any pending refills.
     * Returns the effective balance; silently persists the refilled value.
     */
    public function getBalance(string $ip): float
    {
        $state = $this->readState($ip);
        $state = $this->applyRefill($state);
        $this->writeState($ip, $state);
        return (float) $state['balance'];
    }

    /**
     * Attempt to charge the given cost against the IP's bucket.
     * Returns array:
     *   ['ok' => true, 'balance' => float]         — cost deducted
     *   ['ok' => false, 'balance' => float]        — insufficient points
     */
    public function charge(string $ip, float $cost): array
    {
        $state = $this->readState($ip);
        $state = $this->applyRefill($state);

        if ($state['balance'] < $cost) {
            $this->writeState($ip, $state);
            return ['ok' => false, 'balance' => (float) $state['balance']];
        }

        $state['balance'] = max(0.0, $state['balance'] - $cost);
        $state['ts'] = time();
        $this->writeState($ip, $state);
        return ['ok' => true, 'balance' => (float) $state['balance']];
    }

    /**
     * Refill balance to the starting value (called after successful captcha).
     */
    public function refillToStart(string $ip): float
    {
        $start = $this->getStart();
        $state = ['balance' => $start, 'ts' => time()];
        $this->writeState($ip, $state);
        return $start;
    }

    // ────────────── Internal storage ──────────────

    protected function getDir(): string
    {
        $dir = $this->paths->storage . '/cache/tryhackx_points';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    protected function getFile(string $ip): string
    {
        return $this->getDir() . '/' . sha1($ip) . '.json';
    }

    protected function readState(string $ip): array
    {
        $file = $this->getFile($ip);
        if (!file_exists($file)) {
            return ['balance' => $this->getStart(), 'ts' => time()];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return ['balance' => $this->getStart(), 'ts' => time()];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['balance'], $data['ts'])) {
            return ['balance' => $this->getStart(), 'ts' => time()];
        }

        return [
            'balance' => (float) $data['balance'],
            'ts' => (int) $data['ts'],
        ];
    }

    protected function writeState(string $ip, array $state): void
    {
        $file = $this->getFile($ip);
        @file_put_contents($file, json_encode([
            'balance' => (float) $state['balance'],
            'ts' => (int) $state['ts'],
        ]), LOCK_EX);
    }

    /**
     * Add any pending refill (time elapsed / refill_seconds * refill_amount),
     * capped at the starting balance.
     */
    protected function applyRefill(array $state): array
    {
        $now = time();
        $elapsed = $now - (int) $state['ts'];
        if ($elapsed <= 0) {
            return $state;
        }

        $refillSeconds = $this->getRefillSeconds();
        $refillAmount = $this->getRefillAmount();
        if ($refillAmount <= 0 || $refillSeconds <= 0) {
            return $state;
        }

        $ticks = (int) floor($elapsed / $refillSeconds);
        if ($ticks <= 0) {
            return $state;
        }

        $max = $this->getStart();
        $newBalance = min($max, (float) $state['balance'] + ($ticks * $refillAmount));

        return [
            'balance' => $newBalance,
            'ts' => (int) $state['ts'] + ($ticks * $refillSeconds),
        ];
    }
}
