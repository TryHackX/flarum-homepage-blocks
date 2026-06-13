import app from 'flarum/forum/app';

/**
 * app.translator.trans() może zwrócić tablicę (gdy tłumaczenie zawiera zagnieżdżone
 * węzły/komponenty), a w wielu miejscach UI potrzebujemy czystego stringa
 * (placeholdery, etykiety option, title/aria-label). Sklejamy części tekstowe,
 * pomijając węzły niebędące stringami.
 *
 * Wspólne dla TrackerStats / AdvancedFilters / CaptchaModal (audyt #8 — wcześniej
 * identyczna metoda była skopiowana do każdego z tych komponentów).
 */
export function transStr(key) {
    const result = app.translator.trans(key);
    if (Array.isArray(result)) {
        return result.map((item) => (typeof item === 'string' ? item : '')).join('');
    }
    return String(result || '');
}
