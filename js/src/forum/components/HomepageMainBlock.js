import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import TrackerInfo from './TrackerInfo';
import TrackerStats from './TrackerStats';

/**
 * HomepageMainBlock.
 *
 * Renders the tracker info + stats subsections with a divider between them.
 * Orphan dividers around hidden sections are hidden via CSS:
 *   .HomepageMainBlock-divider:first-child
 *   .HomepageMainBlock-divider:last-child
 *   .HomepageMainBlock-divider + .HomepageMainBlock-divider
 */
export default class HomepageMainBlock extends Component {
    view() {
        return m('div', { className: 'HomepageMainBlock' }, [
            m(TrackerInfo),
            m('div', { className: 'HomepageMainBlock-divider' }),
            m(TrackerStats),
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
     * When TrackerInfo/TrackerStats all return null,
     * the .HomepageMainBlock contains only dividers — hide the whole section.
     */
    adjustDividers(vnode) {
        const el = vnode.dom;
        if (!el) return;
        const hasContent = !!el.querySelector(
            '.TrackerInfo, .TrackerStats'
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
