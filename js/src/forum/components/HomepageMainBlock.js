import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import RandomMovieButtons from './RandomMovieButtons';
import TrackerInfo from './TrackerInfo';
import TrackerStats from './TrackerStats';
import CustomLinks from './CustomLinks';

/**
 * HomepageMainBlock.
 *
 * Renders all four subsections with dividers between them.
 * Orphan dividers around hidden sections are hidden via CSS:
 *   .HomepageMainBlock-divider:first-child
 *   .HomepageMainBlock-divider:last-child
 *   .HomepageMainBlock-divider + .HomepageMainBlock-divider
 */
export default class HomepageMainBlock extends Component {
    view() {
        return m('div', { className: 'HomepageMainBlock' }, [
            m(RandomMovieButtons),
            m('div', { className: 'HomepageMainBlock-divider' }),
            m(TrackerInfo),
            m('div', { className: 'HomepageMainBlock-divider' }),
            m(TrackerStats),
            m('div', { className: 'HomepageMainBlock-divider' }),
            m(CustomLinks),
        ]);
    }

    oncreate(vnode) {
        super.oncreate(vnode);
        this.adjustDividers(vnode);
    }

    onupdate(vnode) {
        super.onupdate(vnode);
        this.adjustDividers(vnode);
    }

    /**
     * Hide the parent HomepageSection if all child components rendered nothing.
     * When TrackerInfo/RandomMovieButtons/TrackerStats/CustomLinks all return null,
     * the .HomepageMainBlock contains only dividers — hide the whole section.
     */
    adjustDividers(vnode) {
        const el = vnode.dom;
        if (!el) return;
        const hasContent = !!el.querySelector(
            '.RandomMovieButtons, .TrackerInfo, .TrackerStats, .CustomLinks'
        );
        // Walk up to find the surrounding HomepageSection (CollapsibleSection wrapper)
        let wrapper = el.parentElement;
        while (wrapper && !wrapper.classList.contains('HomepageSection')) {
            wrapper = wrapper.parentElement;
        }
        if (wrapper) {
            wrapper.style.display = hasContent ? '' : 'none';
        }
    }
}
