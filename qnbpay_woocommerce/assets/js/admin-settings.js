/**
 * QNBPay admin settings helper for the gateway settings page.
 *
 * - "QNB test anahtarlarini ekle": fills QNB's PUBLICLY published sandbox test
 *   credentials (apidocs.qnbpay.com.tr) and turns on test mode. Not secrets.
 * - "Gercek anahtarlara don": restores the real credentials. It prefers the
 *   in-memory snapshot taken right before the test keys were loaded (undo before
 *   save); if the test keys were already saved, it restores from the server-side
 *   backup of the last real keys (window.qnbpayAdminData.liveBackup).
 *
 * The buttons are placed inline, to the right of the Merchant Key field.
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var idBase = 'woocommerce_QNBPay_sanalpos_';
        var mk = document.getElementById(idBase + 'merchant_key');
        if (!mk) {
            return;
        }

        var data = window.qnbpayAdminData || {};
        var liveBackup = data.liveBackup || null;

        var testKeys = {
            merchant_key: '$2y$10$N9IJkgazXMUwCzpn7NJrZePy3v.dIFOQUyW4yGfT3eWry6m.KxanK',
            app_key: '07fb70f9d8de575f32baa6518e38c5d6',
            app_secret: '61d97b2cac247069495be4b16f8604db',
            merchant_id: '20158'
        };

        var fieldIds = {
            merchant_key: idBase + 'merchant_key',
            app_key: idBase + 'app_key',
            app_secret: idBase + 'app_secret',
            merchant_id: idBase + 'merchant_id'
        };
        var envId = idBase + 'environment';

        var getVal = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
        var setVal = function (id, v) { var el = document.getElementById(id); if (el) { el.value = (v == null ? '' : v); } };

        var makeButton = function (text) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button';
            b.textContent = text;
            return b;
        };

        var snapshot = null; // real values captured right before test keys were loaded

        var fillBtn = makeButton('QNB test anahtarlarini ekle');
        var restoreBtn = makeButton('Gercek anahtarlara don');
        var msg = document.createElement('span');
        msg.style.marginLeft = '4px';
        msg.style.color = '#2271b1';

        var currentIsTest = function () { return getVal(fieldIds.merchant_key) === testKeys.merchant_key; };

        var refreshRestore = function () {
            var canRestore = !!snapshot || (liveBackup && liveBackup.merchant_key && currentIsTest());
            restoreBtn.style.display = canRestore ? '' : 'none';
        };

        fillBtn.addEventListener('click', function () {
            var env = document.getElementById(envId);
            snapshot = {
                merchant_key: getVal(fieldIds.merchant_key),
                app_key: getVal(fieldIds.app_key),
                app_secret: getVal(fieldIds.app_secret),
                merchant_id: getVal(fieldIds.merchant_id),
                environment: env ? !!env.checked : false
            };
            setVal(fieldIds.merchant_key, testKeys.merchant_key);
            setVal(fieldIds.app_key, testKeys.app_key);
            setVal(fieldIds.app_secret, testKeys.app_secret);
            setVal(fieldIds.merchant_id, testKeys.merchant_id);
            if (env) { env.checked = true; }
            msg.textContent = 'Test anahtarlari ve test modu dolduruldu. Kaydetmeyi unutmayin.';
            refreshRestore();
        });

        restoreBtn.addEventListener('click', function () {
            var fromSnapshot = !!snapshot;
            var src = snapshot || liveBackup;
            if (!src) { return; }
            setVal(fieldIds.merchant_key, src.merchant_key);
            setVal(fieldIds.app_key, src.app_key);
            setVal(fieldIds.app_secret, src.app_secret);
            setVal(fieldIds.merchant_id, src.merchant_id);
            var env = document.getElementById(envId);
            if (env) {
                env.checked = fromSnapshot ? !!src.environment : (src.environment === 'yes');
            }
            msg.textContent = fromSnapshot
                ? 'Onceki anahtarlar geri yuklendi. Kaydetmeyi unutmayin.'
                : 'Kayitli gercek anahtarlar geri yuklendi. Kaydetmeyi unutmayin.';
            if (fromSnapshot) { snapshot = null; }
            refreshRestore();
        });

        // Place the buttons inline, to the right of the Merchant Key input.
        var wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.alignItems = 'center';
        wrap.style.gap = '8px';
        wrap.style.flexWrap = 'wrap';
        mk.parentNode.insertBefore(wrap, mk);
        wrap.appendChild(mk);
        wrap.appendChild(fillBtn);
        wrap.appendChild(restoreBtn);
        wrap.appendChild(msg);

        refreshRestore();

        // Live theme preview under the "Odeme satiri temasi" selector. The markup
        // is rendered server-side from the real checkout renderers, so it matches
        // the storefront; only the title text is filled in here (kept live).
        var previews = data.previews || {};
        var themeSel = document.getElementById(idBase + 'checkout_theme');
        var titleInput = document.getElementById(idBase + 'title');
        if (themeSel && previews && Object.keys(previews).length) {
            var box = document.createElement('div');
            box.id = 'qnb-theme-preview';
            box.style.cssText = 'margin-top:12px;max-width:560px;border:1px dashed #c3c4c7;border-radius:8px;padding:14px;background:#fff;';
            var head = document.createElement('div');
            head.textContent = 'Onizleme';
            head.style.cssText = 'font-size:12px;color:#646970;margin-bottom:8px;';
            var body = document.createElement('div');
            body.className = 'qnb-preview-body';
            box.appendChild(head);
            box.appendChild(body);
            (themeSel.closest('td') || themeSel.parentNode).appendChild(box);

            var defTitle = data.defaultTitle || 'Banka/Kredi Karti ile Ode';
            var applyTitle = function () {
                var el = body.querySelector('.qnb-preview-title');
                if (el) { el.textContent = (titleInput && titleInput.value) ? titleInput.value : defTitle; }
            };
            var renderPreview = function () {
                body.innerHTML = previews[themeSel.value] || '';
                applyTitle();
            };
            themeSel.addEventListener('change', renderPreview);
            if (titleInput) { titleInput.addEventListener('input', applyTitle); }
            renderPreview();
        }
    });
})();
