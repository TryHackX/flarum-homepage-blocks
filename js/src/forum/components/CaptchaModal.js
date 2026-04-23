import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import app from 'flarum/forum/app';

/**
 * Modal shown when the points bucket for a given action is exhausted. The user
 * solves a reCAPTCHA challenge; on success, the parent receives the token and
 * the server refills the bucket on the next request.
 *
 * Props:
 *   - scope: action scope ('search' | 'random' | 'stats' | 'external_stats')
 *   - onSuccess: called with the reCAPTCHA token string
 *   - onDismiss: called when the modal is closed without a token
 */
export default class CaptchaModal extends Modal {
    oninit(vnode) {
        super.oninit(vnode);
        this.status = 'idle'; // idle | verifying | failed | success
        this.token = null;
        this.widgetId = null;
        this._scriptLoaded = false;
    }

    className() {
        return 'HomepageBlocks-CaptchaModal Modal--small';
    }

    title() {
        return app.translator.trans('tryhackx-homepage-blocks.forum.captcha_title');
    }

    onDismiss() {
        if (this.attrs && typeof this.attrs.onDismiss === 'function') {
            this.attrs.onDismiss();
        }
    }

    hide() {
        this.onDismiss();
        super.hide();
    }

    oncreate(vnode) {
        super.oncreate(vnode);
        this.loadRecaptchaScript().then(() => this.startChallenge());
    }

    onremove(vnode) {
        super.onremove && super.onremove(vnode);
    }

    content() {
        const siteKey = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_site_key');
        const version = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_version') || 'v3';

        return m('div', { className: 'Modal-body HomepageBlocks-CaptchaModal-body' }, [
            m('p', { className: 'HomepageBlocks-CaptchaModal-description' },
                app.translator.trans('tryhackx-homepage-blocks.forum.captcha_description')
            ),

            !siteKey
                ? m('div', { className: 'Alert Alert--error' },
                    app.translator.trans('tryhackx-homepage-blocks.forum.captcha_failed'))
                : null,

            version === 'v2' && siteKey
                ? m('div', { className: 'HomepageBlocks-CaptchaModal-widget', id: 'homepage-blocks-captcha-widget' })
                : null,

            this.status === 'verifying'
                ? m('div', { className: 'HomepageBlocks-CaptchaModal-status' }, [
                    m(LoadingIndicator, { size: 'small', display: 'inline' }),
                    m('span', ' ' + this.transStr('tryhackx-homepage-blocks.forum.captcha_verifying')),
                ])
                : null,

            this.status === 'failed'
                ? m('div', { className: 'HomepageBlocks-CaptchaModal-error' },
                    app.translator.trans('tryhackx-homepage-blocks.forum.captcha_failed'))
                : null,

            m('div', { className: 'HomepageBlocks-CaptchaModal-actions' }, [
                this.status === 'failed'
                    ? m(Button, {
                        className: 'Button Button--primary',
                        onclick: () => this.retry(),
                    }, this.transStr('tryhackx-homepage-blocks.forum.captcha_retry'))
                    : null,
                m(Button, {
                    className: 'Button',
                    onclick: () => this.hide(),
                }, this.transStr('tryhackx-homepage-blocks.forum.captcha_close')),
            ]),
        ]);
    }

    transStr(key) {
        const result = app.translator.trans(key);
        if (Array.isArray(result)) {
            return result.map((item) => (typeof item === 'string' ? item : '')).join('');
        }
        return String(result || '');
    }

    loadRecaptchaScript() {
        return new Promise((resolve) => {
            if (window.grecaptcha && window.grecaptcha.execute) {
                resolve();
                return;
            }
            if (this._scriptLoaded) {
                resolve();
                return;
            }

            const siteKey = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_site_key');
            if (!siteKey) {
                resolve();
                return;
            }

            const version = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_version') || 'v3';
            const src = version === 'v2'
                ? 'https://www.google.com/recaptcha/api.js?render=explicit'
                : 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(siteKey);

            const existing = document.querySelector('script[data-homepage-blocks-recaptcha="1"]');
            if (existing) {
                this._scriptLoaded = true;
                const wait = () => {
                    if (window.grecaptcha && window.grecaptcha.ready) resolve();
                    else setTimeout(wait, 100);
                };
                wait();
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.defer = true;
            script.dataset.homepageBlocksRecaptcha = '1';
            script.onload = () => {
                this._scriptLoaded = true;
                const wait = () => {
                    if (window.grecaptcha && window.grecaptcha.ready) resolve();
                    else setTimeout(wait, 100);
                };
                wait();
            };
            script.onerror = () => resolve();
            document.head.appendChild(script);
        });
    }

    startChallenge() {
        const siteKey = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_site_key');
        if (!siteKey || !window.grecaptcha) {
            this.status = 'failed';
            m.redraw();
            return;
        }

        const version = app.forum.attribute('tryhackx-homepage-blocks.recaptcha_version') || 'v3';

        if (version === 'v2') {
            this.renderV2Widget(siteKey);
        } else {
            this.executeV3(siteKey);
        }
    }

    renderV2Widget(siteKey) {
        const scope = (this.attrs && this.attrs.scope) || 'search';
        window.grecaptcha.ready(() => {
            const container = document.getElementById('homepage-blocks-captcha-widget');
            if (!container) {
                this.status = 'failed';
                m.redraw();
                return;
            }
            try {
                this.widgetId = window.grecaptcha.render(container, {
                    sitekey: siteKey,
                    callback: (token) => this.onToken(token),
                    'expired-callback': () => {
                        this.status = 'failed';
                        m.redraw();
                    },
                    'error-callback': () => {
                        this.status = 'failed';
                        m.redraw();
                    },
                });
            } catch (e) {
                this.status = 'failed';
                m.redraw();
            }
        });
    }

    executeV3(siteKey) {
        const scope = (this.attrs && this.attrs.scope) || 'search';
        this.status = 'verifying';
        m.redraw();

        window.grecaptcha.ready(() => {
            window.grecaptcha.execute(siteKey, { action: scope })
                .then((token) => this.onToken(token))
                .catch(() => {
                    this.status = 'failed';
                    m.redraw();
                });
        });
    }

    onToken(token) {
        if (!token) {
            this.status = 'failed';
            m.redraw();
            return;
        }
        this.token = token;
        this.status = 'success';
        if (this.attrs && typeof this.attrs.onSuccess === 'function') {
            this.attrs.onSuccess(token);
        }
        // Close without dispatching onDismiss
        this.attrs.onDismiss = null;
        super.hide();
    }

    retry() {
        this.status = 'idle';
        this.token = null;
        if (this.widgetId !== null && window.grecaptcha && window.grecaptcha.reset) {
            try { window.grecaptcha.reset(this.widgetId); } catch (e) {}
        }
        this.startChallenge();
        m.redraw();
    }
}
