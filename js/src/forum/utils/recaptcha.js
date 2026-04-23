import app from 'flarum/forum/app';

/**
 * Check whether a Flarum boolean-ish setting attribute is truthy.
 * Handles true, 1, '1', 'true'. Treats unset (null/undefined) as default (true for scope toggles).
 */
function isTruthy(value) {
    if (value === true || value === 1 || value === '1' || value === 'true') return true;
    return false;
}

/**
 * Should reCAPTCHA be applied for this scope?
 * Scopes: 'random' | 'stats' | 'external_stats' | 'search'
 */
export function recaptchaRequiredFor(scope) {
    // Global toggle
    if (!isTruthy(app.forum.attribute('tryhackx-homepage-blocks.recaptcha_enabled'))) {
        return false;
    }

    // Per-scope toggle — default true when unset
    const raw = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_on_' + scope);
    const scopeEnabled = (raw === null || raw === undefined) ? true : isTruthy(raw);
    if (!scopeEnabled) {
        return false;
    }

    // Skip for authenticated users when configured (default: true)
    const skipAuthRaw = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_skip_authenticated');
    const skipAuth = (skipAuthRaw === null || skipAuthRaw === undefined) ? true : isTruthy(skipAuthRaw);
    if (skipAuth && app.session && app.session.user) {
        return false;
    }

    return true;
}

/**
 * Is the points-based system enabled globally?
 */
export function pointsEnabled() {
    return isTruthy(app.forum.attribute('tryhackx-homepage-blocks.recaptcha_points_enabled'));
}

/**
 * Obtain a reCAPTCHA v3 token for a given action. Returns null if reCAPTCHA
 * is not required for this scope, or if token generation fails.
 */
export async function getRecaptchaToken(scope) {
    if (!recaptchaRequiredFor(scope)) {
        return null;
    }

    const siteKey = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_site_key');
    const version = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_version') || 'v3';

    // For v2 we return whatever the modal collects (handled elsewhere).
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
 * Append recaptcha_token to a URL if a token is available for this scope.
 */
export async function appendRecaptchaToken(url, scope) {
    const token = await getRecaptchaToken(scope);
    if (!token) return url;
    const sep = url.indexOf('?') !== -1 ? '&' : '?';
    return url + sep + 'recaptcha_token=' + encodeURIComponent(token);
}

/**
 * Pre-flight check: ask the server whether this action is allowed for the
 * caller right now. In points mode this decrements the bucket; in classic
 * mode it validates the current token.
 *
 * Returns an object:
 *   { ok: true, balance, cost, refilled }
 *   { ok: false, captchaRequired: true, balance }
 *   { ok: false, captchaRequired: false, error }
 */
export async function preflightCheck(scope, token = null) {
    let url = app.forum.attribute('apiUrl') + '/tryhackx/homepage/points/check?action=' + encodeURIComponent(scope);
    if (token) {
        url += '&recaptcha_token=' + encodeURIComponent(token);
    } else {
        // Still attach a v3 token if we can — covers classic mode
        const silent = await getRecaptchaToken(scope);
        if (silent) {
            url += '&recaptcha_token=' + encodeURIComponent(silent);
        }
    }

    try {
        const res = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
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
 * High-level helper: run a guarded action for a given scope. If the server
 * says a captcha is required, show the CaptchaModal; on success retry the
 * action.
 *
 * `run` is an async function called with { token } — token is non-null only
 * after a successful captcha refill.
 *
 * Returns whatever `run()` returned, or null if the user dismissed the
 * captcha modal.
 */
export async function guardedAction(scope, run) {
    // Nothing to do if the scope is disabled — just run it
    if (!recaptchaRequiredFor(scope)) {
        return await run({ token: null });
    }

    // In points mode the server decides when a captcha is needed. We optimistically
    // run the action; if it comes back with captcha_required, show the modal and retry.
    try {
        const result = await run({ token: null });
        return result;
    } catch (err) {
        if (err && err.captchaRequired) {
            const token = await showCaptchaModal(scope);
            if (!token) return null;
            return await run({ token });
        }
        throw err;
    }
}

/**
 * Show the CaptchaModal and resolve with a token, or null if dismissed.
 * Imported lazily to avoid a circular dep with the component file.
 */
export function showCaptchaModal(scope) {
    return new Promise((resolve) => {
        // Lazy require to avoid ES module circular reference issues
        const CaptchaModal = require('../components/CaptchaModal').default;
        app.modal.show(CaptchaModal, {
            scope,
            onSuccess: (token) => resolve(token),
            onDismiss: () => resolve(null),
        });
    });
}

/**
 * Helper to normalise a 403 captcha_required response into a thrown error
 * that guardedAction recognises.
 */
export function isCaptchaRequiredResponse(status, body) {
    return status === 403 && body && (body.captcha_required || body.error === 'captcha_required');
}
