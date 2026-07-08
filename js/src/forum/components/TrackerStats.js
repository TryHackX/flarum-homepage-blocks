import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { transStr } from '../utils/trans';

/**
 * TrackerStats component.
 *
 * Uses app.homepageStatsCache to persist data across collapse/expand cycles.
 * Internal stats load first (fast DB query), external stats load below (via the
 * cached PHP backend that fetches the native OpenTracker XML).
 * External stats auto-refresh every N seconds (configurable).
 */
export default class TrackerStats extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        // Initialize global cache if not exists
        if (!app.homepageStatsCache) {
            app.homepageStatsCache = {
                internalStats: null,
                externalStats: null,
                internalLoaded: false,
                externalLoaded: false,
            };
        }

        this.destroyed = false;

        const cache = app.homepageStatsCache;
        const internalEnabled = app.forum.attribute('tryhackx-homepage-blocks.stats_enabled');
        const externalEnabled = app.forum.attribute('tryhackx-homepage-blocks.external_stats_enabled');

        // Load internal stats only if not already cached
        if (internalEnabled !== false && !cache.internalLoaded) {
            this.loadInternalStats();
        }

        // Load external stats only if not already cached, or start refresh cycle
        if (externalEnabled) {
            if (!cache.externalLoaded) {
                this.loadExternalStats();
            } else {
                // Already have data — schedule next refresh
                this.scheduleRefresh(this.getRefreshInterval());
            }
        }
    }

    onremove() {
        this.destroyed = true;
        if (this._refreshTimer) {
            clearTimeout(this._refreshTimer);
            this._refreshTimer = null;
        }
    }

    getRefreshInterval() {
        return Math.max(1, Number(app.forum.attribute('tryhackx-homepage-blocks.external_stats_refresh') || 5)) * 1000;
    }

    view() {
        const internalEnabled = app.forum.attribute('tryhackx-homepage-blocks.stats_enabled');
        const externalEnabled = app.forum.attribute('tryhackx-homepage-blocks.external_stats_enabled');

        if (internalEnabled === false && !externalEnabled) {
            return null;
        }

        const cache = app.homepageStatsCache;
        const title = app.forum.attribute('tryhackx-homepage-blocks.stats_title') || 'Current tracker statistics:';

        const internalLabel = transStr('tryhackx-homepage-blocks.forum.stats_internal_label');
        const externalLabel = transStr('tryhackx-homepage-blocks.forum.stats_external_label');

        return m('div', { className: 'TrackerStats' }, [
            m('div', { className: 'TrackerStats-title' }, [
                m('strong', title),
            ]),

            // Internal database stats (loads first, fast)
            internalEnabled !== false ? [
                m('div', { className: 'TrackerStats-sectionLabel' }, internalLabel),
                this.renderInternalStats(cache),
            ] : null,

            // Divider between internal and external
            internalEnabled !== false && externalEnabled
                ? m('div', { className: 'TrackerStats-divider' })
                : null,

            // External tracker stats (OpenTracker) - loads below
            externalEnabled ? [
                m('div', { className: 'TrackerStats-sectionLabel' }, externalLabel),
                this.renderExternalStats(cache),
            ] : null,
        ]);
    }

    renderInternalStats(cache) {
        if (!cache.internalLoaded) {
            return m('div', { className: 'TrackerStats-loading' }, [
                m(LoadingIndicator, { size: 'small' }),
                m('span', ' Loading...'),
            ]);
        }

        if (!cache.internalStats) return null;

        const s = cache.internalStats;
        return m('div', { className: 'TrackerStats-grid' }, [
            this.renderStat('fas fa-film', s.discussions, 'tryhackx-homepage-blocks.forum.stats_torrents'),
            this.renderStat('fas fa-users', s.users, 'tryhackx-homepage-blocks.forum.stats_users'),
            this.renderStat('fas fa-magnet', s.magnets, 'tryhackx-homepage-blocks.forum.stats_magnets'),
            this.renderStat('fas fa-download', s.magnet_clicks, 'tryhackx-homepage-blocks.forum.stats_downloads'),
            this.renderStat('fas fa-eye', s.total_views, 'tryhackx-homepage-blocks.forum.stats_views'),
            s.rated_count > 0
                ? this.renderStat('fas fa-star', s.avg_rating + ' / 5', 'tryhackx-homepage-blocks.forum.stats_avg_rating')
                : null,
        ]);
    }

    renderExternalStats(cache) {
        if (!cache.externalLoaded && !cache.externalStats) {
            return m('div', { className: 'TrackerStats-loading TrackerStats-external' }, [
                m(LoadingIndicator, { size: 'small' }),
                m('span', ' Loading tracker stats...'),
            ]);
        }

        if (!cache.externalStats) return null;

        const s = cache.externalStats;
        return m('div', { className: 'TrackerStats-grid TrackerStats-external' }, [
            this.renderStat('fas fa-database', s.torrents, 'tryhackx-homepage-blocks.forum.stats_torrents'),
            this.renderStat('fas fa-arrow-up', s.seeds, 'tryhackx-homepage-blocks.forum.stats_seeds'),
            this.renderStat('fas fa-arrow-down', s.leechers, 'tryhackx-homepage-blocks.forum.stats_leechers'),
            this.renderStat('fas fa-exchange-alt', s.peers, 'tryhackx-homepage-blocks.forum.stats_peers'),
            this.renderStat('fas fa-check-circle', s.completed, 'tryhackx-homepage-blocks.forum.stats_completed'),
            s.uptime !== undefined
                ? this.renderStat('fas fa-clock', this.formatUptime(s.uptime), 'tryhackx-homepage-blocks.forum.stats_uptime')
                : null,
        ]);
    }

    renderStat(iconClass, value, labelKey) {
        return m('div', { className: 'TrackerStats-item' }, [
            m('i', { className: iconClass }),
            m('span', { className: 'TrackerStats-value' }, this.formatNumber(value)),
            m('span', { className: 'TrackerStats-label' }, app.translator.trans(labelKey)),
        ]);
    }

    formatNumber(num) {
        if (typeof num === 'string' && isNaN(num)) return num;
        const n = Number(num);
        if (isNaN(n)) return String(num || 0);
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return String(n);
    }

    formatUptime(seconds) {
        const s = Number(seconds);
        if (isNaN(s)) return String(seconds);
        const days = Math.floor(s / 86400);
        const hours = Math.floor((s % 86400) / 3600);
        const mins = Math.floor((s % 3600) / 60);
        if (days > 0) return days + 'd ' + hours + 'h';
        if (hours > 0) return hours + 'h ' + mins + 'm';
        return mins + 'm';
    }

    // ────────────── Data loading ──────────────

    async performStatsRequest(query) {
        let url = app.forum.attribute('apiUrl') + '/tryhackx/homepage/stats';
        if (query) url += query;
        // Statystyki nie są bramkowane (pasywne, cache'owane po stronie serwera).
        return app.request({ method: 'GET', url });
    }

    async loadInternalStats() {
        try {
            const response = await this.performStatsRequest('');
            app.homepageStatsCache.internalStats = response.data;
        } catch (e) {
            console.error('[HomepageBlocks] Failed to load internal stats:', e);
        }
        app.homepageStatsCache.internalLoaded = true;
        m.redraw();
    }

    async loadExternalStats() {
        if (this.destroyed) return;

        const cache = app.homepageStatsCache;
        const refreshInterval = this.getRefreshInterval();
        const startTime = Date.now();

        try {
            // Statystyki liczy i cache'uje backend PHP (natywny OpenTracker XML).
            // Pierwsze zimne pobranie bywa wolne (duży tracker), kolejne są
            // natychmiastowe ze wspólnego cache.
            const response = await this.performStatsRequest('?source=external');

            if (response && response.external) {
                const ext = response.external;
                // Leechers liczy backend (peers − seeds). Fallback wylicza to samo po
                // stronie klienta, gdyby serwowano starszy wpis cache bez tego pola.
                const leechers =
                    ext.leechers != null
                        ? ext.leechers
                        : Math.max(0, Number(ext.peers || 0) - Number(ext.seeds || 0));
                cache.externalStats = {
                    torrents: ext.torrents || 0,
                    seeds: ext.seeds || 0,
                    leechers: leechers,
                    peers: ext.peers || 0,
                    completed: ext.completed || 0,
                    uptime: ext.uptime || 0,
                };
                cache.externalLoaded = true;
                cache.externalPendingSince = null;
            } else if (response && response.external_pending && !cache.externalStats) {
                // Backend rozgrzewa cache (jeden worker pobiera od trackera). Trzymaj
                // spinner i dopytuj częściej, aż cache się zapełni — ZAMIAST gasić loader
                // po kilku sekundach (to był główny objaw: znikające „Loading…").
                if (!cache.externalPendingSince) cache.externalPendingSince = startTime;
                if (Date.now() - cache.externalPendingSince < this.getPendingGiveUpMs()) {
                    m.redraw();
                    if (!this.destroyed) this.scheduleRefresh(Math.min(refreshInterval, 3000));
                    return;
                }
                // Zbyt długo bez danych → tracker prawdopodobnie niedostępny; przestań
                // kręcić spinnerem (dalej cicho dopytujemy — dane wrócą, gdy tracker wróci).
                cache.externalLoaded = true;
            } else {
                // {external:null} bez „pending" = źródło wyłączone / brak URL.
                cache.externalLoaded = true;
                cache.externalPendingSince = null;
            }
        } catch (e) {
            console.error('[HomepageBlocks] Failed to load external stats:', e);
            cache.externalLoaded = true;
        }

        m.redraw();

        // Smart refresh: nie odświeżaj częściej niż interwał; jeśli żądanie trwało
        // dłużej — odśwież od razu (ale nie szybciej niż 500 ms).
        if (!this.destroyed) {
            const elapsed = Date.now() - startTime;
            const remaining = Math.max(500, refreshInterval - elapsed);
            this.scheduleRefresh(remaining);
        }
    }

    // Jak długo najdłużej trzymać spinner „pending", zanim uznamy tracker za
    // niedostępny. = serwerowy max_time + bufor: po tym czasie single-flight albo
    // zapełnił cache (mamy dane), albo padł — nie ma sensu kręcić w nieskończoność.
    getPendingGiveUpMs() {
        const maxTime = Number(app.forum.attribute('tryhackx-homepage-blocks.external_stats_max_time') || 30);
        return Math.max(15000, (maxTime + 20) * 1000);
    }

    scheduleRefresh(delay) {
        if (this.destroyed) return;
        if (this._refreshTimer) clearTimeout(this._refreshTimer);

        this._refreshTimer = setTimeout(() => {
            if (!this.destroyed) {
                this.loadExternalStats();
            }
        }, delay);
    }
}
