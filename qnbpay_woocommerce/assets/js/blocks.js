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
        if (t === 'kurumsal' || t === 'gradient' || t === 'koyu' || t === 'ince') { return 22; }
        if (t === 'guvence' || t === 'kompakt') { return 20; }
        return 24;
    };

    // Card logos wrapped in white chips (readable on dark/gradient backgrounds).
    var chipRow = function (h) {
        return icons.map(function (u, i) {
            return el('span', { key: i, style: { background: '#fff', borderRadius: '6px', padding: '3px 5px', display: 'inline-flex', alignItems: 'center' } },
                el('img', { src: u, alt: '', style: { height: h + 'px', width: 'auto', verticalAlign: 'middle' } }));
        });
    };

    // Small padlock icon (inline SVG) used by some themes.
    var lockEl = function (color, size) {
        return el('svg', { width: size, height: size, viewBox: '0 0 24 24', fill: 'none', style: { verticalAlign: 'middle', flex: 'none' }, 'aria-hidden': true },
            el('rect', { x: 4, y: 10, width: 16, height: 10, rx: 2, fill: color }),
            el('path', { d: 'M8 10V7a4 4 0 0 1 8 0v3', stroke: color, strokeWidth: 2, fill: 'none' }),
            el('circle', { cx: 12, cy: 15, r: 1.6, fill: '#fff' })
        );
    };

    var secure = __('3D Secure ile güvenli ödeme', 'QNBPay');
    var guvenli = __('Güvenli ödeme', 'QNBPay');

    var Label = function () {
        return el('span', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
            el('span', null, title),
            el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, iconRow(iconHeight(theme)))
        );
    };

    var Content = function () {
        if (theme === 'vurgulu') {
            return el('div', { style: { borderLeft: '4px solid #6c2bd9', background: '#faf9ff', borderRadius: '8px', padding: '14px 16px' } },
                el('div', { style: { fontWeight: 600, marginBottom: '6px' } }, secure),
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
                    el('span', { style: { fontWeight: 600 } }, secure)
                ),
                el('div', { style: { color: '#667085', marginBottom: '12px' } }, note),
                el('div', null, iconRow(22))
            );
        }
        if (theme === 'kurumsal') {
            return el('div', { style: { border: '1px solid #d9dee6', borderLeft: '4px solid #1f3a5f', borderRadius: '4px', background: '#fff' } },
                el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '12px 16px' } },
                    lockEl('#1f3a5f', 16),
                    el('span', { style: { fontWeight: 600, color: '#1f3a5f' } }, __('QNB ile güvenli ödeme', 'QNBPay'))
                ),
                el('div', { style: { padding: '0 16px 12px', color: '#555' } }, note),
                el('div', { style: { borderTop: '1px solid #eef1f5', padding: '10px 16px', display: 'flex', justifyContent: 'flex-end' } }, iconRow(20))
            );
        }
        if (theme === 'gradient') {
            return el('div', { style: { background: 'linear-gradient(135deg,#7b2ff7 0%,#2b6cff 100%)', borderRadius: '14px', padding: '16px 18px', color: '#fff', boxShadow: '0 8px 24px rgba(43,108,255,0.22)' } },
                el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', marginBottom: '10px' } },
                    el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600 } }, lockEl('#ffffff', 16), guvenli),
                    el('span', { style: { background: 'rgba(255,255,255,0.2)', border: '1px solid rgba(255,255,255,0.35)', borderRadius: '999px', padding: '3px 10px', fontSize: '11px', fontWeight: 600 } }, '3D Secure')
                ),
                el('div', { style: { color: 'rgba(255,255,255,0.9)', marginBottom: '12px' } }, note),
                el('div', { style: { display: 'flex', gap: '6px', flexWrap: 'wrap' } }, chipRow(18))
            );
        }
        if (theme === 'koyu') {
            return el('div', { style: { background: '#12141c', border: '1px solid #262a36', borderRadius: '12px', padding: '14px 16px', color: '#e6e8ef' } },
                el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', marginBottom: '8px' } },
                    el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, color: '#f2f4f8' } }, lockEl('#34d399', 16), guvenli),
                    el('span', { style: { display: 'inline-flex', gap: '6px' } }, chipRow(18))
                ),
                el('div', { style: { color: '#aab1c0' } }, note)
            );
        }
        if (theme === 'ince') {
            return el('div', { style: { border: '1.5px solid #2b6cff', borderRadius: '14px', padding: '16px 18px', color: '#1d2327' } },
                el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', marginBottom: '6px' } },
                    el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600 } }, lockEl('#2b6cff', 15), guvenli),
                    el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, iconRow(22))
                ),
                el('div', { style: { color: '#6b7280' } }, note)
            );
        }
        if (theme === 'guvence') {
            var pill = { background: '#fff', border: '1px solid #cde9d6', borderRadius: '999px', padding: '3px 10px', fontSize: '11.5px', color: '#166534', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: '5px' };
            return el('div', { style: { background: '#f6fbf7', border: '1px solid #cde9d6', borderRadius: '10px', padding: '12px 14px', color: '#1d2327' } },
                el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px', marginBottom: '10px' } },
                    el('span', { style: pill }, lockEl('#16a34a', 12), '3D Secure'),
                    el('span', { style: pill }, 'SSL'),
                    el('span', { style: pill }, 'PCI DSS'),
                    el('span', { style: pill }, __('Kart bilgisi saklanmaz', 'QNBPay'))
                ),
                el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', flexWrap: 'wrap' } },
                    el('span', { style: { color: '#3f6b4c' } }, note),
                    el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, iconRow(20))
                )
            );
        }
        if (theme === 'kompakt') {
            return el('div', null,
                el('div', { style: { display: 'flex', alignItems: 'center', gap: '10px', background: '#eef3ff', border: '1px solid #d6e2ff', borderRadius: '999px', padding: '10px 16px', flexWrap: 'wrap' } },
                    lockEl('#2b6cff', 16),
                    el('span', { style: { fontWeight: 600, color: '#1d2327' } }, guvenli),
                    el('span', { style: { flex: 1 } }),
                    el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, iconRow(20))
                ),
                el('div', { style: { padding: '8px 4px 0', color: '#555' } }, note)
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
