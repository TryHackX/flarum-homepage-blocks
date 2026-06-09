import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import { preflightCheck, showCaptchaModal, guardActiveFor, showRateLimitNotice } from '../utils/recaptcha';

/**
 * AdvancedFilters component.
 *
 * Uses app.discussions.refreshParams() to update the discussion list
 * WITHOUT triggering a route change. This prevents:
 * - Full page re-render (which resets collapsed state)
 * - Input focus loss during debounced typing
 * - Component unmount/remount cycle
 *
 * Title search uses a custom TitleFilter (LIKE '%text%') registered in PHP,
 * accessible via filter[title]=value in the Flarum API.
 */
export default class AdvancedFilters extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        // Restore filter state from global (survives collapse/expand)
        this.filters = app.homepageFilters;
        this.categoryTags = [];
        this.resolutionTags = [];
        this.debounceTimer = null;

        // Accelerating-delete "hold X" state
        this.holdTimer = null;
        this.holdActiveField = null;
        this.holdDelay = 90;           // initial delay before first tick (ms)
        this.minHoldDelay = 12;        // fastest tick (ms)
        this.holdAcceleration = 0.70;  // multiplicative decay per tick (snappier)

        this.loadTags();
    }

    onremove() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }
        this.stopClear();
    }

    getDebounceMs() {
        const raw = app.forum.attribute('tryhackx-homepage-blocks.search_debounce_ms');
        const parsed = Number(raw);
        if (!parsed || isNaN(parsed)) return 500;
        return Math.max(100, Math.min(5000, parsed));
    }

    view() {
        const showOnlyUsed = app.forum.attribute('tryhackx-homepage-blocks.show_only_used_tags');
        const showCount = app.forum.attribute('tryhackx-homepage-blocks.show_tag_count');

        const categoryOptions = { all: this.transStr('tryhackx-homepage-blocks.forum.filter_all') };
        this.categoryTags.forEach((t) => {
            const count = t.discussionCount ? t.discussionCount() : 0;
            if (showOnlyUsed && count === 0) return;
            const label = showCount ? t.name() + ' (' + count + ')' : t.name();
            categoryOptions[t.slug()] = label;
        });

        const resolutionOptions = { all: this.transStr('tryhackx-homepage-blocks.forum.filter_all') };
        this.resolutionTags.forEach((t) => {
            const count = t.discussionCount ? t.discussionCount() : 0;
            if (showOnlyUsed && count === 0) return;
            const label = showCount ? t.name() + ' (' + count + ')' : t.name();
            resolutionOptions[t.slug()] = label;
        });

        const ratingIntervalOptions = {
            '0': this.transStr('tryhackx-homepage-blocks.forum.interval_all'),
            'today': this.transStr('tryhackx-homepage-blocks.forum.interval_today'),
            '1d': this.transStr('tryhackx-homepage-blocks.forum.interval_1d'),
            '1w': this.transStr('tryhackx-homepage-blocks.forum.interval_1w'),
            '2w': this.transStr('tryhackx-homepage-blocks.forum.interval_2w'),
            '1m': this.transStr('tryhackx-homepage-blocks.forum.interval_1m'),
            '3m': this.transStr('tryhackx-homepage-blocks.forum.interval_3m'),
            '6m': this.transStr('tryhackx-homepage-blocks.forum.interval_6m'),
            '1y': this.transStr('tryhackx-homepage-blocks.forum.interval_1y'),
        };

        // Wykrywanie rozszerzeń przez atrybuty forum (serializowane warunkowo w
        // extend.php / magnet-link). Opcje sortowania pokazujemy TYLKO gdy dane
        // rozszerzenie jest aktywne — inaczej wybór wywaliłby zapytanie API
        // (brak kolumny rating_average / view_count / magnet…). Data utworzenia
        // jest zawsze dostępna (kolumna rdzeniowa).
        const hasRating = typeof app.forum.attribute('tryhackxHomepageHasRating') !== 'undefined';
        const hasViews = typeof app.forum.attribute('tryhackxHomepageHasViews') !== 'undefined';
        const hasMagnet = typeof app.forum.attribute('magnetClickTracking') !== 'undefined';

        const sortOptions = {
            '4': this.transStr('tryhackx-homepage-blocks.forum.sort_created'),
        };
        if (hasRating) {
            sortOptions['0'] = this.transStr('tryhackx-homepage-blocks.forum.sort_steamdb');
            sortOptions['1'] = this.transStr('tryhackx-homepage-blocks.forum.sort_avg_rating');
            sortOptions['2'] = this.transStr('tryhackx-homepage-blocks.forum.sort_rating_count');
            sortOptions['3'] = this.transStr('tryhackx-homepage-blocks.forum.sort_recently_rated');
        }
        if (hasViews) {
            sortOptions['5'] = this.transStr('tryhackx-homepage-blocks.forum.sort_views');
        }
        if (hasMagnet) {
            sortOptions['6'] = this.transStr('tryhackx-homepage-blocks.forum.sort_magnet_sum');
            sortOptions['7'] = this.transStr('tryhackx-homepage-blocks.forum.sort_magnet_max');
            sortOptions['8'] = this.transStr('tryhackx-homepage-blocks.forum.sort_recently_clicked');
        }

        const dateIntervalOptions = {
            '0': this.transStr('tryhackx-homepage-blocks.forum.interval_all'),
            'today': this.transStr('tryhackx-homepage-blocks.forum.interval_today'),
            '1d': this.transStr('tryhackx-homepage-blocks.forum.interval_1d'),
            '1w': this.transStr('tryhackx-homepage-blocks.forum.interval_1w'),
            '2w': this.transStr('tryhackx-homepage-blocks.forum.interval_2w'),
            '1m': this.transStr('tryhackx-homepage-blocks.forum.interval_1m'),
            '3m': this.transStr('tryhackx-homepage-blocks.forum.interval_3m'),
            '6m': this.transStr('tryhackx-homepage-blocks.forum.interval_6m'),
            '1y': this.transStr('tryhackx-homepage-blocks.forum.interval_1y'),
        };

        const directionOptions = {
            desc: this.transStr('tryhackx-homepage-blocks.forum.sort_desc'),
            asc: this.transStr('tryhackx-homepage-blocks.forum.sort_asc'),
        };

        return m('div', { className: 'AdvancedFilters' }, [
            m('div', { className: 'AdvancedFilters-row' }, [
                this.renderTextField('filter_title', 'filter_title_placeholder', 'title'),
                this.renderTextField('filter_user', 'filter_user_placeholder', 'user'),
                hasRating ? this.renderSelect('filter_rating_interval', ratingIntervalOptions, 'ratingInterval') : null,
                this.renderSelect('filter_sort_by', sortOptions, 'sortBy'),
                this.renderSelect('filter_date_interval', dateIntervalOptions, 'dateInterval'),
            ]),
            m('div', { className: 'AdvancedFilters-row' }, [
                this.renderSelect('filter_category', categoryOptions, 'category',
                    app.forum.attribute('tryhackx-homepage-blocks.category_label') || null),
                this.renderSelect('filter_resolution', resolutionOptions, 'resolution',
                    app.forum.attribute('tryhackx-homepage-blocks.resolution_label') || null),
                m('div', { className: 'AdvancedFilters-field AdvancedFilters-field--right' }, [
                    m('label', this.transStr('tryhackx-homepage-blocks.forum.filter_direction') + ':'),
                    m(
                        'select',
                        {
                            className: 'FormControl',
                            value: this.filters.sortDirection,
                            onchange: (e) => {
                                this.filters.sortDirection = e.target.value;
                                this.applyFilters();
                            },
                        },
                        Object.keys(directionOptions).map((key) =>
                            m('option', { value: key }, directionOptions[key])
                        )
                    ),
                ]),
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

    renderTextField(labelKey, placeholderKey, filterKey) {
        const value = this.filters[filterKey] || '';
        return m('div', { className: 'AdvancedFilters-field' }, [
            m('label', this.transStr('tryhackx-homepage-blocks.forum.' + labelKey) + ':'),
            m('div', { className: 'AdvancedFilters-inputWrap' }, [
                m('input', {
                    type: 'text',
                    className: 'FormControl AdvancedFilters-input' + (value ? ' has-value' : ''),
                    placeholder: this.transStr('tryhackx-homepage-blocks.forum.' + placeholderKey),
                    value,
                    oninput: (e) => {
                        this.filters[filterKey] = e.target.value;
                        this.debounceApply();
                    },
                    onkeydown: (e) => {
                        if (e.key === 'Enter') {
                            if (this.debounceTimer) clearTimeout(this.debounceTimer);
                            this.applyFilters();
                        }
                    },
                }),
                value ? m('button', {
                    type: 'button',
                    className: 'AdvancedFilters-clear',
                    title: this.transStr('tryhackx-homepage-blocks.forum.clear_field'),
                    'aria-label': this.transStr('tryhackx-homepage-blocks.forum.clear_field'),
                    // Klik uruchamia przyspieszane kasowanie aż do opróżnienia pola
                    // (efekt jak przytrzymany backspace). Nie wymaga przytrzymania.
                    onclick: (e) => {
                        e.preventDefault();
                        this.startClear(filterKey);
                    },
                }, m('i', { className: 'fas fa-times' })) : null,
            ]),
        ]);
    }

    /**
     * Accelerating clear. One click strips characters one at a time with an
     * increasing tempo (like holding backspace) until the field is empty —
     * the animation runs to completion on its own, no need to hold the button.
     */
    startClear(filterKey) {
        this.stopClear();
        this.holdActiveField = filterKey;

        // First tick: single character removed immediately
        this.tickClear(filterKey, this.holdDelay);
    }

    tickClear(filterKey, delay) {
        const current = this.filters[filterKey] || '';
        if (!current) {
            // Field empty — we're done
            this.stopClear();
            this.applyFilters();
            return;
        }

        // Pop one character
        this.filters[filterKey] = current.slice(0, -1);
        this.debounceApply();
        m.redraw();

        // Accelerate and schedule next tick
        const nextDelay = Math.max(this.minHoldDelay, Math.floor(delay * this.holdAcceleration));
        this.holdTimer = setTimeout(() => {
            if (this.holdActiveField !== filterKey) return;
            this.tickClear(filterKey, nextDelay);
        }, delay);
    }

    stopClear() {
        if (this.holdTimer) {
            clearTimeout(this.holdTimer);
            this.holdTimer = null;
        }
        this.holdActiveField = null;
    }

    renderSelect(labelKey, options, filterKey, customLabel) {
        const label = customLabel || this.transStr('tryhackx-homepage-blocks.forum.' + labelKey);
        return m('div', { className: 'AdvancedFilters-field' }, [
            m('label', label + ':'),
            m(
                'select',
                {
                    className: 'FormControl',
                    value: this.filters[filterKey],
                    onchange: (e) => {
                        this.filters[filterKey] = e.target.value;
                        this.applyFilters();
                    },
                },
                Object.keys(options).map((key) =>
                    m('option', { value: key }, options[key])
                )
            ),
        ]);
    }

    debounceApply() {
        if (this.debounceTimer) clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
            this.applyFilters();
        }, this.getDebounceMs());
    }

    loadTags() {
        const allTags = app.store.all('tags');

        // Auto-detect: Primary tags (position set) → Category, Secondary (no position) → Resolution
        allTags.forEach((t) => {
            if (this.tagHasParent(t)) return;
            const pos = t.position ? t.position() : null;

            if (pos !== null && pos !== undefined && pos !== '') {
                this.categoryTags.push(t);
            } else {
                this.resolutionTags.push(t);
            }
        });

        const byName = (a, b) => (a.name() || '').localeCompare(b.name() || '');
        this.categoryTags.sort(byName);
        this.resolutionTags.sort(byName);
    }

    tagHasParent(t) {
        try {
            if (t.parent && t.parent()) return true;
        } catch (e) {}
        try {
            if (t.data && t.data.relationships && t.data.relationships.parent && t.data.relationships.parent.data) {
                return true;
            }
        } catch (e) {}
        return false;
    }

    /**
     * Apply filters by directly updating app.discussions state.
     * No route change = no re-render = no focus loss.
     *
     * Sort uses Flarum sort key names (registered in sortMap) which are
     * resolved through DiscussionListState.sortMap() → API sort string.
     *
     * If reCAPTCHA is enabled for search, a pre-flight check is performed
     * against the server; when the points bucket is exhausted the captcha
     * modal is shown and the search is retried after a successful token.
     */
    async applyFilters() {
        app.homepageFilters = this.filters;

        // Pre-flight guard: tylko gdy zakres jest faktycznie chroniony
        // (reCAPTCHA lub limiter punktowy).
        if (guardActiveFor('search')) {
            const result = await preflightCheck('search');
            if (!result.ok) {
                if (result.blocked) {
                    // Tymczasowa blokada IP — pokaż odliczanie, nie wykonuj zapytania.
                    showRateLimitNotice(result.retryAfter);
                    return;
                }
                if (result.captchaRequired) {
                    const token = await showCaptchaModal('search');
                    if (!token) return; // użytkownik zamknął
                    const retry = await preflightCheck('search', token);
                    if (!retry.ok) {
                        if (retry.blocked) showRateLimitNotice(retry.retryAfter);
                        return;
                    }
                } else {
                    // Niepowiązany błąd — wyjdź cicho zamiast spamować.
                    return;
                }
            }
        }

        this.runQuery();
    }

    runQuery() {
        // Build filter object for Flarum API
        const filter = {};

        // Title search: uses custom TitleFilter (LIKE '%text%')
        if (this.filters.title) {
            filter.title = this.filters.title;
        }

        // User search: uses custom UserFilter (LIKE '%text%' on username)
        if (this.filters.user) {
            filter.user = this.filters.user;
        }

        // Tag filters: combine category + resolution
        const tags = [];
        if (this.filters.category !== 'all') tags.push(this.filters.category);
        if (this.filters.resolution !== 'all') tags.push(this.filters.resolution);
        if (tags.length) {
            filter.tag = tags;
        }

        // Rating interval: custom RatingFilter — tylko gdy topic-rating aktywne.
        const hasRating = typeof app.forum.attribute('tryhackxHomepageHasRating') !== 'undefined';
        if (hasRating && this.filters.ratingInterval !== '0') {
            filter.ratingInterval = this.filters.ratingInterval;
        }

        // Date interval: custom DateIntervalFilter
        if (this.filters.dateInterval !== '0') {
            filter.dateInterval = this.filters.dateInterval;
        }

        // Sort mapping: UI index → { desc: sortKey, asc: sortKey }
        // Sort keys must match aliases registered in extend.php and DiscussionListState.sortMap()
        const sortMap = {
            '0': { desc: 'steamdb',            asc: 'steamdb_asc' },          // Steam DB rating
            '1': { desc: 'most_rated',         asc: 'least_rated' },          // Avg rating
            '2': { desc: 'most_rating_count',  asc: 'least_rating_count' },   // Number of ratings
            '3': { desc: 'recently_rated',     asc: 'oldest_rated' },         // Recently rated
            '4': { desc: 'newest',             asc: 'oldest' },               // Creation date
            '5': { desc: 'most_viewed',        asc: 'least_viewed' },         // Views (fof/discussion-views)
            // Magnet-click sorts — aliases registered by tryhackx/flarum-magnet-link
            // (Sort\MagnetClicksSort) and ordered via its search mutator. Options
            // 6/7/8 are only shown when magnet-link is enabled (see sortOptions).
            '6': { desc: 'most_magnet_clicks',        asc: 'least_magnet_clicks' },        // Magnet clicks total (sum)
            '7': { desc: 'most_magnet_clicks_single', asc: 'least_magnet_clicks_single' }, // Magnet clicks max (best single magnet)
            '8': { desc: 'recently_magnet_clicked',   asc: 'oldest_magnet_clicked' },      // Last clicked (time)
        };

        // Zabezpieczenie: jeśli zapisany wybór dotyczy sortowania z rozszerzenia,
        // którego nie ma, wróć do daty utworzenia (inaczej API zwróci błąd).
        const hasViews = typeof app.forum.attribute('tryhackxHomepageHasViews') !== 'undefined';
        const hasMagnet = typeof app.forum.attribute('magnetClickTracking') !== 'undefined';
        let sortBy = this.filters.sortBy;
        if (((['0', '1', '2', '3'].indexOf(sortBy) !== -1) && !hasRating) ||
            (sortBy === '5' && !hasViews) ||
            ((['6', '7', '8'].indexOf(sortBy) !== -1) && !hasMagnet)) {
            sortBy = '4';
        }

        const sortEntry = sortMap[sortBy] || sortMap['4'];
        const sortKey = this.filters.sortDirection === 'asc' ? sortEntry.asc : sortEntry.desc;

        // Build the params object that Flarum's DiscussionListState expects
        // params.sort must be a sort KEY name (not raw API value) — it gets
        // resolved via DiscussionListState.sortMap() in requestParams()
        const params = { filter, sort: sortKey };

        // Directly refresh the discussion list — NO route change!
        // Do NOT call clear() — refreshParams() handles it internally.
        // Without clear(), refreshParams() checks paramsChanged() first:
        //   - Same params → skip reload entirely (no unnecessary image re-downloads)
        //   - Changed params → calls refresh() which reloads from API
        if (app.discussions) {
            app.discussions.refreshParams(params, 1);
        }
    }
}
