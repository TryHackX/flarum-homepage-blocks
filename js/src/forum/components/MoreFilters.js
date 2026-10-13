import Component from 'flarum/common/Component';
import { transStr } from '../utils/trans';
import { advConfig, panelFields, fieldValue, activePanelCount, typePlacement, GROUPS, TYPE_FIELD, SIZE_UNITS } from '../utils/advanced';

const T = 'tryhackx-homepage-blocks.forum.';
let uid = 0;

/**
 * The bottom row of AdvancedFilters and the collapsible "More filters" panel:
 *
 *   [Clear]  [All | Movies | Series]  [⚙ More filters (2) ▾]
 *   ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─
 *   RELEASE   size · year · running time · …
 *   RATINGS & POPULARITY   Steam DB · stars · views · magnet clicks · …
 *   DATES     added · last activity
 *
 * The Type switch is here unless the admin moved it into the main row or the panel;
 * the panel lists only the fields the admin enabled.
 *
 * attrs:
 *   state      app.homepageFilters.adv (mutated in place)
 *   open       is the panel expanded
 *   onToggle   () → void
 *   onChange   (field, immediate) → void — a value changed (debounced unless immediate)
 *   onClear    () → void — reset every panel field
 *   typeLabel  (key) → option label with its live count
 *   updating   facet counts are being refreshed
 */
export default class MoreFilters extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.panelId = 'AdvancedFilters-more-' + ++uid;
    }

    view() {
        const { state, open } = this.attrs;
        const fields = panelFields();
        const typeButton = typePlacement() === 'button';
        if (!fields.length && !typeButton) return null;
        const active = activePanelCount(state);

        return m('div', { className: 'AdvancedFilters-moreWrap' }, [
            m('div', { className: 'AdvancedFilters-footer' }, [
                active
                    ? m('button', {
                        type: 'button',
                        className: 'AdvancedFilters-clearAll',
                        onclick: () => this.attrs.onClear(),
                    }, [m('i', { className: 'fas fa-eraser', 'aria-hidden': 'true' }), ' ', transStr(T + 'adv_clear')])
                    : null,
                typeButton ? this.typeSwitch() : null,
                fields.length
                    ? m('button', {
                        type: 'button',
                        className: 'AdvancedFilters-moreToggle' + (open ? ' is-open' : '') + (active ? ' has-active' : ''),
                        'aria-expanded': open ? 'true' : 'false',
                        'aria-controls': this.panelId,
                        onclick: () => this.attrs.onToggle(),
                    }, [
                        m('i', { className: 'fas fa-sliders-h', 'aria-hidden': 'true' }),
                        m('span', transStr(T + 'adv_more')),
                        active ? m('span', { className: 'AdvancedFilters-badge', title: transStr(T + 'adv_active', { count: active }) }, active) : null,
                        m('i', { className: 'fas fa-chevron-down AdvancedFilters-moreChevron', 'aria-hidden': 'true' }),
                    ])
                    : null,
            ]),
            fields.length
                ? m('div', {
                    id: this.panelId,
                    className: 'AdvancedFilters-more' + (open ? ' is-open' : ''),
                    role: 'region',
                    'aria-label': transStr(T + 'adv_more'),
                }, m('div', {
                    className: 'AdvancedFilters-moreInner',
                    // Collapsed: out of the tab order and hidden from screen readers.
                    inert: !open,
                    'aria-hidden': open ? undefined : 'true',
                }, m('div', { className: 'AdvancedFilters-grid' }, this.groupedFields(fields))))
                : null,
        ]);
    }

    /** Fields with a heading before each group that has any. */
    groupedFields(fields) {
        const out = [];
        GROUPS.forEach((group) => {
            const inGroup = fields.filter((f) => f.group === group);
            if (!inGroup.length) return;
            out.push(m('div', { className: 'AdvancedFilters-group', key: 'g-' + group, role: 'presentation' }, transStr(T + 'adv_group_' + group)));
            inGroup.forEach((f) => out.push(this.field(f)));
        });
        return out;
    }

    /** All / Movies / Series as a segmented button group (Type placement "button"). */
    typeSwitch() {
        const { state } = this.attrs;
        const cfg = advConfig();
        const label = (cfg && cfg.typeLabel) || transStr(T + 'adv_type');

        return m('div', {
            className: 'AdvancedFilters-typeSwitch' + (this.attrs.updating ? ' AdvancedFilters-field--updating' : ''),
            role: 'group',
            'aria-label': label,
        }, ['all', 'movie', 'series'].map((key) => {
            const on = state.mediaType === key;
            return m('button', {
                type: 'button',
                className: 'AdvancedFilters-typeOption' + (on ? ' is-active' : ''),
                'aria-pressed': on ? 'true' : 'false',
                onclick: () => {
                    if (on) return;
                    state.mediaType = key;
                    this.attrs.onChange(TYPE_FIELD, true);
                },
            }, this.attrs.typeLabel(key));
        }));
    }

    label(field) {
        if (field.kind === 'type') {
            const cfg = advConfig();
            return (cfg && cfg.typeLabel) || transStr(T + 'adv_type');
        }
        return transStr(T + 'adv_field_' + field.id);
    }

    field(field) {
        const { state } = this.attrs;

        if (field.kind === 'type' || field.kind === 'magnet') {
            const isType = field.kind === 'type';
            const key = isType ? 'mediaType' : 'hasMagnet';
            const options = isType
                ? [['all', this.attrs.typeLabel('all')], ['movie', this.attrs.typeLabel('movie')], ['series', this.attrs.typeLabel('series')]]
                : [['', transStr(T + 'adv_magnet_any')], ['1', transStr(T + 'adv_magnet_yes')], ['0', transStr(T + 'adv_magnet_no')]];
            const id = this.panelId + '-' + field.id;

            return m('div', {
                className: 'AdvancedFilters-range AdvancedFilters-range--select'
                    + (isType && this.attrs.updating ? ' AdvancedFilters-field--updating' : '')
                    + (fieldValue(field, state).value !== undefined ? ' is-active' : ''),
                key: field.id,
            }, [
                m('label', { for: id, className: 'AdvancedFilters-rangeLabel' }, this.label(field)),
                m('select', {
                    id,
                    className: 'FormControl',
                    value: state[key],
                    onchange: (e) => {
                        state[key] = e.target.value;
                        this.attrs.onChange(field, true);
                    },
                }, options.map(([value, text]) => m('option', { value }, text))),
            ]);
        }

        const result = fieldValue(field, state);
        const invalid = !!result.invalid;
        const label = this.label(field);
        const isDate = field.kind === 'date';
        const input = (side) => {
            const key = field.id + side;
            const placeholder = side === 'From'
                ? (field.placeholderFrom || transStr(T + 'adv_from'))
                : (field.placeholderTo || transStr(T + 'adv_to'));
            return m('input', {
                type: isDate ? 'date' : 'text',
                min: isDate ? '1970-01-01' : undefined,
                className: 'FormControl AdvancedFilters-rangeInput',
                inputmode: isDate ? undefined : (field.kind === 'int' ? 'numeric' : 'decimal'),
                autocomplete: 'off',
                maxlength: isDate ? undefined : 16,
                placeholder: isDate ? undefined : placeholder,
                value: state[key] || '',
                'aria-label': label + ' — ' + transStr(T + (side === 'From' ? 'adv_from' : 'adv_to')),
                'aria-invalid': invalid ? 'true' : undefined,
                oninput: (e) => {
                    state[key] = e.target.value;
                    this.attrs.onChange(field, isDate);
                },
                onkeydown: (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.attrs.onChange(field, true);
                    }
                },
            });
        };

        return m('fieldset', { className: 'AdvancedFilters-range' + (invalid ? ' is-invalid' : '') + (result.value !== undefined ? ' is-active' : ''), key: field.id }, [
            m('legend', { className: 'AdvancedFilters-rangeLabel' }, label),
            m('div', { className: 'AdvancedFilters-rangeInputs' + (isDate ? ' AdvancedFilters-rangeInputs--date' : '') }, [
                input('From'),
                m('span', { className: 'AdvancedFilters-rangeSep', 'aria-hidden': 'true' }, '–'),
                input('To'),
                field.kind === 'size'
                    ? m('select', {
                        className: 'FormControl AdvancedFilters-unit',
                        'aria-label': transStr(T + 'adv_unit'),
                        value: state.sizeUnit,
                        onchange: (e) => {
                            state.sizeUnit = e.target.value;
                            this.attrs.onChange(field, true);
                        },
                    }, SIZE_UNITS.map((u) => m('option', { value: u }, u)))
                    : null,
            ]),
            invalid ? m('div', { className: 'AdvancedFilters-rangeError', role: 'alert' }, transStr(T + 'adv_invalid')) : null,
        ]);
    }
}
