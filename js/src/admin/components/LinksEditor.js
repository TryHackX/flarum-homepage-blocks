import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import app from 'flarum/admin/app';

const T = 'tryhackx-homepage-blocks.admin.settings.';

/**
 * Przyjazny edytor własnych linków Sekcji 1 — karty zamiast surowego JSON.
 *
 * Każdy link: { label, url, color, external, className }. Przechowywany dalej
 * jako JSON w ustawieniu `custom_links` (zgodność wsteczna z poprzednim polem
 * tekstowym i z frontendowym komponentem CustomLinks). `className` pozwala
 * przypiąć własną klasę CSS (np. glow-on-hover) zdefiniowaną w polu „Własny CSS".
 *
 * Props:
 *   - value:    () => string    — getter aktualnego JSON-a ustawienia
 *   - onchange: (json) => void   — zapis JSON-a (oznacza ustawienie jako zmienione)
 */
export default class LinksEditor extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.links = this.parse();
    }

    parse() {
        try {
            const arr = JSON.parse((this.attrs.value && this.attrs.value()) || '[]');
            if (!Array.isArray(arr)) return [];
            return arr.map((l) => ({
                label: typeof l.label === 'string' ? l.label : '',
                url: typeof l.url === 'string' ? l.url : '',
                color: typeof l.color === 'string' ? l.color : '#e74c3c',
                external: !!l.external,
                className: typeof l.className === 'string' ? l.className : '',
            }));
        } catch (e) {
            return [];
        }
    }

    commit() {
        if (typeof this.attrs.onchange === 'function') {
            this.attrs.onchange(JSON.stringify(this.links));
        }
    }

    trans(key) {
        const r = app.translator.trans(T + key);
        return Array.isArray(r) ? r.map((x) => (typeof x === 'string' ? x : '')).join('') : String(r || '');
    }

    view() {
        return m('div', { className: 'HomepageBlocks-linksEditor' }, [
            this.links.map((link, i) => this.renderRow(link, i)),
            m(Button, {
                className: 'Button HomepageBlocks-linksAdd',
                icon: 'fas fa-plus',
                onclick: () => {
                    this.links.push({ label: '', url: '', color: '#e74c3c', external: true, className: '' });
                    this.commit();
                },
            }, this.trans('custom_links_add')),
        ]);
    }

    renderRow(link, i) {
        return m('div', { className: 'HomepageBlocks-linkRow', key: i }, [
            m('div', { className: 'HomepageBlocks-linkRow-fields' }, [
                this.field('HomepageBlocks-linkName', 'custom_links_name', m('input', {
                    type: 'text',
                    className: 'FormControl',
                    placeholder: this.trans('custom_links_name'),
                    value: link.label,
                    oninput: (e) => { link.label = e.target.value; this.commit(); },
                })),
                this.field('HomepageBlocks-linkUrl', 'custom_links_url', m('input', {
                    type: 'url',
                    className: 'FormControl',
                    placeholder: this.trans('custom_links_url'),
                    value: link.url,
                    oninput: (e) => { link.url = e.target.value; this.commit(); },
                })),
                this.field('HomepageBlocks-linkClass', 'custom_links_class', m('input', {
                    type: 'text',
                    className: 'FormControl',
                    placeholder: this.trans('custom_links_class_placeholder'),
                    value: link.className,
                    oninput: (e) => { link.className = e.target.value; this.commit(); },
                })),
            ]),
            m('div', { className: 'HomepageBlocks-linkRow-meta' }, [
                m('label', { className: 'HomepageBlocks-linkColor' }, [
                    m('span', this.trans('custom_links_color')),
                    m('input', {
                        type: 'color',
                        value: /^#[0-9a-fA-F]{6}$/.test(link.color) ? link.color : '#e74c3c',
                        oninput: (e) => { link.color = e.target.value; this.commit(); },
                    }),
                ]),
                m('label', { className: 'HomepageBlocks-linkExternal' }, [
                    m('input', {
                        type: 'checkbox',
                        checked: link.external,
                        onchange: (e) => { link.external = e.target.checked; this.commit(); },
                    }),
                    m('span', this.trans('custom_links_newtab')),
                ]),
                m('div', { className: 'HomepageBlocks-linkRow-actions' }, [
                    m(Button, {
                        className: 'Button Button--icon',
                        icon: 'fas fa-arrow-up',
                        disabled: i === 0,
                        title: this.trans('custom_links_move_up'),
                        onclick: () => this.move(i, -1),
                    }),
                    m(Button, {
                        className: 'Button Button--icon',
                        icon: 'fas fa-arrow-down',
                        disabled: i === this.links.length - 1,
                        title: this.trans('custom_links_move_down'),
                        onclick: () => this.move(i, 1),
                    }),
                    m(Button, {
                        className: 'Button Button--icon Button--danger',
                        icon: 'fas fa-trash',
                        title: this.trans('custom_links_remove'),
                        onclick: () => { this.links.splice(i, 1); this.commit(); },
                    }),
                ]),
            ]),
        ]);
    }

    field(className, labelKey, input) {
        return m('div', { className: 'HomepageBlocks-linkField ' + className }, [
            m('label', this.trans(labelKey)),
            input,
        ]);
    }

    move(i, dir) {
        const j = i + dir;
        if (j < 0 || j >= this.links.length) return;
        const tmp = this.links[i];
        this.links[i] = this.links[j];
        this.links[j] = tmp;
        this.commit();
    }
}
