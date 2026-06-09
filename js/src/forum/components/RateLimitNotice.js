import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';

/**
 * Treść alertu pokazywanego, gdy IP zostało tymczasowo zablokowane (zbyt wiele
 * akcji). Odlicza pozostały czas na żywo i po dojściu do zera pokazuje, że można
 * spróbować ponownie (a następnie sam się chowa przez przekazany onDone).
 *
 * Props:
 *   - seconds: number — początkowy czas blokady w sekundach
 *   - onDone:  ()=>void — wywoływane chwilę po zejściu licznika do zera
 */
export default class RateLimitNotice extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.remaining = Math.max(0, parseInt(this.attrs.seconds, 10) || 0);
        this.timer = setInterval(() => {
            this.remaining -= 1;
            if (this.remaining <= 0) {
                this.remaining = 0;
                clearInterval(this.timer);
                this.timer = null;
                if (typeof this.attrs.onDone === 'function') {
                    setTimeout(this.attrs.onDone, 1500);
                }
            }
            m.redraw();
        }, 1000);
    }

    onremove() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    view() {
        if (this.remaining > 0) {
            return app.translator.trans('tryhackx-homepage-blocks.forum.rate_limited', {
                seconds: this.remaining,
            });
        }
        return app.translator.trans('tryhackx-homepage-blocks.forum.rate_limited_ready');
    }
}
