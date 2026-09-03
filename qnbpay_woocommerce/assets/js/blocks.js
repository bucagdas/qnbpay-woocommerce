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

    var Label = function () {
        return el('span', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
            el('span', null, title),
            el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, iconRow(theme === 'sade' ? 20 : (theme === 'vurgulu' ? 28 : 24)))
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
