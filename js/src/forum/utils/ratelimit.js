import app from 'flarum/forum/app';
import RateLimitNotice from '../components/RateLimitNotice';

const S = 'tryhackx-homepage-blocks';

/**
 * Czy wartość ustawienia (bool-ish) jest prawdziwa?
 * Obsługuje true, 1, '1', 'true'.
 */
function isTruthy(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

function attr(key) {
    return app.forum.attribute(S + '.' + key);
}

/**
 * Czy limiter punktowy jest włączony?
 */
export function pointsEnabled() {
    return isTruthy(attr('ratelimit_enabled'));
}

/**
 * Czy dla danego zakresu robimy pre-flight limitera? Mierzone jest wyłącznie
 * 'search' (statystyki są pasywne). Lustrzane do logiki serwera (RateLimiter).
 */
export function guardActiveFor(scope) {
    if (!pointsEnabled()) return false;
    if (scope !== 'search') return false;
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
 * Pre-flight: zapytaj serwer, czy akcja jest teraz dozwolona dla dzwoniącego
 * (dekrementuje kubełek punktów per-IP).
 *
 * Zwraca:
 *   { ok: true, balance }
 *   { ok: false, blocked: true, retryAfter }
 *   { ok: false, error }
 */
export async function preflightCheck(scope) {
    const url = app.forum.attribute('apiUrl') + '/tryhackx/homepage/points/check?action=' + encodeURIComponent(scope);

    const headers = { 'Accept': 'application/json' };
    // Endpoint jest POST (mutuje kubełek punktów — audyt H2), więc dokładamy token
    // CSRF, którego rdzeń wymaga dla metod innych niż GET.
    if (app.session && app.session.csrfToken) {
        headers['X-CSRF-Token'] = app.session.csrfToken;
    }

    try {
        // Celowo surowy fetch(), a NIE app.request(): pre-flight traktuje 429
        // (rate_limited) jako NORMALNY przepływ sterowania i obsługuje je sam.
        // app.request() przy 4xx odrzuca obietnicę i pokazuje domyślny alert Flarum,
        // który dublowałby nasze własne odliczanie. Same-origin POST z ciasteczkami
        // sesji + ręcznie dołożony nagłówek CSRF.
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data && data.ok) {
            return { ok: true, balance: data.balance ?? null };
        }
        if (isRateLimitedResponse(res.status, data)) {
            return {
                ok: false,
                blocked: true,
                retryAfter: (data && (data.retry_after ?? data.retryAfter)) ?? 0,
            };
        }
        return { ok: false, error: (data && data.error) || ('HTTP ' + res.status) };
    } catch (e) {
        return { ok: false, error: e.message || 'network error' };
    }
}
