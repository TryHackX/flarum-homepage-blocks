import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';

/**
 * Bezpieczny href albo null. Dopuszcza http(s), mailto oraz ścieżki względne /
 * kotwice; odrzuca niebezpieczne schematy (javascript:, data:, vbscript: …),
 * żeby link z ustawień admina nie stał się wektorem XSS dla odwiedzających.
 */
function safeHref(url) {
    if (typeof url !== 'string') return null;
    const u = url.trim();
    if (!u) return null;
    const m = u.match(/^([a-z][a-z0-9+.-]*):/i);
    if (m) {
        const scheme = m[1].toLowerCase();
        if (scheme !== 'http' && scheme !== 'https' && scheme !== 'mailto') return null;
    }
    return u;
}

/**
 * Sanityzuje nazwę(y) klasy CSS przypiętej do linku — tylko litery, cyfry,
 * `-`, `_` i spacje (oddzielanie wielu klas). Odrzuca cudzysłowy / nawiasy itp.,
 * żeby wartość z ustawień nie mogła wyłamać się z atrybutu class.
 */
function safeClass(c) {
    if (typeof c !== 'string') return '';
    return c.replace(/[^A-Za-z0-9_\- ]/g, '').replace(/\s+/g, ' ').trim().slice(0, 200);
}

export default class CustomLinks extends Component {
    view() {
        const linksJson = app.forum.attribute('tryhackx-homepage-blocks.custom_links');
        let links = [];
        try {
            links = JSON.parse(linksJson || '[]');
        } catch (e) {
            links = [];
        }

        // Pomijaj puste wiersze i niebezpieczne URL-e (np. javascript:).
        links = links
            .map((l) => (l ? {
                label: l.label,
                color: l.color,
                external: !!l.external,
                href: safeHref(l.url),
                className: safeClass(l.className),
            } : null))
            .filter((l) => l && l.href && l.label);
        if (!links.length) return null;

        const title = app.forum.attribute('tryhackx-homepage-blocks.custom_links_title');

        return (
            <div className="CustomLinks">
                {title ? (
                    <div className="CustomLinks-title">
                        <strong>{title}</strong>
                    </div>
                ) : null}
                <div className="CustomLinks-list">
                    {links.map((link) => (
                        <a
                            href={link.href}
                            className={'CustomLinks-link' + (link.className ? ' ' + link.className : '')}
                            style={link.className ? undefined : { color: link.color || '#e74c3c' }}
                            target={link.external ? '_blank' : undefined}
                            rel={link.external ? 'noopener noreferrer' : undefined}
                        >
                            <strong>{link.label}</strong>
                        </a>
                    ))}
                </div>
            </div>
        );
    }
}
