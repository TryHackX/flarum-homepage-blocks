import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';

export default class CustomLinks extends Component {
    view() {
        const linksJson = app.forum.attribute('tryhackx-homepage-blocks.custom_links');
        let links = [];
        try {
            links = JSON.parse(linksJson || '[]');
        } catch (e) {
            links = [];
        }

        if (!links.length) return null;

        return (
            <div className="CustomLinks">
                {links.map((link) => (
                    <a
                        href={link.url}
                        className="CustomLinks-link"
                        style={{ color: link.color || '#e74c3c' }}
                        target={link.external ? '_blank' : undefined}
                        rel={link.external ? 'noopener noreferrer' : undefined}
                    >
                        <strong>{link.label}</strong>
                    </a>
                ))}
            </div>
        );
    }
}
