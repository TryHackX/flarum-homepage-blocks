import app from 'flarum/forum/app';
import RateLimitNotice from '../components/RateLimitNotice';

const S = 'tryhackx-homepage-blocks';

/**
 * Czy wartość ustawienia (bool-ish) jest prawdziwa?
 * Obsługuje true, 1, '1', 'true'. Nieustawione (null/undefined) traktujemy osobno.
 */
function isTruthy(value) {
    if (value === true || value === 1 || value === '1' || value === 'true') return true;
    return false;
}

function attr(key) {
    return app.forum.attribute(S + '.' + key);
}

function scopeEnabled(scope) {
    const raw = attr('recaptcha_on_' + scope);
    return (raw === null || raw === undefined) ? true : isTruthy(raw);
}

function skipsAuthenticated() {
    const raw = attr('recaptcha_skip_authenticated');
    const skip = (raw === null || raw === undefined) ? true : isTruthy(raw);
    return skip && app.session && app.session.user;
}

/**
 * Czy dla tego zakresu należy pobierać token reCAPTCHA?
 * (Wymaga włączonej reCAPTCHA — w trybie samych punktów/blokady zwraca false.)
 * Zakresy: 'random' | 'stats' | 'external_stats' | 'search'
 */
export function recaptchaRequiredFor(scope) {
    if (!isTruthy(attr('recaptcha_enabled'))) return false;
    if (!scopeEnabled(scope)) return false;
    if (skipsAuthenticated()) return false;
    return true;
}

/**
 * Czy limiter punktowy jest włączony globalnie?
 */
export function pointsEnabled() {
    return isTruthy(attr('recaptcha_points_enabled'));
}

/**
 * Czy dla tego zakresu w ogóle działa bramka ochrony (reCAPTCHA LUB punkty)?
 * Decyduje, czy robić pre-flight. Lustrzane do logiki serwera: w trybie
 * punktowym mierzone są tylko akcje 'random' i 'search'.
 */
export function guardActiveFor(scope) {
    const recaptcha = isTruthy(attr('recaptcha_enabled'));
    const points = pointsEnabled();
    if (!recaptcha && !points) return false;
    if (!scopeEnabled(scope)) return false;
    if (skipsAuthenticated()) return false;

    if (points) {
        // Statystyki są pasywne — serwer ich nie mierzy w trybie punktowym.
        return scope === 'random' || scope === 'search';
    }

    // Tryb klasycznej reCAPTCHA chroni wszystkie włączone zakresy.
    return true;
}

/**
 * Czy odpowiedź oznacza tymczasową blokadę IP (zbyt wiele akcji)?
 */
export function isRateLimitedResponse(status, body) {
    return status === 429 || (body && (body.blocked === true || body.error === 'rate_limited'));
}

/**
 * Pokaż profesjonalne powiadomienie o blokadzie z odliczaniem.
 * Nie spiętrza wielu alertów naraz.
 */
export function showRateLimitNotice(retryAfter) {
    const seconds = Math.max(1, parseInt(retryAfter, 10) || 1);

    if (app._homepageRateLimitAlert) {
        try { app.alerts.dismiss(app._homepageRateLimitAlert); } catch (e) {}
        app._homepageRateLimitAlert = null;
    }

    let key;
    const onDone = () => {
        if (key) {
            try { app.alerts.dismiss(key); } catch (e) {}
        }
        if (app._homepageRateLimitAlert === key) app._homepageRateLimitAlert = null;
    };

    key = app.alerts.show({ type: 'error', dismissible: true }, m(RateLimitNotice, { seconds, onDone }));
    app._homepageRateLimitAlert = key;
}

/**
 * Pobierz token reCAPTCHA v3 dla akcji. Zwraca null, gdy reCAPTCHA nie jest
 * wymagana dla zakresu albo generowanie tokenu się nie powiodło.
 */
export async function getRecaptchaToken(scope) {
    if (!recaptchaRequiredFor(scope)) {
        return null;
    }

    const siteKey = attr('recaptcha_site_key');
    const version = attr('recaptcha_version') || 'v3';

    // Dla v2 token zbiera modal (obsługiwane gdzie indziej).
    if (!siteKey || version !== 'v3' || !window.grecaptcha) {
        return null;
    }

    try {
        await new Promise((resolve) => window.grecaptcha.ready(resolve));
        const token = await window.grecaptcha.execute(siteKey, { action: scope });
        return token || null;
    } catch (e) {
        return null;
    }
}

/**
 * Zbierz nagłówki uwierzytelnienia reCAPTCHA dla zakresu. Token jedzie w
 * nagłówku `X-Recaptcha-Token`, a NIE w query stringu — dzięki temu nie ląduje
 * w logach dostępu serwera/proxy (które rutynowo zapisują pełne URI). Zwraca
 * pusty obiekt, gdy token nie jest dostępny/wymagany dla zakresu.
 */
export async function recaptchaHeaders(scope) {
    const token = await getRecaptchaToken(scope);
    return token ? { 'X-Recaptcha-Token': token } : {};
}

/**
 * Pre-flight: zapytaj serwer, czy akcja jest teraz dozwolona dla dzwoniącego.
 * W trybie punktowym dekrementuje kubełek; w klasycznym waliduje token.
 *
 * Zwraca:
 *   { ok: true, balance, cost, refilled }
 *   { ok: false, captchaRequired: true, balance }
 *   { ok: false, blocked: true, retryAfter, balance }
 *   { ok: false, captchaRequired: false, error }
 */
export async function preflightCheck(scope, token = null) {
    const url = app.forum.attribute('apiUrl') + '/tryhackx/homepage/points/check?action=' + encodeURIComponent(scope);

    // Token w nagłówku (nie w URL) — patrz recaptchaHeaders().
    const headers = { 'Accept': 'application/json' };
    const tok = token || (await getRecaptchaToken(scope));
    if (tok) {
        headers['X-Recaptcha-Token'] = tok;
    }

    try {
        // Celowo surowy fetch(), a NIE app.request(): pre-flight traktuje 403
        // (captcha_required) oraz 429 (rate_limited) jako NORMALNY przepływ sterowania
        // i obsługuje je sam (patrz niżej). app.request() przy 4xx/5xx odrzuca obietnicę
        // i pokazuje domyślny alert błędu Flarum — tu byłby mylący i dublowałby nasze
        // własne powiadomienia. To same-origin GET z ciasteczkami sesji, więc nie
        // potrzebuje pipeline'u app.request() (CSRF/nagłówki). Audyt #7.
        const res = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers,
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data && data.ok) {
            return {
                ok: true,
                balance: data.balance ?? null,
                cost: data.cost ?? null,
                refilled: !!data.refilled,
            };
        }
        if (isRateLimitedResponse(res.status, data)) {
            return {
                ok: false,
                blocked: true,
                retryAfter: (data && (data.retry_after ?? data.retryAfter)) ?? 0,
                balance: (data && data.balance) ?? 0,
            };
        }
        if (res.status === 403 && data && data.captcha_required) {
            return {
                ok: false,
                captchaRequired: true,
                balance: data.balance ?? 0,
                cost: data.cost ?? null,
            };
        }
        return {
            ok: false,
            captchaRequired: false,
            error: (data && data.error) || ('HTTP ' + res.status),
        };
    } catch (e) {
        return { ok: false, captchaRequired: false, error: e.message || 'network error' };
    }
}

/**
 * Pokaż CaptchaModal i rozwiąż tokenem, albo null po zamknięciu.
 * Importowany leniwie, by uniknąć cyklicznej zależności z plikiem komponentu.
 */
export function showCaptchaModal(scope) {
    return new Promise((resolve) => {
        const CaptchaModal = require('../components/CaptchaModal').default;
        app.modal.show(CaptchaModal, {
            scope,
            onSuccess: (token) => resolve(token),
            onDismiss: () => resolve(null),
        });
    });
}
