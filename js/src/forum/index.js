import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import CollapsibleSection from './components/CollapsibleSection';
import HomepageMainBlock from './components/HomepageMainBlock';
import AdvancedFilters from './components/AdvancedFilters';

app.initializers.add('tryhackx-homepage-blocks', () => {
    // ── Theme detection and application ──
    function applyTheme() {
        try {
            if (!document || !document.body) return;

            // Read setting; default to 'light' if app.forum is not yet available
            let mode = 'light';
            try {
                if (app.forum) {
                    mode = app.forum.attribute('tryhackx-homepage-blocks.theme_mode') || 'auto';
                }
            } catch (e) {}

            let theme;
            if (mode === 'dark') {
                theme = 'dark';
            } else if (mode === 'light') {
                theme = 'light';
            } else {
                // Auto: detect from OS/browser preference or Flarum/theme dark mode
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const htmlEl = document.documentElement;
                const bodyEl = document.body;
                const htmlDark = htmlEl && (htmlEl.getAttribute('data-theme') === 'dark' || htmlEl.classList.contains('dark'));
                const bodyDark = bodyEl && (bodyEl.classList.contains('dark') || bodyEl.getAttribute('data-flarum-theme') === 'dark');
                theme = (prefersDark || htmlDark || bodyDark) ? 'dark' : 'light';
            }

            document.body.classList.remove('HomepageBlocks--dark', 'HomepageBlocks--light');
            document.body.classList.add('HomepageBlocks--' + theme);
        } catch (e) {
            // Never let theme errors break the rest of the extension
            console.warn('[HomepageBlocks] applyTheme failed:', e);
        }
    }

    // Apply immediately (may default to light if app.forum not ready yet)
    applyTheme();

    // Reapply when IndexPage renders (app.forum is guaranteed to be available)
    extend(IndexPage.prototype, 'oncreate', function () {
        applyTheme();
    });

    // Listen for OS theme changes when in auto mode
    if (window.matchMedia) {
        try {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                try {
                    if (app.forum && (app.forum.attribute('tryhackx-homepage-blocks.theme_mode') || 'auto') === 'auto') {
                        applyTheme();
                    }
                } catch (e) {}
            });
        } catch (e) {}
    }

    // Extend Flarum's sort map with our custom sort columns
    extend(DiscussionListState.prototype, 'sortMap', function (map) {
        map.most_rated = '-rating_average';
        map.least_rated = 'rating_average';
        map.most_rating_count = '-rating_count';
        map.least_rating_count = 'rating_count';
        map.recently_rated = '-last_rated_at';
        map.oldest_rated = 'last_rated_at';
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

        // Section 1: Random movies, tracker info, stats, custom links
        if (section1Enabled) {
            const section1Title =
                app.forum.attribute('tryhackx-homepage-blocks.section1_title') || 'RANDOM & TRACKER STATS';
            const section1DefaultCollapsed = app.forum.attribute('tryhackx-homepage-blocks.section1_collapsed');
            const section1Collapsed = getCollapsed(LS_KEY_S1, section1DefaultCollapsed);

            items.add(
                'homepage-main-block',
                m(CollapsibleSection, {
                    title: section1Title,
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
                    className: 'HomepageSection HomepageSection--listHeader' + (section2Collapsed ? ' HomepageSection--collapsed' : ''),
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
