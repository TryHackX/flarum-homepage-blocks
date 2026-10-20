import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import { preflightCheck, guardActiveFor, showRateLimitNotice } from '../utils/ratelimit';
import { transStr } from '../utils/trans';
import MoreFilters from './MoreFilters';
import {
    advConfig, typePlacement, sortEnabled, emptyAdvState, advFilterParams, fieldValue,
    clearPanel, isPanelOpen, setPanelOpen,
} from '../utils/advanced';

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
 *
 * The Category / Resolution lists show live counts ("facets") from
 * GET /api/tryhackx/homepage/facets: each option's number is what the list
 * returns if that option is picked together with all the OTHER active filters
 * (title, user, intervals, the other tag list) — so a number never promises
 * results that aren't there. Until the first response arrives the tags' global
 * counts are shown.
 */
export default class AdvancedFilters extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        // Restore filter state from global (survives collapse/expand)
        this.filters = app.homepageFilters;
        if (!this.filters.adv) this.filters.adv = emptyAdvState();
        // "More filters" panel: expanded state remembered per browser (admin sets the default).
        this.moreOpen = isPanelOpen();
        this.categoryTags = [];
        this.resolutionTags = [];
        this.debounceTimer = null;

        // Live counts (see class doc). Reuse the last response while it still
        // matches the filters (e.g. after collapse/expand), then refresh.
        const cached = app.homepageFacets;
        this.facets = cached && cached.key === JSON.stringify(this.facetParams()) ? cached.data : null;
        this.facetsLoading = false;
        this.facetSeq = 0;

        // Accelerating-delete "hold X" state
        this.holdTimer = null;
        this.holdActiveField = null;
        this.holdDelay = 90;           // initial delay before first tick (ms)
        this.minHoldDelay = 12;        // fastest tick (ms)
        this.holdAcceleration = 0.70;  // multiplicative decay per tick (snappier)

        this.loadTags();
        this.loadFacets();
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
        const categoryOptions = this.tagOptions(this.categoryTags, 'category');
        const resolutionOptions = this.tagOptions(this.resolutionTags, 'resolution');

        const ratingIntervalOptions = {
            '0': transStr('tryhackx-homepage-blocks.forum.interval_all'),
            'today': transStr('tryhackx-homepage-blocks.forum.interval_today'),
            '1d': transStr('tryhackx-homepage-blocks.forum.interval_1d'),
            '1w': transStr('tryhackx-homepage-blocks.forum.interval_1w'),
            '2w': transStr('tryhackx-homepage-blocks.forum.interval_2w'),
            '1m': transStr('tryhackx-homepage-blocks.forum.interval_1m'),
            '3m': transStr('tryhackx-homepage-blocks.forum.interval_3m'),
            '6m': transStr('tryhackx-homepage-blocks.forum.interval_6m'),
            '1y': transStr('tryhackx-homepage-blocks.forum.interval_1y'),
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
            '4': transStr('tryhackx-homepage-blocks.forum.sort_created'),
        };
        if (hasRating) {
            sortOptions['0'] = transStr('tryhackx-homepage-blocks.forum.sort_steamdb');
            sortOptions['1'] = transStr('tryhackx-homepage-blocks.forum.sort_avg_rating');
            sortOptions['2'] = transStr('tryhackx-homepage-blocks.forum.sort_rating_count');
            sortOptions['3'] = transStr('tryhackx-homepage-blocks.forum.sort_recently_rated');
        }
        if (hasViews) {
            sortOptions['5'] = transStr('tryhackx-homepage-blocks.forum.sort_views');
        }
        if (hasMagnet) {
            sortOptions['6'] = transStr('tryhackx-homepage-blocks.forum.sort_magnet_sum');
            sortOptions['7'] = transStr('tryhackx-homepage-blocks.forum.sort_magnet_max');
            sortOptions['8'] = transStr('tryhackx-homepage-blocks.forum.sort_recently_clicked');
        }
        // Advanced Filters index sorts (admin switches).
        if (sortEnabled('size')) sortOptions['9'] = transStr('tryhackx-homepage-blocks.forum.sort_size');
        if (sortEnabled('year')) sortOptions['10'] = transStr('tryhackx-homepage-blocks.forum.sort_year');

        const dateIntervalOptions = {
            '0': transStr('tryhackx-homepage-blocks.forum.interval_all'),
            'today': transStr('tryhackx-homepage-blocks.forum.interval_today'),
            '1d': transStr('tryhackx-homepage-blocks.forum.interval_1d'),
            '1w': transStr('tryhackx-homepage-blocks.forum.interval_1w'),
            '2w': transStr('tryhackx-homepage-blocks.forum.interval_2w'),
            '1m': transStr('tryhackx-homepage-blocks.forum.interval_1m'),
            '3m': transStr('tryhackx-homepage-blocks.forum.interval_3m'),
            '6m': transStr('tryhackx-homepage-blocks.forum.interval_6m'),
            '1y': transStr('tryhackx-homepage-blocks.forum.interval_1y'),
        };

        const directionOptions = {
            desc: transStr('tryhackx-homepage-blocks.forum.sort_desc'),
            asc: transStr('tryhackx-homepage-blocks.forum.sort_asc'),
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
                // Movies / series — here by default, or inside "More filters" (admin).
                typePlacement() === 'main' ? this.renderTypeSelect() : null,
                m('div', { className: 'AdvancedFilters-field AdvancedFilters-field--right' }, [
                    m('label', transStr('tryhackx-homepage-blocks.forum.filter_direction') + ':'),
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
            advConfig()
                ? m(MoreFilters, {
                    state: this.filters.adv,
                    open: this.moreOpen,
                    updating: this.facetsLoading,
                    typeLabel: (key) => this.typeOptionLabel(key),
                    onToggle: () => {
                        this.moreOpen = !this.moreOpen;
                        setPanelOpen(this.moreOpen);
                    },
                    onChange: (field, immediate) => this.onAdvChange(field, immediate),
                    onClear: () => {
                        clearPanel(this.filters.adv);
                        if (this.debounceTimer) clearTimeout(this.debounceTimer);
                        this.applyFilters();
                    },
                })
                : null,
        ]);
    }

    /** Type (movies / series) select for the main row, with live counts like the tag lists. */
    renderTypeSelect() {
        const cfg = advConfig();
        const label = (cfg && cfg.typeLabel) || transStr('tryhackx-homepage-blocks.forum.adv_type');
        return m('div', { className: 'AdvancedFilters-field AdvancedFilters-field--type' + (this.facetsLoading ? ' AdvancedFilters-field--updating' : '') }, [
            m('label', label + ':'),
            m('select', {
                className: 'FormControl',
                value: this.filters.adv.mediaType,
                onchange: (e) => {
                    this.filters.adv.mediaType = e.target.value;
                    this.applyFilters();
                },
            }, ['all', 'movie', 'series'].map((key) => m('option', { value: key }, this.typeOptionLabel(key)))),
        ]);
    }

    /** "Movies (130)" — the count is what the list returns with this option picked. */
    typeOptionLabel(key) {
        const name = transStr('tryhackx-homepage-blocks.forum.adv_type_' + key);
        const facet = this.facets && this.facets.mediaType;
        if (!app.forum.attribute('tryhackx-homepage-blocks.show_tag_count') || !facet) return name;
        const count = key === 'all' ? facet.total : facet[key];
        return typeof count === 'number' ? name + ' (' + count + ')' : name;
    }

    /**
     * A "More filters" field changed. Typing is debounced like the title search;
     * a select / date applies at once. While a field is invalid ("from" > "to", not a
     * number) the list keeps its last results instead of dropping that filter.
     */
    onAdvChange(field, immediate) {
        if (fieldValue(field, this.filters.adv).invalid) {
            if (this.debounceTimer) clearTimeout(this.debounceTimer);
            return;
        }
        if (immediate) {
            if (this.debounceTimer) clearTimeout(this.debounceTimer);
            this.applyFilters();
        } else {
            this.debounceApply();
        }
    }

    renderTextField(labelKey, placeholderKey, filterKey) {
        const value = this.filters[filterKey] || '';
        return m('div', { className: 'AdvancedFilters-field' }, [
            m('label', transStr('tryhackx-homepage-blocks.forum.' + labelKey) + ':'),
            m('div', { className: 'AdvancedFilters-inputWrap' }, [
                m('input', {
                    type: 'text',
                    className: 'FormControl AdvancedFilters-input' + (value ? ' has-value' : ''),
                    placeholder: transStr('tryhackx-homepage-blocks.forum.' + placeholderKey),
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
                    title: transStr('tryhackx-homepage-blocks.forum.clear_field'),
                    'aria-label': transStr('tryhackx-homepage-blocks.forum.clear_field'),
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

    /**
     * Options for a tag list: "All (n)" plus each tag with its live count.
     * With "show only used tags" on, zero-count tags are left out — except the
     * one currently picked, so it stays visible (and can be switched back).
     */
    tagOptions(tags, facetKey) {
        const showOnlyUsed = app.forum.attribute('tryhackx-homepage-blocks.show_only_used_tags');
        const showCount = app.forum.attribute('tryhackx-homepage-blocks.show_tag_count');
        const facet = this.facets && this.facets[facetKey];
        const selected = this.filters[facetKey];

        let allLabel = transStr('tryhackx-homepage-blocks.forum.filter_all');
        if (showCount && facet) allLabel += ' (' + facet.total + ')';

        const options = { all: allLabel };
        tags.forEach((t) => {
            const count = facet
                ? (facet.counts[t.id()] || 0)
                : ((t.discussionCount && t.discussionCount()) || 0);
            if (showOnlyUsed && count === 0 && t.slug() !== selected) return;
            options[t.slug()] = showCount ? t.name() + ' (' + count + ')' : t.name();
        });

        return options;
    }

    renderSelect(labelKey, options, filterKey, customLabel) {
        const label = customLabel || transStr('tryhackx-homepage-blocks.forum.' + labelKey);
        const isFacet = filterKey === 'category' || filterKey === 'resolution';
        return m('div', { className: 'AdvancedFilters-field' + (isFacet && this.facetsLoading ? ' AdvancedFilters-field--updating' : '') }, [
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
        this.classifyTags(app.store.all('tags'));

        // The forum payload only preloads SOME tags (Tags caps the secondary ones), so
        // e.g. "HDR" could be missing from the Resolution list although it has
        // discussions. Fetch the complete list once (cached by Tags' TagListState).
        if (app.tagList && typeof app.tagList.load === 'function') {
            app.tagList.load(['parent']).then(() => {
                this.classifyTags(app.store.all('tags'));
                m.redraw();
            }).catch(() => {});
        }
    }

    classifyTags(allTags) {
        this.categoryTags = [];
        this.resolutionTags = [];

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
     * If the points rate limiter is enabled and the search is heavy (title / user of
     * 3+ characters, or a range no index can serve — the only requests the server
     * meters), a pre-flight check is performed against the server; when the points
     * bucket is exhausted the IP is temporarily blocked and a countdown notice is
     * shown instead of running the query. Light changes never cost points.
     *
     * The pre-flight is the UX layer (instant countdown before the query fires). The
     * authoritative server-side guard is SearchRateLimitMiddleware on core
     * /api/discussions, which also throttles clients that skip this pre-flight
     * (curl/bot/scraper). To avoid double-charging, a successful pre-flight grants a
     * short-lived "grace" the middleware consumes for the follow-up real request.
     */
    async applyFilters() {
        app.homepageFilters = this.filters;

        // Pre-flight limitera: tylko gdy 'search' jest limitowany ORAZ to ciężkie
        // wyszukiwanie — serwer nalicza wyłącznie takie (SearchRateLimitMiddleware /
        // FacetsController). Lekka zmiana (tag, sortowanie, kierunek, zakres z indeksu)
        // nie kosztuje nic; wcześniej pre-flight pobierał punkty za KAŻDĄ zmianę, więc gość
        // dostawał blokadę już po kilku kliknięciach w listy. Żądanie liczników ciężkiego
        // wyszukiwania też jest limitowane — prosimy o drugi grace, by nie płacić podwójnie.
        if (guardActiveFor('search') && this.isHeavySearch()) {
            const result = await preflightCheck('search', { facets: true });
            if (!result.ok) {
                // Tymczasowa blokada IP — pokaż odliczanie. Inny błąd — wyjdź cicho.
                // W obu wypadkach nie wykonujemy zapytania.
                if (result.blocked) {
                    showRateLimitNotice(result.retryAfter);
                }
                return;
            }
        }

        this.runQuery();
    }

    isHeavySearch() {
        const long = (v) => !!v && v.trim().length >= 3;
        if (long(this.filters.title) || long(this.filters.user)) return true;
        // Ranges no index can serve are metered too (FacetCounter::HEAVY_KEYS).
        const adv = advFilterParams(this.filters.adv);
        return ['steamRange', 'clicksRange', 'clicksTopRange', 'clickedRange'].some((k) => adv[k] !== undefined);
    }

    /**
     * Non-tag filters exactly as sent to the API (shared by the list and the
     * facet counts, so both always describe the same search).
     */
    baseFilter() {
        const filter = {};

        // Title/User search: custom LIKE '%text%' filters. Min. 3 znaki — krótsze są
        // i tak ignorowane po stronie serwera (full-scan guard, audyt H2), więc nie
        // wysyłamy ich w ogóle (inaczej 1–2 znaki zwracałyby NIEfiltrowaną listę, co
        // myli). Próg lustrzany do TitleFilter/UserFilter.
        if (this.filters.title && this.filters.title.trim().length >= 3) {
            filter.title = this.filters.title;
        }

        if (this.filters.user && this.filters.user.trim().length >= 3) {
            filter.user = this.filters.user;
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

        // "More filters" (+ Type): only usable values are sent (see utils/advanced).
        Object.assign(filter, advFilterParams(this.filters.adv));

        return filter;
    }

    facetParams() {
        return {
            filter: this.baseFilter(),
            category: this.picked('category') || 'all',
            resolution: this.picked('resolution') || 'all',
        };
    }

    /** The tag slug picked in a list, or null for "All" (or an empty value). */
    picked(filterKey) {
        const value = this.filters[filterKey];
        return value && value !== 'all' ? value : null;
    }

    /**
     * Fetch the live Category/Resolution counts for the current filters. Only the
     * newest response is applied (a slow earlier one can't overwrite it); on any
     * error the previous counts simply stay.
     */
    loadFacets() {
        const params = this.facetParams();
        const key = JSON.stringify(params);
        const seq = ++this.facetSeq;
        this.facetsLoading = true;

        return app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/tryhackx/homepage/facets',
            params,
            // Background refresh: never pop an alert for it.
            errorHandler: () => {},
        }).then((data) => {
            if (seq !== this.facetSeq || !data || !data.category || !data.resolution) return;
            this.facets = data;
            app.homepageFacets = { key, data };
        }).catch(() => {}).finally(() => {
            if (seq !== this.facetSeq) return;
            this.facetsLoading = false;
            m.redraw();
        });
    }

    runQuery() {
        // Build filter object for Flarum API
        const filter = this.baseFilter();

        // Tag filters: combine category + resolution
        const tags = [this.picked('category'), this.picked('resolution')].filter(Boolean);
        if (tags.length) {
            filter.tag = tags;
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
            // Advanced Filters index sorts (Sort\MetaSort); unknown values always last.
            '9': { desc: 'hb_size_desc',  asc: 'hb_size_asc' },   // Release size
            '10': { desc: 'hb_year_desc', asc: 'hb_year_asc' },   // Release year
        };

        // Zabezpieczenie: jeśli zapisany wybór dotyczy sortowania z rozszerzenia,
        // którego nie ma, wróć do daty utworzenia (inaczej API zwróci błąd).
        const hasRating = typeof app.forum.attribute('tryhackxHomepageHasRating') !== 'undefined';
        const hasViews = typeof app.forum.attribute('tryhackxHomepageHasViews') !== 'undefined';
        const hasMagnet = typeof app.forum.attribute('magnetClickTracking') !== 'undefined';
        let sortBy = this.filters.sortBy;
        if (((['0', '1', '2', '3'].indexOf(sortBy) !== -1) && !hasRating) ||
            (sortBy === '5' && !hasViews) ||
            ((['6', '7', '8'].indexOf(sortBy) !== -1) && !hasMagnet) ||
            (sortBy === '9' && !sortEnabled('size')) ||
            (sortBy === '10' && !sortEnabled('year'))) {
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

        // Recount the Category/Resolution lists for the new filters.
        this.loadFacets();
    }
}
