<?php

namespace TryHackX\HomepageBlocks\Security;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared reCAPTCHA gate for homepage-blocks API endpoints.
 *
 * Scope keys: 'random', 'stats', 'external_stats', 'search'.
 *
 * Two modes:
 *   1. Classic (points disabled) — every allowed request must carry a valid
 *      reCAPTCHA token.
 *   2. Points-based — each IP has a balance. Requests decrement it; when the
 *      balance drops below the action's cost, a captcha is required to refill.
 *
 * verify() returns an array with keys:
 *   - ok             bool   — whether the request may proceed
 *   - captcha_required bool — if points are exhausted and no valid token provided
 *   - balance        ?float — remaining points (points mode only)
 *   - cost           ?float — cost charged for this action (points mode only)
 */
class RecaptchaGuard
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var PointsManager|null
     */
    protected $points;

    public function __construct(SettingsRepositoryInterface $settings, ?PointsManager $points = null)
    {
        $this->settings = $settings;
        $this->points = $points;
    }

    /**
     * Back-compat helper returning a simple bool.
     */
    public function check(ServerRequestInterface $request, string $scope): bool
    {
        return $this->verify($request, $scope)['ok'];
    }

    /**
     * Full verification, returning ok/balance/captcha_required.
     */
    public function verify(ServerRequestInterface $request, string $scope): array
    {
        // Global toggle — nothing to enforce when disabled
        if (!(bool) $this->settings->get('tryhackx-homepage-blocks.recaptcha_enabled')) {
            return ['ok' => true];
        }

        // Per-scope toggle — unset defaults to true (on)
        $scopeSetting = 'tryhackx-homepage-blocks.recaptcha_on_' . $scope;
        $scopeValue = $this->settings->get($scopeSetting);
        $scopeEnabled = ($scopeValue === null) ? true : (bool) $scopeValue;
        if (!$scopeEnabled) {
            return ['ok' => true];
        }

        // Skip for authenticated users when configured
        $skipAuthRaw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_skip_authenticated');
        $skipAuth = ($skipAuthRaw === null) ? true : (bool) $skipAuthRaw;
        $isGuest = true;
        try {
            $actor = RequestUtil::getActor($request);
            if ($actor && $actor->exists && !$actor->isGuest()) {
                $isGuest = false;
                if ($skipAuth) {
                    return ['ok' => true];
                }
            }
        } catch (\Throwable $e) {
            // assume guest on failure
        }

        $token = $this->extractToken($request);

        // ── Points mode ──
        if ($this->points && $this->points->isEnabled()) {
            $ip = $this->points->getIp($request);
            $cost = $this->points->getCost($scope, $isGuest);

            // Try to charge the bucket
            $charge = $this->points->charge($ip, $cost);
            if ($charge['ok']) {
                return [
                    'ok' => true,
                    'balance' => $charge['balance'],
                    'cost' => $cost,
                ];
            }

            // Insufficient balance — require captcha this time
            if ($token && $this->verifyToken($token)) {
                $newBalance = $this->points->refillToStart($ip);
                // Deduct cost from fresh balance
                $charge = $this->points->charge($ip, $cost);
                return [
                    'ok' => true,
                    'refilled' => true,
                    'balance' => $charge['balance'] ?? $newBalance,
                    'cost' => $cost,
                ];
            }

            return [
                'ok' => false,
                'captcha_required' => true,
                'balance' => $charge['balance'],
                'cost' => $cost,
            ];
        }

        // ── Classic mode: every request must carry a valid token ──
        if (!$token || !$this->verifyToken($token)) {
            return ['ok' => false];
        }

        return ['ok' => true];
    }

    protected function extractToken(ServerRequestInterface $request): ?string
    {
        $token = $request->getQueryParams()['recaptcha_token'] ?? null;
        if ($token) {
            return (string) $token;
        }
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['recaptcha_token'])) {
            return (string) $body['recaptcha_token'];
        }
        return null;
    }

    /**
     * Call Google's siteverify endpoint and check the response.
     * For v3, the score must be >= the configured threshold.
     */
    public function verifyToken(string $token): bool
    {
        $secretKey = $this->settings->get('tryhackx-homepage-blocks.recaptcha_secret_key');
        if (!$secretKey) {
            return false;
        }

        $version = $this->settings->get('tryhackx-homepage-blocks.recaptcha_version') ?: 'v3';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => $secretKey,
            'response' => $token,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return false;
        }

        $result = json_decode($response, true);
        if (!$result || !($result['success'] ?? false)) {
            return false;
        }

        if ($version === 'v3' && isset($result['score'])) {
            $threshold = $this->getThreshold();
            if ((float) $result['score'] < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve v3 threshold from settings, clamped to [0.0, 1.0].
     * Default is 0.5 when unset or invalid.
     */
    protected function getThreshold(): float
    {
        $raw = $this->settings->get('tryhackx-homepage-blocks.recaptcha_v3_threshold');
        if ($raw === null || $raw === '') {
            return 0.5;
        }
        $value = (float) $raw;
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }
        return $value;
    }
}
