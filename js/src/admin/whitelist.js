import app from 'flarum/admin/app';

// ──────────── TRACKER WHITELIST SYNC ────────────
// Enable toggle → animated reveal of the connection settings, "Test connection", and a batched,
// resumable "Scan whole forum" (one HTTP call per batch of posts, cursor kept client-side).
// The API secret is NEVER bound to this.setting(): the admin payload does not contain it (stripped
// server-side) and it is saved through its own endpoint, so a regular Save can't wipe it.
const S = 'tryhackx-homepage-blocks';

function settingIsTruthy(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

function sectionHeader(title) {
    return function () {
        return m('div', { className: 'Form-group HomepageBlocks-sectionHeader' }, [m('h3', title)]);
    };
}

const DESCRIBE_COUNTERS = ['published', 'waiting', 'proposed', 'unchanged', 'kept', 'withdrawn', 'too_long', 'invalid', 'skipped', 'errors'];
const PROBLEMS = ['too_long', 'too_big', 'invalid', 'error', 'failed', 'not_registered', 'registered_elsewhere', 'banned', 'other_topic', 'removed', 'declined'];

function sleep(seconds) {
    return new Promise((resolve) => setTimeout(resolve, Math.max(1, seconds) * 1000));
}

function when(ts) {
    return ts ? new Date(ts * 1000).toLocaleString() : '—';
}

function count(n) {
    return Number(n || 0).toLocaleString();
}

export default function registerWhitelistSettings() {
    const t = (k, params) => app.translator.trans(S + '.admin.whitelist.' + k, params);
    const apiUrl = () => app.forum.attribute('apiUrl');

    // component-local state (survives redraws; reset on page reload)
    const st = {
        secretInput: '',
        secretBusy: false,
        secretMsg: null,
        testBusy: false,
        testResult: null,
        status: null,
        statusBusy: false,
        statusError: false,
        statusAsked: false,
        scan: { running: false, cancel: false, cursor: 0, processed: 0, total: null, hashes: 0, skipped: 0, sent: 0, added: 0, exists: 0, banned: 0, invalid: 0, error: null, done: false, batches: 0, describe: null, describeOff: false, waiting: 0, queued: 0 },
        describeAll: { running: false, cancel: false, cursor: 0, processed: 0, total: null, error: null, done: false, describe: null, describeOff: false, waiting: 0, queued: 0 },
    };

    async function loadStatus() {
        if (st.statusBusy) return;
        st.statusBusy = true; st.statusAsked = true; m.redraw();
        try {
            st.status = await app.request({ method: 'GET', url: apiUrl() + '/tryhackx/homepage/whitelist/status', errorHandler: () => {} });
            st.statusError = false;
        } catch (e) {
            st.statusError = true;
        }
        st.statusBusy = false; m.redraw();
    }

    function addDescribe(s, res) {
        if (!res.describe) return;
        s.describe = s.describe || {};
        DESCRIBE_COUNTERS.forEach((k) => { s.describe[k] = (s.describe[k] || 0) + (res.describe[k] || 0); });
        if (res.describe.off) s.describeOff = true;
    }

    async function waitFor(s, seconds) {
        s.waiting = seconds; m.redraw();
        await sleep(seconds);
        s.waiting = 0; m.redraw();
    }

    function describeLine(d) {
        return t('scan_progress_describe', { published: d.published || 0, waiting: d.waiting || 0, proposed: d.proposed || 0, unchanged: d.unchanged || 0, kept: d.kept || 0, withdrawn: d.withdrawn || 0, tooLong: d.too_long || 0, invalid: d.invalid || 0, skipped: d.skipped || 0, errors: d.errors || 0 });
    }

    function problemText(p) {
        const detail = p.detail || '';
        if (p.status === 'too_long') {
            const match = /^(\d+)\/(\d+)$/.exec(detail);
            if (match) return t('problem_too_long_chars', { chars: count(match[1]), limit: count(match[2]) });
        }
        if (p.status === 'too_big' && /^\d+$/.test(detail)) return t('problem_too_big', { bytes: count(detail) });
        if (p.status === 'failed') return t('problem_failed', { error: detail || '?', attempts: p.attempts, next: when(p.next_at) });
        const text = t('problem_' + (PROBLEMS.includes(p.status) ? p.status : 'other'));
        return detail ? [text, ' (', detail, ')'] : text;
    }

    function consentPending(consent) {
        if (!consent) return null;
        if (consent.wanted && consent.confirmed !== true) return 'consent_pending';
        if (!consent.wanted && consent.confirmed === true) return 'withdraw_pending';
        return null;
    }

    function statusBlock() {
        if (!st.statusAsked && settingIsTruthy(app.data.settings[S + '.whitelist_sync_enabled'])) loadStatus();
        const x = st.status;
        const pending = x ? consentPending(x.consent) : null;
        return m('div', { className: 'HomepageBlocks-whitelistScan HomepageBlocks-whitelistStatus' }, [
            m('div', { className: 'HomepageBlocks-whitelistActions' }, [
                m('strong', t('status_title')),
                m('button', { type: 'button', className: 'Button', disabled: st.statusBusy, onclick: () => loadStatus() }, [
                    m('i', { className: 'fas fa-sync-alt Button-icon icon' + (st.statusBusy ? ' fa-spin' : '') }), ' ', t('status_refresh'),
                ]),
            ]),
            st.statusError ? m('div', { className: 'HomepageBlocks-err' }, t('status_error')) : null,
            !x ? m('div', { className: 'helpText' }, st.statusBusy ? t('status_loading') : '') : m('div', { className: 'HomepageBlocks-progress' }, [
                x.describe && x.describe.enabled ? m('div', { className: 'HomepageBlocks-progressText' }, t('status_topics', { published: count(x.topics.published), waiting: count(x.topics.waiting), proposed: count(x.topics.proposed), unchanged: count(x.topics.unchanged), withdrawn: count(x.topics.withdrawn), errors: count(x.topics.error) })) : null,
                x.describe && x.describe.paused_until ? m('div', { className: 'HomepageBlocks-warn' }, t('status_paused', { time: when(x.describe.paused_until) })) : null,
                x.describe && x.describe.enabled && x.describe.withdraw === false ? m('div', { className: 'HomepageBlocks-warn' }, t('status_no_withdraw')) : null,
                m('div', { className: 'HomepageBlocks-progressText' }, x.queue.pending ? t('status_queue', { pending: count(x.queue.pending), failing: count(x.queue.failing), next: when(x.queue.next_at) }) : t('status_queue_empty')),
                x.cooldown ? m('div', { className: 'HomepageBlocks-warn' }, t('status_cooldown', { seconds: x.cooldown.retry_after })) : null,
                pending ? m('div', { className: 'HomepageBlocks-warn' }, t('status_' + pending)) : null,
                x.problems.length ? [
                    m('div', { className: 'HomepageBlocks-progressText' }, t('status_problems')),
                    m('ul', { className: 'HomepageBlocks-whitelistProblems' }, x.problems.map((p) => m('li', [
                        p.url ? m('a', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, p.title || ('#' + p.discussion_id)) : m('span', t('status_deleted_topic', { id: p.discussion_id })),
                        ' — ', problemText(p), ' · ', m('span', { className: 'helpText' }, when(p.at)),
                    ]))),
                ] : m('div', { className: 'helpText' }, t('status_no_problems')),
            ]),
            m('div', { className: 'helpText' }, t('status_help')),
        ]);
    }

    function secretIsSet() {
        return String(app.data.settings[S + '.whitelist_api_secret_set'] || '') === '1';
    }

    async function saveSecret(page, clear) {
        st.secretBusy = true; st.secretMsg = null; m.redraw();
        try {
            const res = await app.request({ method: 'POST', url: apiUrl() + '/tryhackx/homepage/whitelist/secret', body: clear ? { clear: true } : { secret: st.secretInput.trim() } });
            app.data.settings[S + '.whitelist_api_secret_set'] = res.set ? '1' : '0';
            if (res.key_id !== undefined && res.key_id !== null) {
                // update BOTH the raw settings and the page's memoized Stream, otherwise the input keeps
                // the stale value, the page turns dirty and a later Save writes the old key id back
                app.data.settings[S + '.whitelist_api_key_id'] = res.key_id;
                page.setting(S + '.whitelist_api_key_id')(res.key_id);
            }
            st.secretInput = '';
            st.secretMsg = { type: 'ok', text: clear ? t('secret_cleared') : t('secret_saved') };
        } catch (e) {
            st.secretMsg = { type: 'err', text: t('secret_error') };
        }
        st.secretBusy = false; m.redraw();
    }

    async function testConnection(page) {
        st.testBusy = true; st.testResult = null; m.redraw();
        const body = {
            api_url: page.setting(S + '.whitelist_api_url')() || '',
            api_key_id: page.setting(S + '.whitelist_api_key_id')() || '',
        };
        if (st.secretInput.trim() !== '') body.api_secret = st.secretInput.trim();
        try {
            const res = await app.request({ method: 'POST', url: apiUrl() + '/tryhackx/homepage/whitelist/test', body });
            if (res.ok) {
                const describe = res.describe ? ' ' + t('test_describe', { mode: t('describe_mode_' + res.describe) })
                    : settingIsTruthy(page.setting(S + '.whitelist_describe_enabled')()) ? ' ' + t('test_describe_unknown') : '';
                const pending = consentPending(res.consent);
                const images = res.images === 'copy' && pending === 'withdraw_pending' ? ' ' + t('test_images_withdraw_pending')
                    : res.images === 'copy' ? ' ' + t('test_images_copy', { domains: (res.image_domains || []).join(', ') || '—' })
                    : res.images === 'off' ? ' ' + t('test_images_off')
                    : res.images === 'no_consent' && pending === 'consent_pending' ? ' ' + t('test_images_consent_pending')
                    : res.images === 'no_consent' ? ' ' + t('test_images_no_consent') : '';
                st.testResult = { ok: true, text: t('test_ok', { count: res.whitelist_count === null || res.whitelist_count === undefined ? '?' : res.whitelist_count, mode: res.mode || '?', skew: res.skew_seconds || 0, ms: res.elapsed_ms || 0 }) + describe + images };
            } else {
                const hint = (res.http_status === 403 || res.http_status === 401) ? ' ' + t('test_forbidden_hint') : res.error === 'invalid_credentials' ? ' ' + t('test_invalid_credentials_hint') : '';
                st.testResult = { ok: false, text: t('test_fail', { error: res.error || ('HTTP ' + res.http_status) }) + hint };
            }
        } catch (e) {
            st.testResult = { ok: false, text: t('test_fail', { error: (e && e.response && e.response.error) || 'request failed' }) };
        }
        st.testBusy = false; m.redraw();
        loadStatus();
    }

    function rateWait(e) {
        const body = (e && e.response) || {};
        return e && e.status === 503 && body.reason === 'http_429' ? Math.max(1, Number(body.retry_after) || 60) : 0;
    }

    async function runScan(resume) {
        const s = st.scan;
        if (s.running) return;
        if (!resume) Object.assign(s, { cursor: 0, processed: 0, total: null, hashes: 0, skipped: 0, sent: 0, added: 0, exists: 0, banned: 0, invalid: 0, error: null, done: false, batches: 0, describe: null, describeOff: false, queued: 0 });
        s.running = true; s.cancel = false; s.error = null; m.redraw();
        while (!s.done && !s.cancel) {
            let res;
            try {
                res = await app.request({ method: 'POST', url: apiUrl() + '/tryhackx/homepage/whitelist/scan', body: { cursor: s.cursor }, errorHandler: () => {} });
            } catch (e) {
                const wait = rateWait(e);
                if (wait) {
                    await waitFor(s, wait);
                    continue;
                }
                const status = e && e.status;
                const err = (e && e.response && e.response.error) || ('HTTP ' + status);
                s.error = status === 409 ? t('scan_running') : status === 503 ? t('scan_cooldown') : t('scan_error', { error: err });
                break;
            }
            s.batches++;
            if (res.total_posts !== undefined) s.total = res.total_posts;
            if (!res.ok) {
                s.error = t('scan_error', { error: res.error || 'tracker error' });
                break; // cursor not advanced server-side; Resume retries the same batch
            }
            s.cursor = res.next_cursor;
            s.processed += res.processed;
            s.hashes += res.hashes_found;
            s.skipped += res.skipped_no_tracker || 0; // "only our tracker" filter (older backends omit the field)
            s.sent += res.sent; s.added += res.added; s.exists += res.exists; s.banned += res.banned; s.invalid += res.invalid;
            s.queued += res.queued || 0;
            addDescribe(s, res);
            s.done = !!res.done;
            m.redraw();
            if (res.wait && !s.done) await waitFor(s, res.wait);
        }
        s.running = false;
        m.redraw();
        if (s.done && !s.error) {
            app.alerts.show({ type: 'success' }, t('scan_done', { processed: s.processed, hashes: s.hashes, added: s.added, exists: s.exists, banned: s.banned }));
            if (s.describe) loadStatus();
        }
    }

    function describeSaved() {
        return settingIsTruthy(app.data.settings[S + '.whitelist_sync_enabled']) && settingIsTruthy(app.data.settings[S + '.whitelist_describe_enabled']);
    }

    async function runDescribeAll(resume) {
        const s = st.describeAll;
        if (s.running || st.scan.running) return;
        if (!resume) Object.assign(s, { cursor: 0, processed: 0, total: null, error: null, done: false, describe: null, queued: 0 });
        s.running = true; s.cancel = false; s.error = null; s.describeOff = false; m.redraw();
        while (!s.done && !s.cancel) {
            let res;
            try {
                res = await app.request({ method: 'POST', url: apiUrl() + '/tryhackx/homepage/whitelist/scan', body: { cursor: s.cursor, mode: 'describe' }, errorHandler: () => {} });
            } catch (e) {
                const wait = rateWait(e);
                if (wait) {
                    await waitFor(s, wait);
                    continue;
                }
                const status = e && e.status;
                const err = (e && e.response && e.response.error) || ('HTTP ' + status);
                s.error = status === 409 ? t('describe_all_running') : status === 503 ? t('scan_cooldown') : err === 'describe_disabled' ? t('describe_all_disabled') : t('scan_error', { error: err });
                break;
            }
            if (res.total_posts !== undefined) s.total = res.total_posts;
            if (!res.ok) {
                s.error = t('scan_error', { error: res.error || 'tracker error' });
                break;
            }
            s.cursor = res.next_cursor;
            s.processed += res.processed;
            s.queued += res.queued || 0;
            addDescribe(s, res);
            s.done = !!res.done;
            m.redraw();
            if (s.describeOff) break;
            if (res.wait && !s.done) await waitFor(s, res.wait);
        }
        s.running = false;
        m.redraw();
        if (s.done && !s.error && !s.describeOff) {
            const d = s.describe || {};
            app.alerts.show({ type: 'success' }, t('describe_all_done', { processed: s.processed, published: d.published || 0, waiting: d.waiting || 0, unchanged: d.unchanged || 0, withdrawn: d.withdrawn || 0 }));
        }
        loadStatus();
    }

    app.registry.for(S)
        .registerSetting(sectionHeader(app.translator.trans(S + '.admin.section_whitelist')))
        .registerSetting({
            setting: S + '.whitelist_sync_enabled',
            type: 'bool',
            label: t('enabled'),
            help: t('enabled_help'),
        })
        .registerSetting(function () {
            const page = this;
            const enabled = settingIsTruthy(this.setting(S + '.whitelist_sync_enabled')());
            const dis = !enabled;
            const requireTracker = settingIsTruthy(this.setting(S + '.whitelist_require_tracker')());
            const hostsDis = dis || !requireTracker; // host list is inert unless the group is open AND the option is on
            const field = (key, labelKey, helpKey, attrs) =>
                m('div', { className: 'HomepageBlocks-whitelistField' }, [
                    m('label', t(labelKey)),
                    m('input', Object.assign({
                        type: 'text',
                        className: 'FormControl',
                        disabled: dis,
                        tabindex: dis ? -1 : 0,
                        value: this.setting(S + '.' + key)() || '',
                        oninput: (e) => this.setting(S + '.' + key)(e.target.value),
                    }, attrs || {})),
                    helpKey ? m('div', { className: 'helpText' }, t(helpKey)) : null,
                ]);
            const s = st.scan;
            const pct = s.total ? Math.min(100, Math.round(100 * s.processed / Math.max(1, s.total))) : (s.done ? 100 : 0);
            const ds = st.describeAll;
            const dsPct = ds.total ? Math.min(100, Math.round(100 * ds.processed / Math.max(1, ds.total))) : (ds.done ? 100 : 0);

            return m('div', { className: 'Form-group HomepageBlocks-whitelistGroup' + (enabled ? ' is-open' : ''), 'aria-hidden': dis ? 'true' : 'false' }, [
                m('div', { className: 'HomepageBlocks-whitelistInner' }, [
                    field('whitelist_api_url', 'api_url', 'api_url_help', { placeholder: 'https://tracker.example.org', autocomplete: 'off' }),
                    field('whitelist_api_key_id', 'api_key_id', 'api_key_id_help', { placeholder: '0123456789abcdef', autocomplete: 'off', maxlength: 64 }),

                    // Secret: local state only, saved via its own endpoint
                    m('div', { className: 'HomepageBlocks-whitelistField' }, [
                        m('label', t('api_secret')),
                        m('div', { className: 'HomepageBlocks-whitelistSecretRow' }, [
                            m('input', {
                                type: 'password',
                                className: 'FormControl',
                                autocomplete: 'new-password',
                                disabled: dis || st.secretBusy,
                                tabindex: dis ? -1 : 0,
                                placeholder: secretIsSet() ? t('secret_placeholder_set') : t('secret_placeholder_empty'),
                                value: st.secretInput,
                                oninput: (e) => { st.secretInput = e.target.value; },
                            }),
                            m('button', { type: 'button', className: 'Button Button--primary', disabled: dis || st.secretBusy || st.secretInput.trim() === '', onclick: () => saveSecret(page, false) }, t('secret_save')),
                            secretIsSet() ? m('button', { type: 'button', className: 'Button', disabled: dis || st.secretBusy, onclick: () => saveSecret(page, true) }, t('secret_clear')) : null,
                        ]),
                        m('div', { className: 'helpText' }, [
                            t('api_secret_help'), ' ',
                            secretIsSet() ? m('span', { className: 'HomepageBlocks-ok' }, t('secret_is_set')) : m('span', { className: 'HomepageBlocks-warn' }, t('secret_not_set')),
                            st.secretMsg ? m('span', { className: st.secretMsg.type === 'ok' ? 'HomepageBlocks-ok' : 'HomepageBlocks-err' }, ' ' + st.secretMsg.text) : null,
                        ]),
                    ]),

                    m('div', { className: 'HomepageBlocks-whitelistRow' }, [
                        m('div', { className: 'HomepageBlocks-whitelistField HomepageBlocks-whitelistField--small' }, [
                            m('label', t('timeout')),
                            m('input', { type: 'number', className: 'FormControl', min: 1, max: 10, step: 1, disabled: dis, tabindex: dis ? -1 : 0, placeholder: '3', value: this.setting(S + '.whitelist_timeout_seconds')() || '', oninput: (e) => this.setting(S + '.whitelist_timeout_seconds')(e.target.value) }),
                            m('div', { className: 'helpText' }, t('timeout_help')),
                        ]),
                        m('div', { className: 'HomepageBlocks-whitelistField' }, [
                            m('label', { className: 'checkbox' }, [
                                m('input', { type: 'checkbox', disabled: dis, tabindex: dis ? -1 : 0, checked: settingIsTruthy(this.setting(S + '.whitelist_detect_bare_hashes')()), onchange: (e) => this.setting(S + '.whitelist_detect_bare_hashes')(e.target.checked ? '1' : '0') }),
                                ' ', t('bare_hashes'),
                            ]),
                            m('div', { className: 'helpText' }, t('bare_hashes_help')),
                        ]),
                    ]),

                    // Only sync magnets that point at our tracker (+ the host list, inert until the checkbox is on)
                    m('div', { className: 'HomepageBlocks-whitelistStack' }, [
                        m('div', { className: 'HomepageBlocks-whitelistField' }, [
                            m('label', { className: 'checkbox' }, [
                                m('input', { type: 'checkbox', className: 'HomepageBlocks-whitelistRequireTracker', disabled: dis, tabindex: dis ? -1 : 0, checked: requireTracker, onchange: (e) => this.setting(S + '.whitelist_require_tracker')(e.target.checked ? '1' : '0') }),
                                ' ', t('require_tracker'),
                            ]),
                            m('div', { className: 'helpText' }, t('require_tracker_help')),
                        ]),
                        m('div', { className: 'HomepageBlocks-whitelistField' }, [
                            m('label', t('tracker_hosts')),
                            m('input', {
                                type: 'text',
                                className: 'FormControl HomepageBlocks-whitelistTrackerHosts',
                                disabled: hostsDis,
                                tabindex: hostsDis ? -1 : 0,
                                placeholder: 'tracker.example.org, 203.0.113.10',
                                autocomplete: 'off',
                                value: this.setting(S + '.whitelist_tracker_hosts')() || '',
                                oninput: (e) => this.setting(S + '.whitelist_tracker_hosts')(e.target.value),
                            }),
                            m('div', { className: 'helpText' }, t('tracker_hosts_help')),
                        ]),
                    ]),

                    m('div', { className: 'HomepageBlocks-whitelistField' }, [
                        m('label', { className: 'checkbox' }, [
                            m('input', { type: 'checkbox', className: 'HomepageBlocks-whitelistDescribe', disabled: dis, tabindex: dis ? -1 : 0, checked: settingIsTruthy(this.setting(S + '.whitelist_describe_enabled')()), onchange: (e) => this.setting(S + '.whitelist_describe_enabled')(e.target.checked ? '1' : '0') }),
                            ' ', t('describe'),
                        ]),
                        m('div', { className: 'helpText' }, t('describe_help')),
                    ]),

                    m('div', { className: 'HomepageBlocks-whitelistField' }, [
                        m('label', { className: 'checkbox' }, [
                            m('input', { type: 'checkbox', className: 'HomepageBlocks-whitelistImageCopy', disabled: dis, tabindex: dis ? -1 : 0, checked: settingIsTruthy(this.setting(S + '.whitelist_image_copy')()), onchange: (e) => this.setting(S + '.whitelist_image_copy')(e.target.checked ? '1' : '0') }),
                            ' ', t('image_copy'),
                        ]),
                        m('div', { className: 'helpText' }, t('image_copy_help')),
                    ]),

                    m('div', { className: 'HomepageBlocks-whitelistScan HomepageBlocks-whitelistDescribeAll' }, [
                        m('div', { className: 'HomepageBlocks-whitelistActions' }, [
                            m('button', { type: 'button', className: 'Button', disabled: dis || !describeSaved() || ds.running || s.running, onclick: () => runDescribeAll(false) }, [
                                m('i', { className: 'fas fa-file-alt Button-icon icon' + (ds.running ? ' fa-spin' : '') }), ' ', t('describe_all_button'),
                            ]),
                            ds.running ? m('button', { type: 'button', className: 'Button', onclick: () => { ds.cancel = true; } }, t('scan_cancel')) : null,
                            (!ds.running && !ds.done && (ds.cursor > 0 || ds.error || ds.describeOff || ds.cancel)) ? m('button', { type: 'button', className: 'Button', disabled: dis || !describeSaved() || s.running, onclick: () => runDescribeAll(true) }, t('scan_resume')) : null,
                        ]),
                        (ds.running || ds.processed > 0 || ds.error || ds.describeOff) ? m('div', { className: 'HomepageBlocks-progress' }, [
                            m('div', { className: 'HomepageBlocks-progressBar' }, m('div', { className: 'HomepageBlocks-progressFill' + (ds.error ? ' is-error' : ds.done ? ' is-done' : ''), style: 'width:' + dsPct + '%' })),
                            m('div', { className: 'HomepageBlocks-progressText' }, t('describe_all_progress', { processed: ds.processed, total: ds.total === null ? '?' : ds.total })),
                            ds.describe ? m('div', { className: 'HomepageBlocks-progressText' }, describeLine(ds.describe)) : null,
                            ds.queued ? m('div', { className: 'HomepageBlocks-warn' }, t('scan_queued', { count: ds.queued })) : null,
                            ds.waiting ? m('div', { className: 'HomepageBlocks-progressText' }, t('scan_waiting', { seconds: ds.waiting })) : null,
                            ds.describeOff ? m('div', { className: 'HomepageBlocks-warn' }, t('describe_all_off')) : null,
                            ds.error ? m('div', { className: 'HomepageBlocks-err' }, ds.error) : null,
                            ds.cancel && !ds.running ? m('div', { className: 'HomepageBlocks-warn' }, t('scan_cancelled')) : null,
                        ]) : null,
                        m('div', { className: 'helpText' }, t('describe_all_help')),
                    ]),

                    statusBlock(),

                    // Test connection
                    m('div', { className: 'HomepageBlocks-whitelistActions' }, [
                        m('button', { type: 'button', className: 'Button', disabled: dis || st.testBusy, onclick: () => testConnection(page) }, [
                            m('i', { className: 'fas fa-plug Button-icon icon' + (st.testBusy ? ' fa-spin' : '') }), ' ', t('test_button'),
                        ]),
                        st.testResult ? m('span', { className: 'HomepageBlocks-whitelistTestResult ' + (st.testResult.ok ? 'HomepageBlocks-ok' : 'HomepageBlocks-err') }, st.testResult.text) : null,
                    ]),
                    m('div', { className: 'helpText' }, t('test_help')),

                    // Scan whole forum
                    m('div', { className: 'HomepageBlocks-whitelistScan' }, [
                        m('div', { className: 'HomepageBlocks-whitelistActions' }, [
                            m('button', { type: 'button', className: 'Button Button--primary', disabled: dis || s.running || ds.running, onclick: () => runScan(false) }, [
                                m('i', { className: 'fas fa-search Button-icon icon' + (s.running ? ' fa-spin' : '') }), ' ', t('scan_button'),
                            ]),
                            s.running ? m('button', { type: 'button', className: 'Button', onclick: () => { s.cancel = true; } }, t('scan_cancel')) : null,
                            (!s.running && !s.done && (s.cursor > 0 || s.error || s.cancel)) ? m('button', { type: 'button', className: 'Button', disabled: dis, onclick: () => runScan(true) }, t('scan_resume')) : null,
                        ]),
                        (s.running || s.processed > 0 || s.error) ? m('div', { className: 'HomepageBlocks-progress' }, [
                            m('div', { className: 'HomepageBlocks-progressBar' }, m('div', { className: 'HomepageBlocks-progressFill' + (s.error ? ' is-error' : s.done ? ' is-done' : ''), style: 'width:' + pct + '%' })),
                            m('div', { className: 'HomepageBlocks-progressText' }, t('scan_progress', { processed: s.processed, total: s.total === null ? '?' : s.total, hashes: s.hashes, skipped: s.skipped, added: s.added, exists: s.exists, banned: s.banned })),
                            s.describe ? m('div', { className: 'HomepageBlocks-progressText' }, describeLine(s.describe)) : null,
                            s.queued ? m('div', { className: 'HomepageBlocks-warn' }, t('scan_queued', { count: s.queued })) : null,
                            s.waiting ? m('div', { className: 'HomepageBlocks-progressText' }, t('scan_waiting', { seconds: s.waiting })) : null,
                            s.describeOff ? m('div', { className: 'HomepageBlocks-warn' }, t('scan_describe_off')) : null,
                            s.error ? m('div', { className: 'HomepageBlocks-err' }, s.error) : null,
                            s.cancel && !s.running ? m('div', { className: 'HomepageBlocks-warn' }, t('scan_cancelled')) : null,
                        ]) : null,
                        m('div', { className: 'helpText' }, t('scan_help')),
                    ]),
                ]),
            ]);
        });
}
