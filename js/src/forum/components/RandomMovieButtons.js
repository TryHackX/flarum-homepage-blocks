import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { appendRecaptchaToken, showCaptchaModal, recaptchaRequiredFor } from '../utils/recaptcha';

export default class RandomMovieButtons extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.loading = {};
        this.validButtons = null; // will be resolved after checking tags
    }

    view() {
        const buttonsJson = app.forum.attribute('tryhackx-homepage-blocks.random_buttons');
        let buttons = [];
        try {
            buttons = JSON.parse(buttonsJson || '[]');
        } catch (e) {
            buttons = [];
        }

        if (!buttons.length) return null;

        // Filter buttons to only those whose tags exist and have discussions
        const allTags = app.store.all('tags');
        const tagMap = new Map();
        allTags.forEach((t) => tagMap.set((t.slug() || '').toLowerCase(), t));
        const validButtons = buttons.filter((btn) => {
            const tag = tagMap.get((btn.tag || '').toLowerCase());
            if (!tag) return false;
            const count = tag.discussionCount ? tag.discussionCount() : 0;
            return count > 0;
        });

        if (!validButtons.length) return null;

        return m('div', { className: 'RandomMovieButtons' },
            validButtons.map((btn, i) =>
                m('button', {
                    className: 'Button RandomMovieButtons-btn' + (this.loading[i] ? ' loading' : ''),
                    disabled: !!this.loading[i],
                    onclick: (e) => this.randomize(btn.tag, i, e),
                    title: 'Ctrl+Click = open in new tab',
                }, [
                    this.loading[i]
                        ? m(LoadingIndicator, { size: 'small', display: 'inline' })
                        : m('i', { className: btn.icon || 'fas fa-random' }),
                    m('span', ' ' + btn.label),
                ])
            )
        );
    }

    async randomize(tagSlug, index, event) {
        if (this.loading[index]) return;

        const openInNewTab = event && (event.ctrlKey || event.metaKey || event.button === 1);

        this.loading[index] = true;
        m.redraw();

        try {
            const response = await this.performRequest(tagSlug);
            this.loading[index] = false;

            if (response && response.data) {
                const navUrl = app.forum.attribute('baseUrl') + '/d/' + response.data.id + '-' + response.data.slug;
                if (openInNewTab) {
                    window.open(navUrl, '_blank');
                } else {
                    m.route.set(app.route('discussion', { id: response.data.id + '-' + response.data.slug }));
                }
            }
            m.redraw();
        } catch (e) {
            this.loading[index] = false;
            app.alerts.show({ type: 'error' }, app.translator.trans('tryhackx-homepage-blocks.forum.random_not_found'));
            m.redraw();
        }
    }

    /**
     * Perform the random-discussion request, handling a captcha_required 403
     * response by prompting the user and retrying with a fresh token.
     */
    async performRequest(tagSlug, token = null) {
        let url = app.forum.attribute('apiUrl') + '/tryhackx/homepage/random?tag=' + encodeURIComponent(tagSlug);
        if (token) {
            url += '&recaptcha_token=' + encodeURIComponent(token);
        } else {
            url = await appendRecaptchaToken(url, 'random');
        }

        try {
            return await app.request({ method: 'GET', url });
        } catch (err) {
            // Flarum throws on non-2xx; inspect response for captcha_required
            const status = err && err.status;
            const body = err && err.response;
            if (status === 403 && body && (body.captcha_required || body.error === 'captcha_required')) {
                if (!recaptchaRequiredFor('random')) throw err;
                const freshToken = await showCaptchaModal('random');
                if (!freshToken) throw err; // dismissed
                return this.performRequest(tagSlug, freshToken);
            }
            throw err;
        }
    }
}
