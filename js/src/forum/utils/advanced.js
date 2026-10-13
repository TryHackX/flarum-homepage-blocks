import app from 'flarum/forum/app';

/**
 * "More filters" — shared helpers for AdvancedFilters / MoreFilters.
 *
 * The admin switches arrive as the forum attribute `tryhackxHomepageAdv`
 * (null while the feature is off): { filters: [ids], typePlacement, typeLabel,
 * openDefault, sorts: ['size','year'], binaryUnits }.
 *
 * Every range is sent to the API as `filter[<key>]=min..max` (either side optional);
 * see src/Search/Advanced/*. Empty / invalid fields are never sent (an empty filter
 * would still count as "active" server-side).
 */

/**
 * Panel fields in display order, in groups (a group's heading shows only when one of
 * its fields is enabled). kind: date | int | decimal | size | magnet
 */
export const GROUPS = ['release', 'community', 'dates'];

export const FIELDS = [
    // Release data (the metadata index)
    { id: 'size', key: 'sizeRange', kind: 'size', group: 'release' },
    { id: 'year', key: 'yearRange', kind: 'int', group: 'release', placeholderFrom: '1990', placeholderTo: '2025' },
    { id: 'duration', key: 'durationRange', kind: 'int', group: 'release' },
    { id: 'seasons', key: 'seasonsRange', kind: 'int', group: 'release' },
    { id: 'episodes', key: 'episodesRange', kind: 'int', group: 'release' },
    { id: 'imdb', key: 'imdbRange', kind: 'decimal', group: 'release' },
    { id: 'filmweb', key: 'filmwebRange', kind: 'decimal', group: 'release' },
    // Ratings & popularity (the same figures the sort options use)
    { id: 'steam', key: 'steamRange', kind: 'decimal', group: 'community' },
    { id: 'rating', key: 'ratingRange', kind: 'decimal', group: 'community' },
    { id: 'rating_count', key: 'ratingCountRange', kind: 'int', group: 'community' },
    { id: 'rated', key: 'ratedRange', kind: 'date', group: 'community' },
    { id: 'views', key: 'viewsRange', kind: 'int', group: 'community' },
    { id: 'replies', key: 'repliesRange', kind: 'int', group: 'community' },
    { id: 'clicks', key: 'clicksRange', kind: 'int', group: 'community' },
    { id: 'clicks_top', key: 'clicksTopRange', kind: 'int', group: 'community' },
    { id: 'clicked', key: 'clickedRange', kind: 'date', group: 'community' },
    { id: 'magnet', key: 'hasMagnet', kind: 'magnet', group: 'community' },
    // Dates
    { id: 'created', key: 'createdRange', kind: 'date', group: 'dates' },
    { id: 'active', key: 'activeRange', kind: 'date', group: 'dates' },
];

export const TYPE_FIELD = { id: 'type', key: 'mediaType', kind: 'type', group: 'release' };

export const SIZE_UNITS = ['MB', 'GB', 'TB'];

/** The admin payload, or null while the feature is off. */
export function advConfig() {
    const cfg = app.forum.attribute('tryhackxHomepageAdv');
    return cfg && typeof cfg === 'object' ? cfg : null;
}

export function isEnabled(id) {
    const cfg = advConfig();
    return !!cfg && Array.isArray(cfg.filters) && cfg.filters.indexOf(id) !== -1;
}

/**
 * Is the Type filter on, and where does it go: 'button' (a switch next to "More
 * filters", default) | 'main' (a select in the main row) | 'panel' | null (off)?
 */
export function typePlacement() {
    if (!isEnabled('type')) return null;
    const p = advConfig().typePlacement;
    return p === 'main' || p === 'panel' ? p : 'button';
}

/** Panel fields the admin enabled (Type included when it lives in the panel). */
export function panelFields() {
    const fields = FIELDS.filter((f) => isEnabled(f.id));
    return typePlacement() === 'panel' ? [TYPE_FIELD].concat(fields) : fields;
}

export function sortEnabled(id) {
    const cfg = advConfig();
    return !!cfg && Array.isArray(cfg.sorts) && cfg.sorts.indexOf(id) !== -1;
}

/** Fresh "More filters" state (all fields empty). */
export function emptyAdvState() {
    const state = { mediaType: 'all', sizeUnit: 'GB', hasMagnet: '' };
    FIELDS.forEach((f) => {
        if (f.kind !== 'magnet') {
            state[f.id + 'From'] = '';
            state[f.id + 'To'] = '';
        }
    });
    return state;
}

const NUMBER = /^\d{1,15}([.,]\d{1,6})?$/;
const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Local day → unix seconds (start of that day, or its last second for "to"). */
function dayBound(value, end) {
    const m = DATE.exec(value || '');
    if (!m) return null;
    const date = new Date(+m[1], +m[2] - 1, +m[3] + (end ? 1 : 0), 0, 0, 0, 0);
    if (isNaN(date.getTime())) return null;
    const ts = Math.floor(date.getTime() / 1000) - (end ? 1 : 0);
    return ts >= 0 ? ts : null; // the API takes unix timestamps ≥ 1970
}

/** Server-side limits (see src/Search/Advanced/*): whole numbers and maxima per field. */
const LIMITS = {
    year: { int: true, max: 9999 },
    seasons: { int: true, max: 500 },
    episodes: { int: true, max: 100000 },
    duration: { int: true, max: 6000 },
    imdb: { max: 10 },
    filmweb: { max: 10 },
    steam: { max: 100 },
    rating: { max: 5 },
};

function numeric(value, field) {
    const v = String(value || '').trim();
    if (v === '') return { empty: true };
    if (!NUMBER.test(v)) return { invalid: true };
    const limits = LIMITS[field.id] || {};
    const n = parseFloat(v.replace(',', '.'));
    if ((field.kind === 'int' || limits.int) && !/^\d+$/.test(v)) return { invalid: true };
    if (limits.max !== undefined && n > limits.max) return { invalid: true };
    return { value: n, text: v.replace(',', '.') };
}

/**
 * One field's API value: { value } to send, { empty } when unset, { invalid } when
 * the input can't be used (bad number, "from" > "to").
 */
export function fieldValue(field, state) {
    if (field.kind === 'type') {
        return state.mediaType === 'movie' || state.mediaType === 'series' ? { value: state.mediaType } : { empty: true };
    }
    if (field.kind === 'magnet') {
        // "yes" / "no", never "1" / "0": the facets endpoint treats a bare "0" as "All".
        if (state.hasMagnet === '1') return { value: 'yes' };
        if (state.hasMagnet === '0') return { value: 'no' };
        return { empty: true };
    }

    const fromRaw = state[field.id + 'From'];
    const toRaw = state[field.id + 'To'];

    if (field.kind === 'date') {
        const from = fromRaw ? dayBound(fromRaw, false) : null;
        const to = toRaw ? dayBound(toRaw, true) : null;
        if ((fromRaw && from === null) || (toRaw && to === null)) return { invalid: true };
        if (from === null && to === null) return { empty: true };
        if (from !== null && to !== null && from > to) return { invalid: true };
        return { value: (from === null ? '' : from) + '..' + (to === null ? '' : to) };
    }

    const from = numeric(fromRaw, field);
    const to = numeric(toRaw, field);
    if (from.invalid || to.invalid) return { invalid: true };
    if (from.empty && to.empty) return { empty: true };
    if (!from.empty && !to.empty && from.value > to.value) return { invalid: true };

    const unit = field.kind === 'size' ? (SIZE_UNITS.indexOf(state.sizeUnit) !== -1 ? state.sizeUnit : 'GB') : '';
    const side = (n) => (n.empty ? '' : n.text + unit);
    return { value: side(from) + '..' + side(to) };
}

/** `filter` entries for every usable panel field (+ Type when it is in the main row). */
export function advFilterParams(state) {
    const out = {};
    if (!advConfig() || !state) return out;

    const fields = panelFields().slice();
    const placement = typePlacement();
    if (placement === 'main' || placement === 'button') fields.push(TYPE_FIELD);

    fields.forEach((field) => {
        const r = fieldValue(field, state);
        if (r.value !== undefined && r.value !== '') out[field.key] = r.value;
    });
    return out;
}

/** How many panel fields currently filter the list (the toggle's badge). */
export function activePanelCount(state) {
    if (!advConfig() || !state) return 0;
    return panelFields().filter((f) => fieldValue(f, state).value !== undefined).length;
}

/** Clear the panel's fields (a Type control outside the panel keeps its value). */
export function clearPanel(state) {
    const fresh = emptyAdvState();
    const keepType = typePlacement() !== 'panel' ? state.mediaType : 'all';
    Object.keys(fresh).forEach((k) => {
        if (k !== 'sizeUnit') state[k] = fresh[k];
    });
    state.mediaType = keepType;
}

/**
 * The panel starts collapsed on every visit (expanded only when the admin chose so)
 * and keeps the visitor's choice while they browse the forum (in memory, not stored).
 */
export function isPanelOpen() {
    if (typeof app.homepageAdvOpen === 'boolean') return app.homepageAdvOpen;
    const cfg = advConfig();
    return !!(cfg && cfg.openDefault);
}

export function setPanelOpen(open) {
    app.homepageAdvOpen = !!open;
}
