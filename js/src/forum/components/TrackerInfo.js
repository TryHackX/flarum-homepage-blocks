import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';

export default class TrackerInfo extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.copied = false;
    }

    view() {
        const message = app.forum.attribute('tryhackx-homepage-blocks.tracker_message');
        const subMessage = app.forum.attribute('tryhackx-homepage-blocks.tracker_sub_message');
        const urlsText = app.forum.attribute('tryhackx-homepage-blocks.tracker_urls') || '';
        const urls = urlsText.split('\n').filter((u) => u.trim());

        if (!urls.length && !message) return null;

        return (
            <div className="TrackerInfo">
                {message && (
                    <div className="TrackerInfo-message">
                        <strong>{message}</strong>
                    </div>
                )}
                {subMessage && <div className="TrackerInfo-subMessage">{subMessage}</div>}
                {urls.length > 0 && (
                    <div className="TrackerInfo-urls">
                        {urls.map((url) => (
                            <div className="TrackerInfo-url">{url.trim()}</div>
                        ))}
                    </div>
                )}
                {urls.length > 0 && (
                    <Button className="Button TrackerInfo-copyBtn" onclick={() => this.copyUrls(urls)} icon="fas fa-copy">
                        {this.copied
                            ? app.translator.trans('tryhackx-homepage-blocks.forum.copied')
                            : app.translator.trans('tryhackx-homepage-blocks.forum.copy')}
                    </Button>
                )}
            </div>
        );
    }

    copyUrls(urls) {
        const text = urls.map((u) => u.trim()).join('\n');
        const copied = () => {
            this.copied = true;
            m.redraw();
            setTimeout(() => {
                this.copied = false;
                m.redraw();
            }, 2000);
        };

        // The Clipboard API exists only on secure (https) pages and may be refused —
        // fall back to a selected textarea + execCommand('copy') in both cases.
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(copied, () => {
                if (this.legacyCopy(text)) copied();
            });
        } else if (this.legacyCopy(text)) {
            copied();
        }
    }

    legacyCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {}
        document.body.removeChild(textarea);
        return ok;
    }
}
