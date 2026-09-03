/**
 * QNBpay Cart/Checkout Blocks integration (compile-free).
 * Hosted flow: no card fields. The label shows the title and accepted-card icons;
 * the content shows a themed "you'll pay on QNB's secure page" note. Theme, icons
 * and text come from the gateway settings (QNBPay_sanalpos_data).
 */
(function () {
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp) {
        return;
    }
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var el = window.wp.element.createElement;
    var __ = window.wp.i18n.__;
    var decode = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };
    var s = (window.wc.wcSettings && window.wc.wcSettings.getSetting)
        ? window.wc.wcSettings.getSetting('QNBPay_sanalpos_data', {})
        : {};

    var title = decode(s.title || 'QNBPay');
    var note = decode(s.note || '');
    var theme = s.theme || 'kartli';
    var icons = Array.isArray(s.icons) ? s.icons : [];

    var iconRow = function (h) {
        return icons.map(function (u, i) {
            return el('img', { key: i, src: u, alt: '', style: { height: h + 'px', width: 'auto', marginLeft: '4px', verticalAlign: 'middle' } });
        });
    };

    var iconHeight = function (t) {
        if (t === 'sade') { return 20; }
        if (t === 'vurgulu') { return 28; }
        if (t === 'kurumsal') { return 22; }
        return 24;
    };

    // Small padlock icon (inline SVG) used by some themes.
    var lockEl = function (color, size) {
        return el('svg', { width: size, height: size, viewBox: '0 0 24 24', fill: 'none', style: { verticalAlign: 'middle', flex: 'none' }, 'aria-hidden': true },
            el('rect', { x: 4, y: 10, width: 16, height: 10, rx: 2, fill: color }),
            el('path', { d: 'M8 10V7a4 4 0 0 1 8 0v3', stroke: color, strokeWidth: 2, fill: 'none' }),
            el('circle', { cx: 12, cy: 15, r: 1.6, fill: '#fff' })
        );
    };

    var Label = function () {
        return el('span', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
            el('span', null, title),
            el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, iconRow(iconHeight(theme)))
        );
    };

    var Content = function () {
        if (theme === 'vurgulu') {
            return el('div', { style: { borderLeft: '4px solid #6c2bd9', background: '#faf9ff', borderRadius: '8px', padding: '14px 16px' } },
                el('div', { style: { fontWeight: 600, marginBottom: '6px' } }, __('3D Secure ile guvenli odeme', 'QNBPay')),
                el('div', { style: { marginBottom: '10px', color: '#555' } }, note),
                el('div', null, iconRow(26))
            );
        }
        if (theme === 'sade') {
            return el('div', { style: { padding: '8px 0', color: '#555' } }, note);
        }
        if (theme === 'modern') {
            return el('div', { style: { background: '#fff', border: '1px solid #eceef1', borderRadius: '12px', padding: '16px 18px', boxShadow: '0 2px 10px rgba(17,24,39,0.06)' } },
                el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' } },
                    lockEl('#12b76a', 18),
                    el('span', { style: { fontWeight: 600 } }, __('3D Secure ile guvenli odeme', 'QNBPay'))
                ),
                el('div', { style: { color: '#667085', marginBottom: '12px' } }, note),
                el('div', null, iconRow(22))
            );
        }
        if (theme === 'kurumsal') {
            return el('div', { style: { border: '1px solid #d9dee6', borderLeft: '4px solid #1f3a5f', borderRadius: '4px', background: '#fff' } },
                el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '12px 16px' } },
                    lockEl('#1f3a5f', 16),
                    el('span', { style: { fontWeight: 600, color: '#1f3a5f' } }, __('QNB ile guvenli odeme', 'QNBPay'))
                ),
                el('div', { style: { padding: '0 16px 12px', color: '#555' } }, note),
                el('div', { style: { borderTop: '1px solid #eef1f5', padding: '10px 16px', display: 'flex', justifyContent: 'flex-end' } }, iconRow(20))
            );
        }
        return el('div', { style: { background: '#f7f8fa', border: '1px solid #e6e8eb', borderRadius: '8px', padding: '12px 14px', color: '#555' } }, note);
    };

    registerPaymentMethod({
        name: 'QNBPay_sanalpos',
        label: el(Label, null),
        ariaLabel: title,
        content: el(Content, null),
        edit: el(Content, null),
        canMakePayment: function () { return true; },
        supports: { features: (s.supports && s.supports.length) ? s.supports : ['products'] }
    });
})();
