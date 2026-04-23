import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import app from 'flarum/admin/app';

const WALLETS = [
  {
    name: 'Monero (XMR)',
    icon: 'fab fa-monero',
    address: '45hvee4Jv7qeAm6SrBzXb9YVjb8DkHtFtFh7qkDMxS9zYX3NRi1dV27MtSdVC5X8T1YVoiG8XFiJkh4p9UncqWGxHi4tiwk',
    color: '#ff6600',
  },
  {
    name: 'Bitcoin (BTC)',
    icon: 'fab fa-bitcoin',
    address: 'bc1qncavcek4kknpvykedxas8kxash9kdng990qed2',
    color: '#f7931a',
  },
  {
    name: 'Ethereum (ETH)',
    icon: 'fab fa-ethereum',
    address: '0xa3d38d5Cf202598dd782C611e9F43f342C967cF5',
    color: '#627eea',
  },
];

export default class SupportModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.copiedIndex = null;
  }

  className() {
    return 'SupportModal Modal--small';
  }

  title() {
    return app.translator.trans('tryhackx-homepage-blocks.admin.support.title');
  }

  content() {
    return m('div', { className: 'Modal-body' }, [
      m('p', { className: 'SupportModal-description' },
        app.translator.trans('tryhackx-homepage-blocks.admin.support.description')
      ),
      m('div', { className: 'SupportModal-wallets' },
        WALLETS.map((wallet, index) =>
          m('div', { className: 'SupportModal-wallet' }, [
            m('div', { className: 'SupportModal-walletHeader' }, [
              m('i', { className: wallet.icon, style: { color: wallet.color } }),
              m('span', wallet.name),
            ]),
            m('div', { className: 'SupportModal-walletAddress' }, [
              m('code', wallet.address),
              m(Button, {
                className: 'Button Button--icon SupportModal-copyBtn' + (this.copiedIndex === index ? ' SupportModal-copyBtn--copied' : ''),
                icon: this.copiedIndex === index ? 'fas fa-check' : 'fas fa-copy',
                title: app.translator.trans('tryhackx-homepage-blocks.admin.support.copy'),
                onclick: () => this.copyAddress(wallet.address, index),
              }),
            ]),
          ])
        )
      ),
      m('p', { className: 'SupportModal-thanks' },
        app.translator.trans('tryhackx-homepage-blocks.admin.support.thanks')
      ),
    ]);
  }

  copyAddress(address, index) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(address).then(() => {
        this.copiedIndex = index;
        m.redraw();
        setTimeout(() => {
          this.copiedIndex = null;
          m.redraw();
        }, 2000);
      });
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = address;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      try {
        document.execCommand('copy');
        this.copiedIndex = index;
        m.redraw();
        setTimeout(() => {
          this.copiedIndex = null;
          m.redraw();
        }, 2000);
      } catch (e) {}
      document.body.removeChild(textarea);
    }
  }
}
