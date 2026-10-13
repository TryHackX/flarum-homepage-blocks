import app from 'flarum/admin/app';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';

// ──────────── ADVANCED FILTERS ("More filters") ────────────
// Master switch → animated reveal of: which filters the panel offers, the Type options,
// extra sorts, how release data is read from posts (labels / size sources / units), and
// the metadata index (status, batched resumable rebuild, per-discussion preview).
const S = 'tryhackx-homepage-blocks';

/** Panel filters in admin order; meta = read from the index, requires = extension id. */
const FILTERS = [
    { id: 'type', meta: true },
    { id: 'size', meta: true },
    { id: 'year', meta: true },
    { id: 'duration', meta: true },
    { id: 'seasons', meta: true },
    { id: 'episodes', meta: true },
    { id: 'imdb', meta: true },
    { id: 'filmweb', meta: true },
    { id: 'steam', requires: 'tryhackx-topic-rating' },
    { id: 'rating', requires: 'tryhackx-topic-rating' },
    { id: 'rating_count', requires: 'tryhackx-topic-rating' },
    { id: 'rated', requires: 'tryhackx-topic-rating' },
    { id: 'views', requires: 'fof-discussion-views' },
    { id: 'replies' },
    { id: 'clicks', requires: 'tryhackx-magnet-link' },
    { id: 'clicks_top', requires: 'tryhackx-magnet-link' },
    { id: 'clicked', requires: 'tryhackx-magnet-link' },
    { id: 'magnet', requires: 'tryhackx-magnet-link' },
    { id: 'created' },
    { id: 'active' },
];

const SIZE_SOURCES = ['magnet', 'post', 'title'];
const LABEL_FIELDS = ['size_labels', 'kind_labels', 'series_values', 'seasons_labels', 'episodes_labels', 'year_labels', 'duration_labels'];

function truthy(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

function sectionHeader(title) {
    return function () {
        return m('div', { className: 'Form-group HomepageBlocks-sectionHeader' }, [m('h3', title)]);
    };
}

export default function registerAdvancedSettings() {
    const t = (k, params) => app.translator.trans(S + '.admin.advanced.' + k, params);
    // Plain-string variant (trans() may return an array) — for comparisons and attributes.
    const ts = (k, params) => {
        const r = t(k, params);
        return Array.isArray(r) ? r.map((x) => (typeof x === 'string' ? x : '')).join('') : String(r);
    };
    const apiUrl = () => app.forum.attribute('apiUrl');

    // component-local state (survives redraws; reset on page reload)
    const st = {
        status: null,
        statusLoading: false,
        statusError: null,
        scan: { running: false, cancel: false, cursor: 0, processed: 0, total: null, onlyStale: false, done: false, error: null },
        preview: { id: '', busy: false, result: null, error: null },
        tags: null,
    };

    async function loadStatus() {
        st.statusLoading = true;
        st.statusError = null;
        m.redraw();
        try {
            st.status = await app.request({ method: 'GET', url: apiUrl() + '/tryhackx/homepage/meta/status', errorHandler: () => {} });
        } catch (e) {
            st.statusError = t('status_error');
        }
        st.statusLoading = false;
        m.redraw();
    }

    function loadTags() {
        if (st.tags !== null) return;
        st.tags = [];
        const done = () => {
            st.tags = app.store.all('tags').slice().sort((a, b) => (a.name() || '').localeCompare(b.name() || ''));
            m.redraw();
        };
        if (app.tagList && typeof app.tagList.load === 'function') {
            app.tagList.load().then(done).catch(() => {});
        } else {
            app.store.find('tags').then(done).catch(() => {});
        }
    }

    async function rebuild(onlyStale, resume) {
        const s = st.scan;
        if (s.running) return;
        if (!resume) Object.assign(s, { cursor: 0, processed: 0, done: false, error: null, onlyStale: !!onlyStale });
        s.total = st.status ? (s.onlyStale ? st.status.missing + st.status.stale : st.status.total) : null;
        s.running = true;
        s.cancel = false;
        s.error = null;
        m.redraw();

        while (!s.done && !s.cancel) {
            let res;
            try {
                res = await app.request({
                    method: 'POST',
                    url: apiUrl() + '/tryhackx/homepage/meta/reindex',
                    body: { cursor: s.cursor, only_stale: s.onlyStale },
                    errorHandler: () => {},
                });
            } catch (e) {
                s.error = e && e.status === 409 ? t('rebuild_running') : t('rebuild_error');
                break;
            }
            s.cursor = res.next_cursor;
            s.processed += res.processed;
            s.done = !!res.done;
            if (res.status) st.status = res.status;
            m.redraw();
        }
        s.running = false;
        m.redraw();
        if (s.done && !s.error) {
            app.alerts.show({ type: 'success' }, t('rebuild_done', { count: s.processed }));
            loadStatus();
        }
    }

    async function runPreview() {
        const p = st.preview;
        const id = parseInt(p.id, 10);
        if (!id || p.busy) return;
        p.busy = true;
        p.error = null;
        p.result = null;
        m.redraw();
        try {
            p.result = await app.request({
                method: 'GET',
                url: apiUrl() + '/tryhackx/homepage/meta/preview',
                params: { id },
                errorHandler: () => {},
            });
        } catch (e) {
            p.error = e && e.status === 404 ? t('preview_not_found') : t('preview_error');
        }
        p.busy = false;
        m.redraw();
    }

    function formatValue(key, value) {
        if (value === null || value === undefined || value === '') return '—';
        if (key === 'size_bytes') {
            const gib = Number(value) / 1024 ** 3;
            return gib >= 1 ? gib.toFixed(2) + ' GiB' : (Number(value) / 1024 ** 2).toFixed(1) + ' MiB';
        }
        if (key === 'is_series') return String(value) === '1' ? ts('preview_series') : ts('preview_movie');
        if (key === 'year_end' && String(value) === '9999') return ts('preview_running');
        return String(value);
    }

    app.registry.for(S)
        .registerSetting(sectionHeader(app.translator.trans(S + '.admin.section_advanced')))
        .registerSetting({
            setting: S + '.adv_enabled',
            type: 'bool',
            label: t('enabled'),
            help: t('enabled_help'),
        })
        .registerSetting(function () {
            const page = this;
            // Read without creating a settings Stream: AdminPage.setting() would start an
            // unsaved key at '' (≠ undefined) and mark the page dirty just by viewing it.
            const get = (key) => {
                const full = S + '.' + key;
                return page.settings && page.settings[full] ? page.settings[full]() : app.data.settings[full];
            };
            const set = (key, value) => this.setting(S + '.' + key)(value);
            const enabled = truthy(get('adv_enabled'));
            const flag = (key, dflt) => {
                const v = get(key);
                return v === undefined || v === null || v === '' ? !!dflt : truthy(v);
            };
            const checkbox = (key, label, help, attrs) => m('div', { className: 'HomepageBlocks-advCheck' }, [
                m('label', { className: 'checkbox' }, [
                    m('input', Object.assign({
                        type: 'checkbox',
                        disabled: !enabled,
                        checked: flag(key, attrs && attrs.dflt),
                        onchange: (e) => set(key, e.target.checked ? '1' : '0'),
                    }, attrs && attrs.input)),
                    ' ',
                    label,
                ]),
                help ? m('div', { className: 'helpText' }, help) : null,
            ]);

            if (enabled && st.status === null && !st.statusLoading && !st.statusError) loadStatus();

            const typeOn = flag('adv_f_type');
            if (enabled && typeOn) loadTags();
            const metaOn = FILTERS.some((f) => f.meta && flag('adv_f_' + f.id)) || flag('adv_sort_size') || flag('adv_sort_year');
            const defaults = (st.status && st.status.defaults) || {};

            // ── 1. Which filters ──
            const filtersGrid = m('div', { className: 'HomepageBlocks-advFilters' }, FILTERS.map((f) => {
                const missing = f.requires && !isExtensionEnabled(f.requires);
                return m('label', { className: 'HomepageBlocks-advFilter' + (missing ? ' is-unavailable' : '') }, [
                    m('input', {
                        type: 'checkbox',
                        disabled: !enabled || missing,
                        checked: !missing && flag('adv_f_' + f.id),
                        onchange: (e) => set('adv_f_' + f.id, e.target.checked ? '1' : '0'),
                    }),
                    m('span', { className: 'HomepageBlocks-advFilterText' }, [
                        m('strong', t('filter_' + f.id)),
                        m('small', missing
                            ? t('requires', { extension: f.requires })
                            : t('filter_' + f.id + '_help')),
                    ]),
                    f.meta ? m('span', { className: 'HomepageBlocks-advTag', title: t('meta_badge_help') }, t('meta_badge')) : null,
                ]);
            }));

            // ── 2. Type options ──
            const seriesTags = String(get('adv_series_tags') || '').split(',').map((v) => v.trim()).filter(Boolean);
            const toggleTag = (id) => {
                const next = seriesTags.indexOf(id) === -1 ? seriesTags.concat([id]) : seriesTags.filter((x) => x !== id);
                set('adv_series_tags', next.join(','));
            };
            const typeBox = typeOn ? m('div', { className: 'HomepageBlocks-advBox' }, [
                m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('type_header')),
                m('div', { className: 'HomepageBlocks-whitelistRow' }, [
                    m('div', { className: 'HomepageBlocks-whitelistField HomepageBlocks-whitelistField--small' }, [
                        m('label', t('type_placement')),
                        m('select', {
                            className: 'FormControl',
                            disabled: !enabled,
                            value: ['main', 'panel'].indexOf(get('adv_type_placement')) !== -1 ? get('adv_type_placement') : 'button',
                            onchange: (e) => set('adv_type_placement', e.target.value),
                        }, [
                            m('option', { value: 'button' }, t('type_placement_button')),
                            m('option', { value: 'main' }, t('type_placement_main')),
                            m('option', { value: 'panel' }, t('type_placement_panel')),
                        ]),
                        m('div', { className: 'helpText' }, t('type_placement_help')),
                    ]),
                    m('div', { className: 'HomepageBlocks-whitelistField HomepageBlocks-whitelistField--small' }, [
                        m('label', t('type_label')),
                        m('input', {
                            type: 'text',
                            className: 'FormControl',
                            disabled: !enabled,
                            maxlength: 60,
                            placeholder: ts('type_label_placeholder'),
                            value: get('adv_type_label') || '',
                            oninput: (e) => set('adv_type_label', e.target.value),
                        }),
                    ]),
                ]),
                m('div', { className: 'HomepageBlocks-whitelistField' }, [
                    m('label', t('series_tags')),
                    m('div', { className: 'HomepageBlocks-advChips' }, (st.tags || []).length
                        ? st.tags.map((tag) => {
                            const id = String(tag.id());
                            const on = seriesTags.indexOf(id) !== -1;
                            return m('button', {
                                type: 'button',
                                className: 'HomepageBlocks-advChip' + (on ? ' is-on' : ''),
                                'aria-pressed': on ? 'true' : 'false',
                                disabled: !enabled,
                                onclick: () => toggleTag(id),
                            }, tag.name());
                        })
                        : m('span', { className: 'helpText' }, t('series_tags_loading'))),
                    m('div', { className: 'helpText' }, t('series_tags_help')),
                ]),
            ]) : null;

            // ── 3. Parser rules ──
            const sources = (get('meta_size_sources') || defaults.size_sources || 'magnet,post,title').split(',').map((v) => v.trim());
            const toggleSource = (src, on) => {
                const next = SIZE_SOURCES.filter((x) => (x === src ? on : sources.indexOf(x) !== -1));
                set('meta_size_sources', next.length ? next.join(',') : 'none');
            };
            const parserBox = m('details', { className: 'HomepageBlocks-advBox HomepageBlocks-advDetails' }, [
                m('summary', t('parser_header')),
                m('div', { className: 'helpText' }, t('parser_help')),

                m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('size_header')),
                m('div', { className: 'HomepageBlocks-advInline' }, SIZE_SOURCES.map((src) => m('label', { className: 'checkbox' }, [
                    m('input', {
                        type: 'checkbox',
                        disabled: !enabled,
                        checked: sources.indexOf(src) !== -1,
                        onchange: (e) => toggleSource(src, e.target.checked),
                    }),
                    ' ',
                    t('size_source_' + src),
                ]))),
                m('div', { className: 'helpText' }, t('size_sources_help')),
                m('div', { className: 'HomepageBlocks-whitelistField HomepageBlocks-whitelistField--small HomepageBlocks-advUnits' }, [
                    m('label', t('size_units')),
                    m('select', {
                        className: 'FormControl',
                        disabled: !enabled,
                        value: get('meta_size_units') === 'decimal' ? 'decimal' : 'binary',
                        onchange: (e) => set('meta_size_units', e.target.value),
                    }, [
                        m('option', { value: 'binary' }, t('size_units_binary')),
                        m('option', { value: 'decimal' }, t('size_units_decimal')),
                    ]),
                ]),

                m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('labels_header')),
                m('div', { className: 'helpText' }, t('labels_help')),
                m('div', { className: 'HomepageBlocks-advLabels' }, LABEL_FIELDS.map((key) => m('div', { className: 'HomepageBlocks-whitelistField' }, [
                    m('label', t('label_' + key)),
                    m('input', {
                        type: 'text',
                        className: 'FormControl',
                        disabled: !enabled,
                        maxlength: 600,
                        placeholder: defaults[key] || '',
                        value: get('meta_' + key) || '',
                        oninput: (e) => set('meta_' + key, e.target.value),
                    }),
                ]))),

                m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('series_rules_header')),
                checkbox('meta_series_from_counts', t('series_from_counts'), null, { dflt: true }),
                checkbox('meta_series_from_pattern', t('series_from_pattern'), null, { dflt: true }),
                checkbox('meta_seasons_from_pattern', t('seasons_from_pattern'), null, { dflt: true }),
            ]);

            // ── 4. Index ──
            const s = st.scan;
            const status = st.status;
            const pct = s.total ? Math.min(100, Math.round((100 * s.processed) / Math.max(1, s.total))) : (s.done ? 100 : 0);
            const p = st.preview;
            const previewKeys = ['size_bytes', 'size_source', 'is_series', 'seasons', 'episodes', 'year_start', 'year_end', 'duration_min', 'imdb_rating', 'filmweb_rating'];

            const indexBox = m('div', { className: 'HomepageBlocks-advBox' }, [
                m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('index_header')),
                m('div', { className: 'helpText' }, t('index_help')),
                st.statusError ? m('div', { className: 'HomepageBlocks-err' }, st.statusError) : null,
                status ? m('div', { className: 'HomepageBlocks-advStatus' }, [
                    m('span', t('status_line', { indexed: status.indexed, total: status.total, size: status.with_size, series: status.series, movies: status.movies })),
                    status.missing > 0 ? m('div', { className: 'HomepageBlocks-warn' }, t('status_missing', { count: status.missing })) : null,
                    status.stale > 0 ? m('div', { className: 'HomepageBlocks-warn' }, t('status_stale', { count: status.stale })) : null,
                    status.missing === 0 && status.stale === 0 && status.total > 0 ? m('div', { className: 'HomepageBlocks-ok' }, t('status_ok')) : null,
                    metaOn ? null : m('div', { className: 'helpText' }, t('status_not_kept')),
                ]) : (st.statusLoading ? m('div', { className: 'helpText' }, t('status_loading')) : null),
                // Only unsaved PARSER rules matter for a rebuild (it uses the saved ones).
                Object.keys(page.dirty ? page.dirty() : {}).some((k) => k.indexOf(S + '.meta_') === 0)
                    ? m('div', { className: 'HomepageBlocks-warn' }, t('save_first'))
                    : null,
                m('div', { className: 'HomepageBlocks-whitelistActions' }, [
                    m('button', { type: 'button', className: 'Button Button--primary', disabled: !enabled || s.running, onclick: () => rebuild(false) }, [
                        m('i', { className: 'fas fa-sync-alt Button-icon icon' + (s.running && !s.onlyStale ? ' fa-spin' : '') }), ' ', t('rebuild'),
                    ]),
                    m('button', { type: 'button', className: 'Button', disabled: !enabled || s.running || !status || (status.missing + status.stale) === 0, onclick: () => rebuild(true) }, [
                        m('i', { className: 'fas fa-wrench Button-icon icon' + (s.running && s.onlyStale ? ' fa-spin' : '') }), ' ', t('rebuild_stale'),
                    ]),
                    s.running ? m('button', { type: 'button', className: 'Button', onclick: () => { s.cancel = true; } }, t('rebuild_cancel')) : null,
                    (!s.running && !s.done && s.cursor > 0) ? m('button', { type: 'button', className: 'Button', disabled: !enabled, onclick: () => rebuild(s.onlyStale, true) }, t('rebuild_resume')) : null,
                    m('button', { type: 'button', className: 'Button Button--link', disabled: !enabled || st.statusLoading, onclick: () => loadStatus() }, t('refresh')),
                ]),
                (s.running || s.processed > 0 || s.error) ? m('div', { className: 'HomepageBlocks-progress' }, [
                    m('div', { className: 'HomepageBlocks-progressBar' }, m('div', { className: 'HomepageBlocks-progressFill' + (s.error ? ' is-error' : s.done ? ' is-done' : ''), style: 'width:' + pct + '%' })),
                    m('div', { className: 'HomepageBlocks-progressText' }, t('rebuild_progress', { processed: s.processed, total: s.total === null ? '?' : s.total })),
                    s.error ? m('div', { className: 'HomepageBlocks-err' }, s.error) : null,
                    s.cancel && !s.running ? m('div', { className: 'HomepageBlocks-warn' }, t('rebuild_cancelled')) : null,
                ]) : null,
                m('div', { className: 'helpText' }, t('rebuild_cli')),

                // Preview one discussion
                m('div', { className: 'HomepageBlocks-advPreview' }, [
                    m('label', t('preview')),
                    m('div', { className: 'HomepageBlocks-whitelistSecretRow' }, [
                        m('input', {
                            type: 'text',
                            inputmode: 'numeric',
                            className: 'FormControl',
                            placeholder: t('preview_placeholder'),
                            disabled: !enabled,
                            value: p.id,
                            oninput: (e) => { p.id = e.target.value.replace(/\D+/g, ''); },
                            onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); runPreview(); } },
                        }),
                        m('button', { type: 'button', className: 'Button', disabled: !enabled || p.busy || !p.id, onclick: runPreview }, t('preview_button')),
                    ]),
                    p.error ? m('div', { className: 'HomepageBlocks-err' }, p.error) : null,
                    p.result ? m('div', { className: 'HomepageBlocks-advPreviewResult' }, [
                        m('div', { className: 'HomepageBlocks-advPreviewTitle' }, '#' + p.result.id + ' — ' + p.result.title),
                        m('table', { className: 'HomepageBlocks-advTable' }, [
                            m('thead', m('tr', [m('th', t('preview_field')), m('th', t('preview_now')), m('th', t('preview_stored'))])),
                            m('tbody', previewKeys.map((key) => {
                                const now = formatValue(key, p.result.extracted[key]);
                                const stored = p.result.stored ? formatValue(key, p.result.stored[key]) : '—';
                                return m('tr', { className: p.result.stored && now !== stored ? 'is-diff' : '' }, [
                                    m('td', t('preview_' + key)), m('td', now), m('td', stored),
                                ]);
                            })),
                        ]),
                    ]) : null,
                ]),
            ]);

            return m('div', { className: 'Form-group HomepageBlocks-advGroup' + (enabled ? ' is-open' : ''), 'aria-hidden': enabled ? 'false' : 'true' },
                m('div', { className: 'HomepageBlocks-advInner', inert: !enabled }, [
                    checkbox('adv_open_default', t('open_default'), t('open_default_help')),
                    m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('filters_header')),
                    filtersGrid,
                    typeBox,
                    m('h4', { className: 'HomepageBlocks-pointsHeading' }, t('sorts_header')),
                    m('div', { className: 'HomepageBlocks-advInline' }, [
                        checkbox('adv_sort_size', t('sort_size')),
                        checkbox('adv_sort_year', t('sort_year')),
                    ]),
                    parserBox,
                    indexBox,
                ]));
        });
}
