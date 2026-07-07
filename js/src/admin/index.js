import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import ResetExtensionSettingsModal from 'flarum/admin/components/ResetExtensionSettingsModal';
import SupportModal from './components/SupportModal';

const S = 'tryhackx-homepage-blocks';

// Give the Cancel button in core's "Reset extension settings" modal Flarum's
// standard `Button--inverted` styling (core renders it as a plain borderless
// button). The modal is registered in the admin component registry, so we
// extend it directly instead of running a whole-document MutationObserver for
// the entire admin session. Guarded in case a future core build drops it.
app.initializers.add('tryhackx-homepage-blocks-cancel-inverted', () => {
    if (!ResetExtensionSettingsModal || !ResetExtensionSettingsModal.prototype) return;

    const invertCancel = function () {
        const root = this.element;
        if (!root) return;
        const cancel = root.querySelector('.Form-controls .Button:not(.Button--danger):not(.Button--primary)');
        if (cancel) cancel.classList.add('Button--inverted');
    };

    extend(ResetExtensionSettingsModal.prototype, 'oncreate', invertCancel);
    extend(ResetExtensionSettingsModal.prototype, 'onupdate', invertCancel);
});

/**
 * Normalize a Flarum setting value to a real boolean.
 * Before save: toggle returns `true`/`false` (raw from Switch).
 * After save: DB stores and returns '1' / '0' (strings).
 * Also handles numeric 1/0 and truthy/falsy edge cases.
 */
function settingIsTruthy(value) {
    if (value === true || value === 1 || value === '1' || value === 'true') return true;
    return false;
}

function textareaSetting(key, label, help, placeholder, rows) {
    return function () {
        return m('div', { className: 'Form-group' }, [
            m('label', label),
            help ? m('div', { className: 'helpText' }, help) : null,
            m('textarea', {
                className: 'FormControl',
                rows: rows || 4,
                placeholder: placeholder || '',
                value: this.setting(S + '.' + key)() || '',
                oninput: (e) => {
                    this.setting(S + '.' + key)(e.target.value);
                },
            }),
        ]);
    };
}

function sectionHeader(title) {
    return function () {
        return m('div', { className: 'Form-group HomepageBlocks-sectionHeader' }, [
            m('h3', title),
        ]);
    };
}

app.initializers.add('tryhackx-homepage-blocks-support', () => {
    app.registry.for('tryhackx-homepage-blocks').registerSetting(function () {
        return m('div', { className: 'HomepageBlocks-support' }, [
            m('button', {
                type: 'button',
                className: 'Button',
                onclick: () => app.modal.show(SupportModal),
            }, [
                m('i', { className: 'fas fa-heart Button-icon icon' }),
                app.translator.trans('tryhackx-homepage-blocks.admin.support.button'),
            ]),
        ]);
    });
});

app.initializers.add('tryhackx-homepage-blocks', () => {
    app.registry
        .for('tryhackx-homepage-blocks')

        // ──────────── GENERAL ────────────
        .registerSetting({
            setting: `${S}.section1_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.section1_enabled'),
        })
        .registerSetting({
            setting: `${S}.section2_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.section2_enabled'),
        })
        .registerSetting({
            setting: `${S}.section1_title`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.section1_title'),
            placeholder: 'TRACKER STATS',
        })
        .registerSetting({
            setting: `${S}.section2_title`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.section2_title'),
            placeholder: 'LATEST DISCUSSIONS',
        })
        .registerSetting({
            setting: `${S}.section1_collapsed`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.section1_collapsed'),
        })
        .registerSetting({
            setting: `${S}.hide_hero`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.hide_hero'),
        })
        .registerSetting({
            setting: `${S}.show_only_used_tags`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.show_only_used_tags'),
        })
        .registerSetting({
            setting: `${S}.show_tag_count`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.show_tag_count'),
        })
        .registerSetting({
            setting: `${S}.theme_mode`,
            type: 'select',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.theme_mode'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.theme_mode_help'),
            options: {
                auto: 'Auto',
                light: 'Light',
                dark: 'Dark',
            },
            default: 'auto',
        })
        .registerSetting({
            setting: `${S}.category_label`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.category_label'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.category_label_help'),
            placeholder: 'Category',
        })
        .registerSetting({
            setting: `${S}.resolution_label`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.resolution_label'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.resolution_label_help'),
            placeholder: 'Resolution',
        })

        // ──────────── TRACKER INFO ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_tracker')))
        .registerSetting({
            setting: `${S}.tracker_message`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.tracker_message'),
            placeholder: 'Use our trackers, add them first on the list.',
        })
        .registerSetting({
            setting: `${S}.tracker_sub_message`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.tracker_sub_message'),
            placeholder: '(Remember to put the HTTP tracker first!)',
        })
        .registerSetting(
            textareaSetting(
                'tracker_urls',
                app.translator.trans('tryhackx-homepage-blocks.admin.settings.tracker_urls'),
                app.translator.trans('tryhackx-homepage-blocks.admin.settings.tracker_urls_help'),
                'http://your-domain.com:6969/announce\nudp://your-domain.com:6969/announce',
                3
            )
        )

        // ──────────── INTERNAL STATS ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_stats')))
        .registerSetting({
            setting: `${S}.stats_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.stats_enabled'),
        })
        .registerSetting({
            setting: `${S}.stats_title`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.stats_title'),
            placeholder: 'Current tracker statistics:',
        })

        // ──────────── EXTERNAL STATS (OpenTracker) ────────────
        .registerSetting({
            setting: `${S}.external_stats_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_enabled'),
        })
        .registerSetting({
            setting: `${S}.external_stats_native_url`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_native_url'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_native_url_help'),
            placeholder: 'http://1.1.1.1:6969/stats?mode=everything',
        })
        .registerSetting({
            setting: `${S}.external_stats_cache_ttl`,
            type: 'number',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_cache_ttl'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_cache_ttl_help'),
            placeholder: '30',
            min: 1,
            max: 3600,
        })
        .registerSetting({
            setting: `${S}.external_stats_max_time`,
            type: 'number',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_max_time'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_max_time_help'),
            placeholder: '30',
            min: 1,
            max: 120,
        })
        .registerSetting({
            setting: `${S}.external_stats_refresh`,
            type: 'number',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_refresh'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_refresh_help'),
            placeholder: '5',
            min: 1,
            max: 300,
        })

        // ──────────── CONTENT SETTINGS ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_content')))

        // Title length
        .registerSetting({
            setting: `${S}.title_length_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.title_length_enabled'),
        })
        .registerSetting(function () {
            const enabled = settingIsTruthy(this.setting(S + '.title_length_enabled')());
            const defaultMin = '3';
            const defaultMax = '200';
            return m('div', { className: 'Form-group HomepageBlocks-lengthGroup', style: { opacity: enabled ? 1 : 0.5 } }, [
                m('div', { className: 'HomepageBlocks-lengthRow' }, [
                    m('div', { className: 'HomepageBlocks-lengthField' }, [
                        m('label', app.translator.trans('tryhackx-homepage-blocks.admin.settings.title_min_length')),
                        m('input', {
                            type: 'number',
                            className: 'FormControl',
                            min: 1,
                            max: 200,
                            disabled: !enabled,
                            value: this.setting(S + '.title_min_length')() || defaultMin,
                            oninput: (e) => this.setting(S + '.title_min_length')(e.target.value),
                        }),
                    ]),
                    m('div', { className: 'HomepageBlocks-lengthField' }, [
                        m('label', app.translator.trans('tryhackx-homepage-blocks.admin.settings.title_max_length')),
                        m('input', {
                            type: 'number',
                            className: 'FormControl',
                            min: 1,
                            max: 200,
                            disabled: !enabled,
                            value: this.setting(S + '.title_max_length')() || defaultMax,
                            oninput: (e) => this.setting(S + '.title_max_length')(e.target.value),
                        }),
                    ]),
                    m('button', {
                        type: 'button',
                        className: 'Button HomepageBlocks-lengthDefault',
                        disabled: !enabled,
                        onclick: () => {
                            this.setting(S + '.title_min_length')(defaultMin);
                            this.setting(S + '.title_max_length')(defaultMax);
                        },
                    }, app.translator.trans('tryhackx-homepage-blocks.admin.settings.restore_defaults')),
                ]),
                m('div', { className: 'helpText' }, app.translator.trans('tryhackx-homepage-blocks.admin.settings.title_length_help')),
            ]);
        })

        // Content length
        .registerSetting({
            setting: `${S}.content_length_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.content_length_enabled'),
        })
        .registerSetting(function () {
            const enabled = settingIsTruthy(this.setting(S + '.content_length_enabled')());
            const defaultMin = '1';
            const defaultMax = '500000';
            return m('div', { className: 'Form-group HomepageBlocks-lengthGroup', style: { opacity: enabled ? 1 : 0.5 } }, [
                m('div', { className: 'HomepageBlocks-lengthRow' }, [
                    m('div', { className: 'HomepageBlocks-lengthField' }, [
                        m('label', app.translator.trans('tryhackx-homepage-blocks.admin.settings.content_min_length')),
                        m('input', {
                            type: 'number',
                            className: 'FormControl',
                            min: 0,
                            max: 16000000,
                            disabled: !enabled,
                            value: this.setting(S + '.content_min_length')() || defaultMin,
                            oninput: (e) => this.setting(S + '.content_min_length')(e.target.value),
                        }),
                    ]),
                    m('div', { className: 'HomepageBlocks-lengthField' }, [
                        m('label', app.translator.trans('tryhackx-homepage-blocks.admin.settings.content_max_length')),
                        m('input', {
                            type: 'number',
                            className: 'FormControl',
                            min: 1,
                            max: 16000000,
                            disabled: !enabled,
                            value: this.setting(S + '.content_max_length')() || defaultMax,
                            oninput: (e) => this.setting(S + '.content_max_length')(e.target.value),
                        }),
                    ]),
                    m('button', {
                        type: 'button',
                        className: 'Button HomepageBlocks-lengthDefault',
                        disabled: !enabled,
                        onclick: () => {
                            this.setting(S + '.content_min_length')(defaultMin);
                            this.setting(S + '.content_max_length')(defaultMax);
                        },
                    }, app.translator.trans('tryhackx-homepage-blocks.admin.settings.restore_defaults')),
                ]),
                m('div', { className: 'helpText' }, app.translator.trans('tryhackx-homepage-blocks.admin.settings.content_length_help')),
            ]);
        })

        // ──────────── RATE LIMITING ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_security')))
        .registerSetting({
            setting: `${S}.search_debounce_ms`,
            type: 'number',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.search_debounce_ms'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.search_debounce_ms_help'),
            placeholder: '500',
            min: 100,
            max: 5000,
            step: 50,
        })

        // ──────────── POINTS-BASED RATE LIMITING ────────────
        .registerSetting({
            setting: `${S}.ratelimit_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.ratelimit_enabled'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.ratelimit_enabled_help'),
        })
        .registerSetting(function () {
            const enabled = settingIsTruthy(this.setting(S + '.ratelimit_enabled')());
            const t = (k) => app.translator.trans('tryhackx-homepage-blocks.admin.settings.' + k);

            const numField = (key, labelKey, helpKey, placeholder, step, min, max, disabledExtra) =>
                m('div', { className: 'HomepageBlocks-pointsField' }, [
                    m('label', t(labelKey)),
                    m('input', {
                        type: 'number',
                        className: 'FormControl',
                        placeholder: placeholder,
                        step: step,
                        min: min,
                        max: max,
                        disabled: !enabled || !!disabledExtra,
                        value: this.setting(S + '.' + key)() || '',
                        oninput: (e) => this.setting(S + '.' + key)(e.target.value),
                    }),
                    helpKey ? m('div', { className: 'helpText' }, t(helpKey)) : null,
                ]);

            const selectField = (key, labelKey, helpKey, current, options, disabledExtra) =>
                m('div', { className: 'HomepageBlocks-pointsField' }, [
                    m('label', t(labelKey)),
                    m('select', {
                        className: 'FormControl',
                        disabled: !enabled || !!disabledExtra,
                        value: current,
                        onchange: (e) => this.setting(S + '.' + key)(e.target.value),
                    }, options.map((o) => m('option', { value: o.value }, t(o.labelKey)))),
                    helpKey ? m('div', { className: 'helpText' }, t(helpKey)) : null,
                ]);

            // Po wyczerpaniu punktów: tymczasowa blokada IP (czas + reset budżetu).
            const blockReset = this.setting(S + '.ratelimit_block_reset')() === 'empty' ? 'empty' : 'full';

            const row = (children) => m('div', { className: 'HomepageBlocks-pointsRow' }, children);
            const heading = (key) => m('h4', { className: 'HomepageBlocks-pointsHeading' }, t(key));

            return m('div', { className: 'Form-group HomepageBlocks-pointsGroup' + (enabled ? '' : ' is-disabled') }, [
                // Budżet i odnawianie
                row([
                    numField.call(this, 'ratelimit_start', 'ratelimit_start', 'ratelimit_start_help', '10', 0.1, 0, 10000),
                    numField.call(this, 'ratelimit_refill_seconds', 'ratelimit_refill_seconds', 'ratelimit_refill_seconds_help', '15', 1, 1, 86400),
                    numField.call(this, 'ratelimit_refill_amount', 'ratelimit_refill_amount', 'ratelimit_refill_amount_help', '1', 0.1, 0, 10000),
                ]),

                // Koszty akcji (razem z dopłatą dla gości)
                heading('ratelimit_costs_header'),
                row([
                    numField.call(this, 'ratelimit_guest_extra', 'ratelimit_guest_extra', 'ratelimit_guest_extra_help', '2', 0.1, 0, 10000),
                    numField.call(this, 'ratelimit_cost_search', 'ratelimit_cost_search', null, '3', 0.1, 0, 10000),
                ]),

                // Po wyczerpaniu punktów → tymczasowa blokada IP
                heading('ratelimit_block_header'),
                row([
                    numField.call(this, 'ratelimit_block_seconds', 'ratelimit_block_seconds', 'ratelimit_block_seconds_help', '60', 1, 1, 86400),
                    selectField.call(this, 'ratelimit_block_reset', 'ratelimit_block_reset', 'ratelimit_block_reset_help', blockReset, [
                        { value: 'full', labelKey: 'ratelimit_block_reset_full' },
                        { value: 'empty', labelKey: 'ratelimit_block_reset_empty' },
                    ]),
                ]),
            ]);
        });
});
