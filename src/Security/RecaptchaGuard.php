<?php

namespace TryHackX\HomepageBlocks\Security;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Wspólna bramka ochrony dla endpointów homepage-blocks.
 *
 * Zakresy: 'random', 'stats', 'external_stats', 'search'.
 *
 * Trzy tryby działania (mogą się łączyć):
 *   1. Limiter punktowy WYŁĄCZONY, reCAPTCHA WŁĄCZONA — każde dozwolone żądanie
 *      musi nieść poprawny token reCAPTCHA (tryb klasyczny).
 *   2. Limiter punktowy WŁĄCZONY, egzekwowanie = 'captcha' — kubełek punktów per
 *      IP; po wyczerpaniu wymagane jest reCAPTCHA, które odnawia kubełek.
 *   3. Limiter punktowy WŁĄCZONY, egzekwowanie = 'block' — kubełek punktów per IP;
 *      po wyczerpaniu IP jest tymczasowo blokowane na czas z ustawień
 *      (NIE wymaga reCAPTCHA). To pozwala chronić forum bez Google reCAPTCHA.
 *
 * verify() zwraca tablicę z kluczami:
 *   - ok               bool   — czy żądanie może przejść
 *   - captcha_required bool   — punkty wyczerpane, wymagane reCAPTCHA
 *   - blocked          bool   — IP tymczasowo zablokowane
 *   - retry_after      ?int   — za ile sekund można spróbować ponownie (blokada)
 *   - balance          ?float — pozostałe punkty (tryb punktowy)
 *   - cost             ?float — koszt pobrany za tę akcję (tryb punktowy)
 *   - refilled         ?bool  — czy kubełek odnowiono po captcha
 */
class RecaptchaGuard
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected PointsManager $points
    ) {}

    /**
     * Pomocnik wstecznie zgodny zwracający proste bool.
     */
    public function check(ServerRequestInterface $request, string $scope): bool
    {
        return $this->verify($request, $scope)['ok'];
    }

    /**
     * Pełna weryfikacja.
     */
    public function verify(ServerRequestInterface $request, string $scope): array
    {
        $recaptchaEnabled = (bool) $this->settings->get('tryhackx-homepage-blocks.recaptcha_enabled');
        $pointsActive = $this->points && $this->points->isEnabled();

        // Nic nie włączone — nie ma czego egzekwować.
        if (!$recaptchaEnabled && !$pointsActive) {
            return ['ok' => true];
        }

        // Przełącznik per-zakres — brak ustawienia domyślnie = włączone.
        $scopeSetting = 'tryhackx-homepage-blocks.recaptcha_on_' . $scope;
        $scopeValue = $this->settings->get($scopeSetting);
        $scopeEnabled = ($scopeValue === null) ? true : (bool) $scopeValue;
        if (!$scopeEnabled) {
            return ['ok' => true];
        }

        // Pomiń dla zalogowanych, jeśli tak skonfigurowano.
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
            // w razie błędu zakładamy gościa
        }

        $token = $this->extractToken($request);

        // ── Tryb punktowy ──
        if ($pointsActive) {
            // Punktami mierzymy TYLKO akcje inicjowane przez użytkownika
            // (losowanie, wyszukiwanie/filtry). Statystyki są ładowane pasywnie
            // i odświeżane automatycznie — naliczanie za nie punktów blokowałoby
            // zwykłych widzów. Chroni je cache + pojedynczy fetch (flock) po
            // stronie serwera, więc tu po prostu przepuszczamy.
            if (in_array($scope, ['random', 'search'], true)) {
                return $this->verifyPoints($request, $scope, $isGuest, $token, $recaptchaEnabled);
            }
            return ['ok' => true];
        }

        // ── Tryb klasyczny: każde żądanie musi nieść poprawny token ──
        if (!$token || !$this->verifyToken($token)) {
            return ['ok' => false];
        }

        return ['ok' => true];
    }

    /**
     * Logika trybu punktowego z egzekwowaniem captcha/blokada.
     */
    protected function verifyPoints(
        ServerRequestInterface $request,
        string $scope,
        bool $isGuest,
        ?string $token,
        bool $recaptchaEnabled
    ): array {
        $ip = $this->points->getIp($request);
        $cost = $this->points->getCost($scope, $isGuest);

        // 1) Czy IP jest już zablokowane?
        $remaining = $this->points->getBlockRemaining($ip);
        if ($remaining > 0) {
            return [
                'ok' => false,
                'blocked' => true,
                'retry_after' => $remaining,
                'balance' => 0,
                'cost' => $cost,
            ];
        }

        // 2) Spróbuj pobrać koszt.
        $charge = $this->points->charge($ip, $cost);
        if ($charge['ok']) {
            return [
                'ok' => true,
                'balance' => $charge['balance'],
                'cost' => $cost,
            ];
        }

        // 3) Punkty wyczerpane — egzekwowanie.
        $enforcement = $this->points->getEnforcement();
        $canCaptcha = $recaptchaEnabled && $this->captchaConfigured();

        if ($enforcement === 'captcha' && $canCaptcha) {
            if ($token && $this->verifyToken($token)) {
                $newBalance = $this->points->refillToStart($ip);
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

        // 4) Tryb blokady (lub captcha niedostępna) — zablokuj IP na czas z ustawień.
        $seconds = $this->points->block($ip, $this->points->getBlockSeconds());

        return [
            'ok' => false,
            'blocked' => true,
            'retry_after' => $seconds,
            'balance' => 0,
            'cost' => $cost,
        ];
    }

    /**
     * Czy reCAPTCHA jest realnie skonfigurowana (oba klucze)?
     * Bez tego tryb 'captcha' nie ma jak zadziałać i degradujemy do blokady.
     */
    protected function captchaConfigured(): bool
    {
        $site = $this->settings->get('tryhackx-homepage-blocks.recaptcha_site_key');
        $secret = $this->settings->get('tryhackx-homepage-blocks.recaptcha_secret_key');
        return !empty($site) && !empty($secret);
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
     * Wywołanie endpointu siteverify Google i sprawdzenie odpowiedzi.
     * Dla v3 wynik musi być >= skonfigurowanego progu.
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
     * Próg v3 z ustawień, ograniczony do [0.0, 1.0]. Domyślnie 0.5.
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
