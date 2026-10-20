import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import CollapsibleSection from './components/CollapsibleSection';
import HomepageMainBlock from './components/HomepageMainBlock';
import AdvancedFilters from './components/AdvancedFilters';
import { emptyAdvState, sortEnabled } from './utils/advanced';
import { transStr } from './utils/trans';

app.initializers.add('tryhackx-homepage-blocks', () => {
    // ── Theme ──
    // "Auto" needs no JS: the stylesheet follows Flarum's own `data-theme` on
    // <html>, which Flarum switches live (header theme switcher, user preference,
    // OS change). Only a palette FORCED by the admin ("Light" / "Dark") is applied
    // here, as a class on <html> that outranks the [data-theme] rules.
    function applyTheme() {
        try {
            let mode = 'auto';
            try {
                if (app.forum) {
                    mode = app.forum.attribute('tryhackx-homepage-blocks.theme_mode') || 'auto';
                }
            } catch (e) {}

            const root = document.documentElement;
            root.classList.remove('HomepageBlocks--dark', 'HomepageBlocks--light');
            if (mode === 'dark' || mode === 'light') {
                root.classList.add('HomepageBlocks--' + mode);
            }
        } catch (e) {
            // Never let theme errors break the rest of the extension
            console.warn('[HomepageBlocks] applyTheme failed:', e);
        }
    }

    // Initializers run before the forum payload is loaded, so apply (again) once
    // the index page — the only page with the blocks — has rendered.
    applyTheme();
    extend(IndexPage.prototype, 'oncreate', applyTheme);

    // Extend Flarum's sort map with our custom sort columns. Each entry carries its own
    // label: for a plain string core's sort dropdown (shown while section 2 is collapsed
    // or off) looks up `core.forum.index_sort.<key>_button`, which doesn't exist for
    // these keys — the raw key was shown. Every group is gated on what the server
    // registers, so the dropdown never offers a sort that would end in an API error and
    // a stale URL degrades to the default sort.
    extend(DiscussionListState.prototype, 'sortMap', function (map) {
        const add = (key, sort) => {
            map[key] = { sort, label: transStr('tryhackx-homepage-blocks.forum.index_sort.' + key) };
        };

        // tryhackx/flarum-topic-rating (its columns + the "Steam DB" confidence-weighted rating).
        if (typeof app.forum.attribute('tryhackxHomepageHasRating') !== 'undefined') {
            add('most_rated', '-rating_average');
            add('least_rated', 'rating_average');
            add('most_rating_count', '-rating_count');
            add('least_rating_count', 'rating_count');
            add('recently_rated', '-last_rated_at');
            add('oldest_rated', 'last_rated_at');
            add('steamdb', '-steamRating');
            add('steamdb_asc', 'steamRating');
        }

        // Magnet-click sorts — resolve the friendly aliases to the API sort
        // fields registered by tryhackx/flarum-magnet-link.
        if (typeof app.forum.attribute('magnetClickTracking') !== 'undefined') {
            add('most_magnet_clicks', '-magnetClicksTotal');
            add('least_magnet_clicks', 'magnetClicksTotal');
            add('most_magnet_clicks_single', '-magnetClicksMax');
            add('least_magnet_clicks_single', 'magnetClicksMax');
            add('recently_magnet_clicked', '-magnetLastClicked');
            add('oldest_magnet_clicked', 'magnetLastClicked');
        }

        // Advanced Filters index sorts (Sort\MetaSort) — only while the admin offers them.
        if (sortEnabled('size')) {
            add('hb_size_desc', '-hbSize');
            add('hb_size_asc', 'hbSize');
        }
        if (sortEnabled('year')) {
            add('hb_year_desc', '-hbYear');
            add('hb_year_asc', 'hbYear');
        }
    });

    // Initialize global filter state with defaults matching option keys in AdvancedFilters
    app.homepageFilters = {
        title: '',
        user: '',
        ratingInterval: '0',   // '0' = All
        sortBy: '4',           // '4' = Creation date
        dateInterval: '0',     // '0' = All
        category: 'all',
        resolution: 'all',
        sortDirection: 'desc',
        adv: emptyAdvState(), // "More filters" + Type (utils/advanced)
    };

    // Collapsed state from localStorage (persists across page loads)
    const LS_KEY_S1 = 'tryhackx_section1_collapsed';
    const LS_KEY_S2 = 'tryhackx_section2_collapsed';

    function getCollapsed(key, adminDefault) {
        try {
            const stored = localStorage.getItem(key);
            if (stored !== null) return stored === '1';
        } catch (e) {}
        return !!adminDefault;
    }

    function setCollapsed(key, val) {
        try { localStorage.setItem(key, val ? '1' : '0'); } catch (e) {}
    }

    // Hide default hero if setting enabled
    override(IndexPage.prototype, 'hero', function (original) {
        if (app.forum.attribute('tryhackx-homepage-blocks.hide_hero')) {
            return m('div');
        }
        return original();
    });

    // Hide Flarum's built-in sort dropdown when our advanced filters are visible
    // Sort is inside viewItems(), not toolbarItems()
    extend(IndexPage.prototype, 'viewItems', function (items) {
        const section2Enabled = app.forum.attribute('tryhackx-homepage-blocks.section2_enabled') !== false;
        if (!section2Enabled) return;

        const section2Collapsed = getCollapsed(LS_KEY_S2, false);
        if (!section2Collapsed && items.has('sort')) {
            items.remove('sort');
        }
    });

    // Add homepage blocks above the discussion list
    extend(IndexPage.prototype, 'contentItems', function (items) {
        const section1Enabled = app.forum.attribute('tryhackx-homepage-blocks.section1_enabled') !== false;
        const section2Enabled = app.forum.attribute('tryhackx-homepage-blocks.section2_enabled') !== false;
        // Visual style (admin): "fitted" blends in, "original" is the card design.
        const styleClass = app.forum.attribute('tryhackx-homepage-blocks.style') === 'original' ? 'HomepageSection--original' : '';

        // Section 1: tracker info, stats, custom links
        if (section1Enabled) {
            const section1Title =
                app.forum.attribute('tryhackx-homepage-blocks.section1_title') || 'TRACKER STATS';
            const section1DefaultCollapsed = app.forum.attribute('tryhackx-homepage-blocks.section1_collapsed');
            const section1Collapsed = getCollapsed(LS_KEY_S1, section1DefaultCollapsed);

            items.add(
                'homepage-main-block',
                m(CollapsibleSection, {
                    title: section1Title,
                    className: styleClass,
                    defaultCollapsed: section1Collapsed,
                    onToggle: (collapsed) => {
                        setCollapsed(LS_KEY_S1, collapsed);
                    },
                }, m(HomepageMainBlock)),
                200
            );
        }

        // Section 2: header + filters combined in one item (no gap between them)
        if (section2Enabled) {
            const section2Title =
                app.forum.attribute('tryhackx-homepage-blocks.section2_title') || 'LATEST DISCUSSIONS';
            const section2Collapsed = getCollapsed(LS_KEY_S2, false);
            const section2IconClass = section2Collapsed ? 'fas fa-chevron-down' : 'fas fa-chevron-up';

            items.add(
                'homepage-section-header',
                m('div', {
                    className: 'HomepageSection HomepageSection--listHeader' + (styleClass ? ' ' + styleClass : '') + (section2Collapsed ? ' HomepageSection--collapsed' : ''),
                }, [
                    m('div', {
                        className: 'HomepageSection-header',
                        onclick: () => {
                            const newState = !getCollapsed(LS_KEY_S2, false);
                            setCollapsed(LS_KEY_S2, newState);
                            m.redraw();
                        },
                    }, [
                        m('span', { className: 'HomepageSection-title' }, section2Title),
                        m('span', { className: 'HomepageSection-toggle' }, m('i', { className: section2IconClass })),
                    ]),
                    // Filters inside the same container — no gap
                    !section2Collapsed ? m(AdvancedFilters) : null,
                ]),
                150
            );
        }
    });
});
