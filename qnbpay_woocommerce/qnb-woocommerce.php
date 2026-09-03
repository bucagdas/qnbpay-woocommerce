<?php

if (!defined('ABSPATH')) {
    exit;
}

class QNBPay_sanalpos extends WC_Payment_Gateway
{
    // QNB's publicly published sandbox merchant key (apidocs.qnbpay.com.tr).
    // Used only to detect when the fields hold the test keys, so the last real
    // credentials can be backed up and restored.
    const QNB_TEST_MERCHANT_KEY = '$2y$10$N9IJkgazXMUwCzpn7NJrZePy3v.dIFOQUyW4yGfT3eWry6m.KxanK';

    protected $is_3d = 0;
    public $headers = array(

        'Accept: application/json',

        'Content-Type: application/json'

    );

    function __construct()
    {
        // global ID
        $this->id = "QNBPay_sanalpos";
        // Show Title
        $this->method_title = __("QNBPay Sanalpos", 'qnb');
        // Show Description
        $this->method_description = __("Woocommerce için QNBPay Entegrasyonu", 'qnb');
        // vertical tab title
        $this->title = __("Banka/Kredi Karti ile Ode", 'qnb');
        $this->icon = null;
        $this->has_fields = true;
        // Enable the WooCommerce refund button for this gateway (QNB /api/refund).
        $this->supports = array('products', 'refunds');
        // support default form with credit card
        // setting defines
        $this->init_form_fields();
        // load time variable setting
        $this->init_settings();


        // Turn these settings into variables we can use
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        // further check of SSL if you want
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        // Save settings
        if (is_admin()) {
            $this->activate();
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }
    } // Here is the  End __construct()

    /**
     * Save settings, then keep a backup of the last real (non-test) credentials
     * so the admin can restore them after loading the sandbox test keys.
     */
    public function process_admin_options()
    {
        $result = parent::process_admin_options();
        $this->init_settings();
        $mk = $this->get_option('merchant_key');
        if ($mk !== '' && $mk !== self::QNB_TEST_MERCHANT_KEY) {
            update_option('qnbpay_live_keys_backup', array(
                'merchant_key' => $this->get_option('merchant_key'),
                'app_key'      => $this->get_option('app_key'),
                'app_secret'   => $this->get_option('app_secret'),
                'merchant_id'  => $this->get_option('merchant_id'),
                'environment'  => $this->get_option('environment'),
            ), false);
        }
        return $result;
    }

    // administration fields for specific Gateway

    function activate()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'postmeta'; // Tablo adını veritabanı önekini ekleyerek oluşturun

        $wpdb->query("DELETE FROM $table_name WHERE meta_key='qnb_data_payment'");
        $wpdb->query("DELETE FROM $table_name WHERE meta_key='qnb_payment_form'");

        $table_name = $wpdb->prefix . 'qnb_cards';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                card_id int(11) NOT NULL AUTO_INCREMENT,
                customer_id INT(11) NOT NULL,
                card_token varchar(255) NOT NULL,
                card_mask varchar(255) NOT NULL,
                created_at  timestamp DEFAULT current_timestamp,
              PRIMARY KEY (card_id)
            ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        try {
            dbDelta($sql);
        } catch (\Exception $e) {
            qnbpay_log('activate: ' . $e->getMessage(), 'error');
        }
    }

    public function init_form_fields()
    {

        $installments = array(1 => __('Tek Çekim (Peşin)', 'qnb'));
        for ($i = 2; $i <= 12; $i++) {
            $installments[$i] = sprintf(__('%d Taksit', 'qnb'), $i);
        }
        $this->form_fields = [

            'section_api' => array(
                'title'       => __('QNB API bilgileri', 'qnb'),
                'type'        => 'title',
                'description' => __('QNBpay üye işyeri panelinden aldığınız anahtarlar. Test için Merchant Key alanının yanındaki butonu kullanabilirsiniz. Bu yöntemi açıp kapatmak için Ödemeler listesindeki aç/kapa düğmesini kullanın.', 'qnb'),
            ),
            'merchant_key' => [
                'title'    => __('Merchant Key', 'qnb'),
                'type'     => 'text',
                'desc_tip' => __('QNBpay panelindeki Merchant Key.', 'qnb'),
            ],
            'app_key' => [
                'title'    => __('App Key', 'qnb'),
                'type'     => 'text',
                'desc_tip' => __('API App Key.', 'qnb'),
            ],
            'app_secret' => [
                'title'    => __('App Secret', 'qnb'),
                'type'     => 'text',
                'desc_tip' => __('API App Secret.', 'qnb'),
            ],
            'merchant_id' => [
                'title'    => __('Merchant ID', 'qnb'),
                'type'     => 'text',
                'desc_tip' => __('Üye işyeri numarası (Merchant ID).', 'qnb'),
            ],
            'sale_webhook_key' => [
                'title'       => __('Satış Webhook Anahtarı', 'qnb'),
                'type'        => 'text',
                'desc_tip'    => __('QNB panelinde "Satış Webhook" adresi olarak aşağıdaki URL\'yi tanımlayın.', 'qnb'),
                'description' => get_site_url() . '?webhook=1',
            ],
            /*'recurring_sale_webhook_key' => [
                    'title' => __('Yinelenen Satış Webhook Anahtarı', 'qnb'),
                    'type' => 'text',
                    'desc_tip' => __('Yinelenen Satış Webhook Anahtarı', 'qnb'),
                    'description' => get_site_url() . '?webhook=1&recurring=1',
                ],*/

            'section_payment' => array(
                'title' => __('Ödeme ayarları', 'qnb'),
                'type'  => 'title',
            ),
            'environment' => [
                'title'       => __('Test Modu', 'qnb'),
                'label'       => __('Etkinleştir', 'qnb'),
                'type'        => 'checkbox',
                'description' => __('Test ortamında deneme yapmak için etkinleştirin. Canlıya geçerken kapatın.', 'qnb'),
                'default'     => 'no',
            ],
            'transaction_type' => array(
                'title'   => __('Provizyon Türü', 'qnb'),
                'type'    => 'select',
                'default' => 'Auth',
                'options' => array('Auth' => __('Auth (anında çekim)', 'qnb'), 'PreAuth' => __('PreAuth (ön provizyon)', 'qnb'))
            ),
            'installment_type' => array(
                'title'   => __('Vade Farkını Kart Sahibi Ödesin', 'qnb'),
                'label'   => __('Etkinleştir', 'qnb'),
                'type'    => 'checkbox',
                'default' => 'yes'
            ),
            'installments' => array(
                'title'             => __('Taksit Sayısı', 'qnb'),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'options'           => $installments,
                'default'           => array(),
                'desc_tip'          => false,
                'description'       => __('Ödeme sayfasında sunulacak taksit sayıları. Boş bırakırsanız QNB POS tanımınızdaki tüm taksitler gösterilir; seçim yaparsanız yalnızca seçtikleriniz sunulur.', 'qnb'),
                'custom_attributes' => array(
                    'data-placeholder' => __('Tümü (POS varsayılanı)', 'qnb'),
                ),
            ),

            'section_display' => array(
                'title'       => __('Görünüm', 'qnb'),
                'type'        => 'title',
                'description' => __('Ödeme adımında müşterinin gördüğü başlık ve satır görünümü.', 'qnb'),
            ),
            'checkout_theme' => array(
                'title'       => __('Ödeme satırı teması', 'qnb'),
                'type'        => 'select',
                'default'     => 'kartli',
                'options'     => array(
                    'sade'     => __('Sade', 'qnb'),
                    'kartli'   => __('Kartlı (kart logoları)', 'qnb'),
                    'vurgulu'  => __('Vurgulu (güvenli ödeme paneli)', 'qnb'),
                    'modern'   => __('Modern (gölgeli kart)', 'qnb'),
                    'kurumsal' => __('Kurumsal (banka görünümü)', 'qnb'),
                ),
                'description' => __('Ödeme yönteminin checkout görünümü.', 'qnb'),
            ),
            'title' => [
                'title'    => __('Başlık', 'qnb'),
                'type'     => 'text',
                'desc_tip' => __('Ödeme adımında görünen başlık.', 'qnb'),
                'default'  => __('Banka/Kredi Karti ile Ode', 'qnb'),
            ],
            'description' => [
                'title'    => __('Açıklama', 'qnb'),
                'type'     => 'textarea',
                'desc_tip' => __('Başlığın altında görünen kısa açıklama.', 'qnb'),
                'default'  => __('Kredi veya banka kartinizla QNB guvenli odeme sayfasinda odeyin.', 'qnb'),
                'css'      => 'max-width:450px;',
            ],


        ];
    }

    public function getLocalizationContent($content, $language)
    {
        $language = strtoupper($language);

        $lang = [
            get_option('woocommerce_currency') => [
                'card_holder_name' => 'Kart Sahibi',

                'card_number' => 'Kart Numarası',

                'expiry' => 'Son Kullanma Tarihi',

                'cvv' => 'Güvenlik Numarası',

                'single_installment' => 'Peşin',

                'installment' => 'Taksit',

                'new_card' => 'Yeni Kart',

                'saved_card' => 'Kayıtlı Kart',

                'save_card' => 'Bu Kartı Kaydet',

                '3D_payment' => '3D Ödeme',

                'your_registered_cards' => 'Kayıtlı Kartlarınız',

                'choose_card' => 'Kart Seçiniz',

                'no_registered_card' => 'Kayıtlı Kartınız Yok',

                'delete_saved_card' => 'Sil',
            ],

            'USD' => [
                'card_holder_name' => 'Card Holder Name',

                'card_number' => 'Card Number',

                'expiry' => 'Expiry',

                'cvv' => 'CVV',

                'single_installment' => 'Single Installment',

                'installment' => 'Installment',

                'new_card' => 'New Card',

                'saved_card' => 'Saved Card',

                'save_card' => 'Save Card',

                '3D_payment' => '3D Payment',

                'your_registered_cards' => 'Your Registered Cards',

                'choose_card' => 'Choose Card',

                'no_registered_card' => 'You Dont Have a Registered Card',

                'delete_saved_card' => 'Delete',
            ],

            'EUR' => [
                'card_holder_name' => 'Card Holder Name',

                'card_number' => 'Card Number',

                'expiry' => 'Expiry',

                'cvv' => 'CVV',

                'single_installment' => 'Single Installment',

                'installment' => 'Installment',

                'new_card' => 'New Card',

                'saved_card' => 'Saved Card',

                'save_card' => 'Save Card',

                '3D_payment' => '3D Payment',

                'your_registered_cards' => 'Your Registered Cards',

                'choose_card' => 'Choose Card',

                'no_registered_card' => 'You Dont Have a Registered Card',

                'delete_saved_card' => 'Delete',
            ],
        ];

        if (!isset($lang[$language])) {
            $language = 'USD';
        }

        if (isset($lang[$language][$content])) {
            $localizeContent = $lang[$language][$content];
        } else {
            $localizeContent = $content;
        }

        return $localizeContent;
    }

    /** Selected checkout row theme (sade|kartli|vurgulu). */
    /** The selectable checkout row themes (single source of truth). */
    public static function qnbpay_theme_keys()
    {
        return array('sade', 'kartli', 'vurgulu', 'modern', 'kurumsal');
    }

    public function qnbpay_theme()
    {
        $t = $this->get_option('checkout_theme', 'kartli');
        return in_array($t, self::qnbpay_theme_keys(), true) ? $t : 'kartli';
    }

    /** Icon pixel height per theme. */
    public static function qnbpay_theme_icon_height($theme)
    {
        $map = array('sade' => 20, 'kartli' => 26, 'vurgulu' => 28, 'modern' => 24, 'kurumsal' => 22);
        return isset($map[$theme]) ? $map[$theme] : 24;
    }

    /** Small padlock icon (inline SVG) used by some themes. */
    public static function qnbpay_lock_svg($color = '#2f7d32', $size = 16)
    {
        $size = (int) $size;
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;flex:none;" aria-hidden="true">'
            . '<rect x="4" y="10" width="16" height="10" rx="2" fill="' . esc_attr($color) . '"/>'
            . '<path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="' . esc_attr($color) . '" stroke-width="2" fill="none"/>'
            . '<circle cx="12" cy="15" r="1.6" fill="#fff"/></svg>';
    }

    /** Accepted card brand <img> tags at the given pixel height. */
    public function card_icons_html($height)
    {
        $base  = plugins_url('assets/images/cards/', __FILE__);
        $cards = array('mastercard', 'visa', 'amex', 'troy');
        $out = '';
        foreach ($cards as $c) {
            $out .= '<img src="' . esc_url($base . $c . '.svg') . '" alt="' . esc_attr($c) . '" style="height:' . (int) $height . 'px;width:auto;margin-left:4px;vertical-align:middle;display:inline-block;" />';
        }
        return $out;
    }

    public function get_icon()
    {
        $theme = $this->qnbpay_theme();
        $h = self::qnbpay_theme_icon_height($theme);
        $html = '<span class="qnbpay-card-icons" style="float:right;display:inline-flex;align-items:center;">' . $this->card_icons_html($h) . '</span>';
        return apply_filters('woocommerce_gateway_icon', $html, $this->id);
    }

    /** Themed "you'll pay on QNB's secure page" note for the classic checkout. */
    public function render_hosted_note($theme)
    {
        $note = esc_html__('Kartinizla QNB\'nin guvenli odeme sayfasinda odeyeceksiniz. Kart bilgileriniz bu sitede saklanmaz.', 'QNBPay');
        $secure = esc_html__('3D Secure ile guvenli odeme', 'QNBPay');

        if ($theme === 'sade') {
            return '<div class="qnbpay-hosted-note" style="padding:8px 0;color:#555;">' . $note . '</div>';
        }
        if ($theme === 'vurgulu') {
            return '<div class="qnbpay-hosted-note" style="border-left:4px solid #6c2bd9;background:#faf9ff;border-radius:8px;padding:14px 16px;color:#333;">'
                . '<div style="font-weight:600;margin-bottom:6px;">' . $secure . '</div>'
                . '<div style="margin-bottom:10px;color:#555;">' . $note . '</div>'
                . '<div>' . $this->card_icons_html(26) . '</div>'
                . '</div>';
        }
        if ($theme === 'modern') {
            return '<div class="qnbpay-hosted-note" style="background:#fff;border:1px solid #eceef1;border-radius:12px;padding:16px 18px;color:#333;box-shadow:0 2px 10px rgba(17,24,39,0.06);">'
                . '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">'
                . self::qnbpay_lock_svg('#12b76a', 18)
                . '<span style="font-weight:600;">' . $secure . '</span></div>'
                . '<div style="color:#667085;margin-bottom:12px;">' . $note . '</div>'
                . '<div>' . $this->card_icons_html(22) . '</div>'
                . '</div>';
        }
        if ($theme === 'kurumsal') {
            return '<div class="qnbpay-hosted-note" style="border:1px solid #d9dee6;border-left:4px solid #1f3a5f;border-radius:4px;background:#fff;color:#333;">'
                . '<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;">'
                . self::qnbpay_lock_svg('#1f3a5f', 16)
                . '<span style="font-weight:600;color:#1f3a5f;">' . esc_html__('QNB ile guvenli odeme', 'QNBPay') . '</span></div>'
                . '<div style="padding:0 16px 12px;color:#555;">' . $note . '</div>'
                . '<div style="border-top:1px solid #eef1f5;padding:10px 16px;display:flex;justify-content:flex-end;">' . $this->card_icons_html(20) . '</div>'
                . '</div>';
        }
        // kartli (default)
        return '<div class="qnbpay-hosted-note" style="background:#f7f8fa;border:1px solid #e6e8eb;border-radius:8px;padding:12px 14px;color:#333;">'
            . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
            . '<span style="color:#555;">' . $note . '</span>'
            . '<span style="display:inline-flex;align-items:center;">' . $this->card_icons_html(24) . '</span>'
            . '</div></div>';
    }

    public function payment_fields()
    {
        // No card fields here: the buyer enters the card on QNB's hosted page.
        if ($description = $this->get_description()) {
            echo wpautop(wptexturize($description));
        }
        echo $this->render_hosted_note($this->qnbpay_theme());
        return;
    }

    public function payment_scripts()
    {


        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {

            return;
        }
        if ('no' === $this->enabled) {

            return;
        }




        wp_enqueue_style('woocommerce_qnb_style', plugins_url('css/qnb.css', __FILE__));
    }

    public function process_payment($order_id)
    {
        // Hosted /purchase/link: create the order, get the redirect link, send the
        // buyer to QNB's page. No card data reaches our server. Reads only from $order.
        $order = wc_get_order($order_id);
        $invoice_id = md5(microtime()) . 'WOO' . $order_id;
        $order->update_meta_data('_qnbpay_invoice_id', $invoice_id);
        $order->save();

        $api  = new QNBPay_Api();
        $resp = $api->purchase_link(
            $order,
            $invoice_id,
            $order->get_checkout_order_received_url(),
            wc_get_checkout_url()
        );

        if (is_object($resp) && isset($resp->status_code) && (string) $resp->status_code === '100' && !empty($resp->link)) {
            if (isset($resp->order_id)) {
                $order->update_meta_data('_qnbpay_order_ref', sanitize_text_field((string) $resp->order_id));
                $order->save();
            }
            $order->add_order_note(__('QNBpay guvenli odeme sayfasina yonlendirildi (hosted).', 'QNBPay'));
            return array('result' => 'success', 'redirect' => $resp->link);
        }

        $desc = (is_object($resp) && isset($resp->status_description)) ? (string) $resp->status_description : $api->last_error;
        qnbpay_log('purchase/link failed for order ' . $order_id . ' code=' . (is_object($resp) && isset($resp->status_code) ? $resp->status_code : 'n/a') . ' reason=' . $desc, 'error');
        wc_add_notice(__('QNBpay odemesi baslatilamadi. Lutfen tekrar deneyin.', 'QNBPay') . ($desc !== '' ? ' (' . esc_html($desc) . ')' : ''), 'error');
        return array('result' => 'failure');
    }

    /**
     * WooCommerce refund button -> QNB /api/refund. $amount is the amount to refund
     * (partial or full, as WooCommerce computes it). Returns true on accept, or a
     * WP_Error whose message WooCommerce shows to the admin.
     *
     * NOTE: QNB's sandbox does not fully exercise refunds; verify against a real
     * transaction before relying on this in production. Status 101 means QNB accepted
     * the request and a human completes it, so confirm settlement in the QNB panel.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('qnbpay_refund', __('Sipariş bulunamadı.', 'qnb'));
        }
        $invoice_id = $order->get_meta('_qnbpay_invoice_id');
        if (!$invoice_id) {
            return new WP_Error('qnbpay_refund', __('QNBpay fatura referansı bulunamadı; bu sipariş QNBpay ile alınmamış olabilir.', 'qnb'));
        }

        $api  = new QNBPay_Api();
        $resp = $api->refund($invoice_id, $amount);
        if (!is_object($resp)) {
            $err = ($api->last_error !== '') ? $api->last_error : __('QNB iade servisine ulaşılamadı.', 'qnb');
            qnbpay_log('refund transport error for order ' . $order_id . ': ' . $err, 'error');
            return new WP_Error('qnbpay_refund', $err);
        }

        $code    = isset($resp->status_code) ? (string) $resp->status_code : '';
        $desc    = isset($resp->status_description) ? (string) $resp->status_description : '';
        $amount_str = ($amount === null || $amount === '') ? __('tam tutar', 'qnb') : html_entity_decode(wp_strip_all_tags(wc_price($amount, array('currency' => $order->get_currency()))));

        if ($code === '100') {
            $order->add_order_note(sprintf(__('QNBpay: iade tamamlandı (%1$s). Kod 100. %2$s', 'qnb'), $amount_str, $desc));
            return true;
        }
        if ($code === '101') {
            $order->add_order_note(sprintf(__('QNBpay: iade talebi alındı, QNB tarafında tamamlanacak (%1$s). Kod 101: QNB panelinden veya checkstatus ile doğrulayın.', 'qnb'), $amount_str));
            return true;
        }

        qnbpay_log('refund rejected for order ' . $order_id . ' code=' . $code . ' reason=' . $desc, 'error');
        $extra = ($code === '49') ? ' ' . __('(Aynı işlemde iki iade arasında en az 30 saniye bekleyin.)', 'qnb') : '';
        return new WP_Error('qnbpay_refund', sprintf(__('QNBpay iade reddedildi. Kod %1$s. %2$s', 'qnb'), $code, $desc) . $extra);
    }

}
