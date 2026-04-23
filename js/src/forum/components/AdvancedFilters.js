import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import { preflightCheck, showCaptchaModal, recaptchaRequiredFor } from '../utils/recaptcha';

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
        this.holdDelay = 300;          // initial delay before first tick
        this.minHoldDelay = 30;        // fastest tick (ms)
        this.holdAcceleration = 0.85;  // multiplicative decay per tick

        this.loadTags();
    }

    onremove() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }
        this.stopHold();
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

        const sortOptions = {
            '0': this.transStr('tryhackx-homepage-blocks.forum.sort_steamdb'),
            '1': this.transStr('tryhackx-homepage-blocks.forum.sort_avg_rating'),
            '2': this.transStr('tryhackx-homepage-blocks.forum.sort_rating_count'),
            '3': this.transStr('tryhackx-homepage-blocks.forum.sort_recently_rated'),
            '4': this.transStr('tryhackx-homepage-blocks.forum.sort_created'),
            '5': this.transStr('tryhackx-homepage-blocks.forum.sort_views'),
            '6': this.transStr('tryhackx-homepage-blocks.forum.sort_magnet_sum'),
            '7': this.transStr('tryhackx-homepage-blocks.forum.sort_magnet_max'),
            '8': this.transStr('tryhackx-homepage-blocks.forum.sort_recently_clicked'),
        };

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
                this.renderSelect('filter_rating_interval', ratingIntervalOptions, 'ratingInterval'),
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
                    onmousedown: (e) => {
                        e.preventDefault();
                        this.startHold(filterKey);
                    },
                    onmouseup: () => this.stopHold(),
                    onmouseleave: () => this.stopHold(),
                    ontouchstart: (e) => {
                        e.preventDefault();
                        this.startHold(filterKey);
                    },
                    ontouchend: () => this.stopHold(),
                    ontouchcancel: () => this.stopHold(),
                    onclick: (e) => { e.preventDefault(); },
                }, m('i', { className: 'fas fa-times' })) : null,
            ]),
        ]);
    }

    /**
     * Accelerating-delete implementation. When the user presses and holds the
     * clear button, strip characters one at a time with an increasing tempo
     * (like holding the backspace key).
     */
    startHold(filterKey) {
        this.stopHold();
        this.holdActiveField = filterKey;

        // First tick: single character removed immediately
        this.tickHold(filterKey, this.holdDelay);
    }

    tickHold(filterKey, delay) {
        const current = this.filters[filterKey] || '';
        if (!current) {
            // Field empty — we're done
            this.stopHold();
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
            this.tickHold(filterKey, nextDelay);
        }, delay);
    }

    stopHold() {
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

        // Pre-flight guard: only when scope is actually gated
        if (recaptchaRequiredFor('search')) {
            const result = await preflightCheck('search');
            if (!result.ok && result.captchaRequired) {
                const token = await showCaptchaModal('search');
                if (!token) return; // user dismissed
                const retry = await preflightCheck('search', token);
                if (!retry.ok) return;
            } else if (!result.ok) {
                // Unrelated failure — bail out silently rather than spamming
                return;
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

        // Rating interval: custom RatingFilter
        if (this.filters.ratingInterval !== '0') {
            filter.ratingInterval = this.filters.ratingInterval;
        }

        // Date interval: custom DateIntervalFilter
        if (this.filters.dateInterval !== '0') {
            filter.dateInterval = this.filters.dateInterval;
        }

        // Sort mapping: UI index → { desc: sortKey, asc: sortKey }
        // Sort keys must match aliases registered in extend.php and DiscussionListState.sortMap()
        const sortMap = {
            '0': { desc: 'most_rated',         asc: 'least_rated' },          // Steam DB → use rating as fallback
            '1': { desc: 'most_rated',         asc: 'least_rated' },          // Avg rating
            '2': { desc: 'most_rating_count',  asc: 'least_rating_count' },   // Number of ratings
            '3': { desc: 'recently_rated',     asc: 'oldest_rated' },         // Recently rated
            '4': { desc: 'newest',             asc: 'oldest' },               // Creation date
            '5': { desc: 'most_viewed',        asc: 'least_viewed' },         // Views (fof/discussion-views)
            '6': { desc: 'newest',             asc: 'oldest' },               // Magnet sum (TODO)
            '7': { desc: 'newest',             asc: 'oldest' },               // Magnet max (TODO)
            '8': { desc: 'newest',             asc: 'oldest' },               // Recently clicked (TODO)
        };

        const sortEntry = sortMap[this.filters.sortBy] || sortMap['4'];
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
