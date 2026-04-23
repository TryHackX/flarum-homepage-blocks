import app from 'flarum/admin/app';
import SupportModal from './components/SupportModal';

const S = 'tryhackx-homepage-blocks';

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
    app.extensionData.for('tryhackx-homepage-blocks').registerSetting(function () {
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
    app.extensionData
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
            placeholder: 'RANDOM & TRACKER STATS',
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

        // ──────────── RANDOM BUTTONS ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_random')))
        .registerSetting(
            textareaSetting(
                'random_buttons',
                app.translator.trans('tryhackx-homepage-blocks.admin.settings.random_buttons'),
                app.translator.trans('tryhackx-homepage-blocks.admin.settings.random_buttons_help'),
                '[{"label":"Random Tag Name","tag":"your-tag-slug"}]',
                5
            )
        )

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
            setting: `${S}.external_stats_mode`,
            type: 'select',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_mode'),
            options: {
                native: 'Native (OpenTracker XML)',
                proxy: 'Proxy (JSON)',
            },
            default: 'native',
        })
        .registerSetting({
            setting: `${S}.external_stats_native_url`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_native_url'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_native_url_help'),
            placeholder: 'http://1.1.1.1:6969/stats?mode=everything',
        })
        .registerSetting({
            setting: `${S}.external_stats_url`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_url'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.external_stats_url_help'),
            placeholder: 'https://your-domain.com/api/tracker-stats.php',
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

        // ──────────── CUSTOM LINKS ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_links')))
        .registerSetting(
            textareaSetting(
                'custom_links',
                app.translator.trans('tryhackx-homepage-blocks.admin.settings.custom_links'),
                app.translator.trans('tryhackx-homepage-blocks.admin.settings.custom_links_help'),
                '[{"label":"Custom Page","url":"/your-page","color":"#e74c3c"}]',
                3
            )
        )

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

        // ──────────── reCAPTCHA SECURITY ────────────
        .registerSetting(sectionHeader(app.translator.trans('tryhackx-homepage-blocks.admin.section_security')))
        .registerSetting({
            setting: `${S}.recaptcha_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_enabled'),
        })
        .registerSetting({
            setting: `${S}.recaptcha_version`,
            type: 'select',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_version'),
            options: {
                v2: 'reCAPTCHA v2',
                v3: 'reCAPTCHA v3',
            },
            default: 'v3',
        })
        .registerSetting({
            setting: `${S}.recaptcha_site_key`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_site_key'),
            placeholder: 'Site Key',
        })
        .registerSetting({
            setting: `${S}.recaptcha_secret_key`,
            type: 'text',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_secret_key'),
            placeholder: 'Secret Key',
        })
        .registerSetting({
            setting: `${S}.recaptcha_on_random`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_on_random'),
        })
        .registerSetting({
            setting: `${S}.recaptcha_on_stats`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_on_stats'),
        })
        .registerSetting({
            setting: `${S}.recaptcha_on_external_stats`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_on_external_stats'),
        })
        .registerSetting({
            setting: `${S}.recaptcha_skip_authenticated`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_skip_authenticated'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_skip_authenticated_help'),
        })
        .registerSetting({
            setting: `${S}.recaptcha_v3_threshold`,
            type: 'number',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_v3_threshold'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_v3_threshold_help'),
            placeholder: '0.5',
            min: 0,
            max: 1,
            step: 0.1,
        })
        .registerSetting({
            setting: `${S}.recaptcha_on_search`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_on_search'),
        })
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
            setting: `${S}.recaptcha_points_enabled`,
            type: 'bool',
            label: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_points_enabled'),
            help: app.translator.trans('tryhackx-homepage-blocks.admin.settings.recaptcha_points_enabled_help'),
        })
        .registerSetting(function () {
            const enabled = settingIsTruthy(this.setting(S + '.recaptcha_points_enabled')());
            const grp = { opacity: enabled ? 1 : 0.5 };
            const rowStyle = { display: 'flex', gap: '12px', flexWrap: 'wrap' };
            const fieldStyle = { flex: '1 1 180px', minWidth: '150px' };

            const numField = (key, labelKey, helpKey, placeholder, step, min, max) =>
                m('div', { className: 'Form-group', style: fieldStyle }, [
                    m('label', app.translator.trans('tryhackx-homepage-blocks.admin.settings.' + labelKey)),
                    m('input', {
                        type: 'number',
                        className: 'FormControl',
                        placeholder: placeholder,
                        step: step,
                        min: min,
                        max: max,
                        disabled: !enabled,
                        value: this.setting(S + '.' + key)() || '',
                        oninput: (e) => this.setting(S + '.' + key)(e.target.value),
                    }),
                    helpKey ? m('div', { className: 'helpText' }, app.translator.trans('tryhackx-homepage-blocks.admin.settings.' + helpKey)) : null,
                ]);

            return m('div', { className: 'Form-group HomepageBlocks-pointsGroup', style: grp }, [
                m('div', { style: rowStyle }, [
                    numField.call(this, 'recaptcha_points_start', 'recaptcha_points_start', 'recaptcha_points_start_help', '10', 0.1, 0, 10000),
                    numField.call(this, 'recaptcha_points_refill_seconds', 'recaptcha_points_refill_seconds', 'recaptcha_points_refill_seconds_help', '15', 1, 1, 86400),
                    numField.call(this, 'recaptcha_points_refill_amount', 'recaptcha_points_refill_amount', 'recaptcha_points_refill_amount_help', '1', 0.1, 0, 10000),
                ]),
                m('div', { style: rowStyle }, [
                    numField.call(this, 'recaptcha_points_guest_extra', 'recaptcha_points_guest_extra', 'recaptcha_points_guest_extra_help', '2', 0.1, 0, 10000),
                ]),
                m('h4', { style: { marginTop: '12px', marginBottom: '6px' } }, 'Action costs'),
                m('div', { style: rowStyle }, [
                    numField.call(this, 'recaptcha_points_cost_random', 'recaptcha_points_cost_random', null, '0.5', 0.1, 0, 10000),
                    numField.call(this, 'recaptcha_points_cost_search', 'recaptcha_points_cost_search', null, '3', 0.1, 0, 10000),
                    numField.call(this, 'recaptcha_points_cost_stats', 'recaptcha_points_cost_stats', null, '1', 0.1, 0, 10000),
                    numField.call(this, 'recaptcha_points_cost_external_stats', 'recaptcha_points_cost_external_stats', null, '1', 0.1, 0, 10000),
                ]),
            ]);
        });
});
