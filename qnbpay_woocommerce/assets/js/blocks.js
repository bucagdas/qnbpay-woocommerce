/**
 * QNBpay — WooCommerce Cart/Checkout Blocks integration (compile-free).
 *
 * The hosted /purchase/link flow means there are NO card fields here: the block only
 * shows a short note and registers the method so it is selectable in the Checkout block.
 * On place-order the server's process_payment() creates the order and returns the QNB
 * hosted-page redirect (Store API honours payment_result.redirect_url), exactly like the
 * classic path. No card data is collected in the browser on our site.
 */
(function () {
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp) {
        return;
    }
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement = window.wp.element.createElement;
    var __ = window.wp.i18n.__;
    var decodeEntities = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };
    var settings = (window.wc.wcSettings && window.wc.wcSettings.getSetting)
        ? window.wc.wcSettings.getSetting('QNBPay_sanalpos_data', {})
        : {};

    var label = decodeEntities(settings.title || 'QNBPay');
    var note = decodeEntities(
        settings.description ||
        __("QNB'nin guvenli odeme sayfasinda odeyeceksiniz. Kart bilgileriniz bu sitede saklanmaz.", 'QNBPay')
    );

    var Content = function () {
        return createElement('div', { className: 'qnbpay-blocks-note' }, note);
    };

    registerPaymentMethod({
        name: 'QNBPay_sanalpos',
        label: label,
        ariaLabel: label,
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function () { return true; },
        supports: {
            features: (settings.supports && settings.supports.length) ? settings.supports : ['products']
        }
    });
})();
