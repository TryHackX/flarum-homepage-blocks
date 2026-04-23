import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { appendRecaptchaToken, showCaptchaModal, recaptchaRequiredFor } from '../utils/recaptcha';

/**
 * TrackerStats component.
 *
 * Uses app.homepageStatsCache to persist data across collapse/expand cycles.
 * Internal stats load first (fast DB query), external stats load below (via cached PHP proxy).
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

        const internalLabel = this.transStr('tryhackx-homepage-blocks.forum.stats_internal_label');
        const externalLabel = this.transStr('tryhackx-homepage-blocks.forum.stats_external_label');

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
            this.renderStat('fas fa-arrow-down', s.peers, 'tryhackx-homepage-blocks.forum.stats_peers'),
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

    transStr(key) {
        const result = app.translator.trans(key);
        if (Array.isArray(result)) {
            return result.map((item) => (typeof item === 'string' ? item : '')).join('');
        }
        return String(result || '');
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

    async performStatsRequest(scope, query, token = null) {
        let url = app.forum.attribute('apiUrl') + '/tryhackx/homepage/stats';
        if (query) url += query;
        if (token) {
            const sep = url.indexOf('?') !== -1 ? '&' : '?';
            url += sep + 'recaptcha_token=' + encodeURIComponent(token);
        } else {
            url = await appendRecaptchaToken(url, scope);
        }

        try {
            return await app.request({ method: 'GET', url });
        } catch (err) {
            const status = err && err.status;
            const body = err && err.response;
            if (status === 403 && body && (body.captcha_required || body.error === 'captcha_required')) {
                if (!recaptchaRequiredFor(scope)) throw err;
                const fresh = await showCaptchaModal(scope);
                if (!fresh) throw err;
                return this.performStatsRequest(scope, query, fresh);
            }
            throw err;
        }
    }

    async loadInternalStats() {
        try {
            const response = await this.performStatsRequest('stats', '');
            app.homepageStatsCache.internalStats = response.data;
        } catch (e) {
            console.error('[HomepageBlocks] Failed to load internal stats:', e);
        }
        app.homepageStatsCache.internalLoaded = true;
        m.redraw();
    }

    async loadExternalStats() {
        if (this.destroyed) return;

        const refreshInterval = this.getRefreshInterval();
        const startTime = Date.now();

        try {
            // Use PHP proxy only (direct fetch is blocked by CORS).
            // PHP proxy has file-based cache — first request is slow, subsequent are instant.
            const response = await this.performStatsRequest('external_stats', '?source=external');

            if (response.external) {
                app.homepageStatsCache.externalStats = {
                    torrents: response.external.torrents || 0,
                    seeds: response.external.seeds || 0,
                    peers: response.external.peers || 0,
                    completed: response.external.completed || 0,
                    uptime: response.external.uptime || 0,
                };
            }
        } catch (e) {
            console.error('[HomepageBlocks] Failed to load external stats:', e);
        }

        app.homepageStatsCache.externalLoaded = true;
        m.redraw();

        // Smart refresh: if request took 3s and interval is 5s → wait 2s.
        // If request took 6s → refresh immediately.
        if (!this.destroyed) {
            const elapsed = Date.now() - startTime;
            const remaining = Math.max(500, refreshInterval - elapsed);
            this.scheduleRefresh(remaining);
        }
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
