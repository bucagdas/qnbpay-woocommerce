/**
 * QNBPay admin settings helper: a "fill QNB test keys" button on the gateway
 * settings page. The values are QNB's PUBLICLY published sandbox test credentials
 * (apidocs.qnbpay.com.tr), used only to speed up test-mode setup. Not secrets.
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var mk = document.getElementById('woocommerce_QNBPay_sanalpos_merchant_key');
        if (!mk) {
            return;
        }
        var keys = {
            merchant_key: '$2y$10$N9IJkgazXMUwCzpn7NJrZePy3v.dIFOQUyW4yGfT3eWry6m.KxanK',
            app_key: '07fb70f9d8de575f32baa6518e38c5d6',
            app_secret: '61d97b2cac247069495be4b16f8604db',
            merchant_id: '20158'
        };
        var setVal = function (id, v) {
            var el = document.getElementById(id);
            if (el) { el.value = v; }
        };
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button';
        btn.textContent = 'QNB test anahtarlarini ekle';
        var msg = document.createElement('span');
        msg.style.marginLeft = '8px';
        msg.style.color = '#2271b1';
        btn.addEventListener('click', function () {
            setVal('woocommerce_QNBPay_sanalpos_merchant_key', keys.merchant_key);
            setVal('woocommerce_QNBPay_sanalpos_app_key', keys.app_key);
            setVal('woocommerce_QNBPay_sanalpos_app_secret', keys.app_secret);
            setVal('woocommerce_QNBPay_sanalpos_merchant_id', keys.merchant_id);
            var env = document.getElementById('woocommerce_QNBPay_sanalpos_environment');
            if (env) { env.checked = true; }
            msg.textContent = 'Test anahtarlari ve test modu dolduruldu. Degisiklikleri kaydedin.';
        });
        var cell = mk.closest('td') || mk.parentNode;
        cell.appendChild(document.createElement('br'));
        cell.appendChild(btn);
        cell.appendChild(msg);
    });
})();
