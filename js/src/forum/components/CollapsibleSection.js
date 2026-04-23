import Component from 'flarum/common/Component';

export default class CollapsibleSection extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.collapsed = !!this.attrs.defaultCollapsed;
    }

    view(vnode) {
        const children = vnode.children || this.attrs.children || [];
        const iconClass = this.collapsed ? 'fas fa-chevron-down' : 'fas fa-chevron-up';

        return m('div', { className: 'HomepageSection' + (this.collapsed ? ' HomepageSection--collapsed' : '') }, [
            m(
                'div',
                {
                    className: 'HomepageSection-header',
                    onclick: () => this.toggle(),
                },
                [
                    m('span', { className: 'HomepageSection-title' }, this.attrs.title),
                    m('span', { className: 'HomepageSection-toggle' }, m('i', { className: iconClass })),
                ]
            ),
            !this.collapsed
                ? m('div', { className: 'HomepageSection-body' }, children)
                : null,
        ]);
    }

    toggle() {
        this.collapsed = !this.collapsed;
        // Notify parent (for localStorage persistence)
        if (this.attrs.onToggle) {
            this.attrs.onToggle(this.collapsed);
        }
    }
}
