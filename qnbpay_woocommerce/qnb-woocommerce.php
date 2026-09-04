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
        $this->method_title = __("QNBPay Sanalpos", 'QNBPay');
        // Show Description
        $this->method_description = __("Woocommerce için QNBPay Entegrasyonu", 'QNBPay');
        // vertical tab title
        $this->title = __("Banka/Kredi Kartı ile Öde", 'QNBPay');
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
        $this->form_fields = [

            'section_api' => array(
                'title'       => __('QNB API bilgileri', 'QNBPay'),
                'type'        => 'title',
                'description' => __('QNBpay üye işyeri panelinden aldığınız anahtarlar. Test için Merchant Key alanının yanındaki butonu kullanabilirsiniz. Bu yöntemi açıp kapatmak için Ödemeler listesindeki aç/kapa düğmesini kullanın.', 'QNBPay'),
            ),
            'merchant_key' => [
                'title'    => __('Merchant Key', 'QNBPay'),
                'type'     => 'text',
                'desc_tip' => __('QNBpay panelindeki Merchant Key.', 'QNBPay'),
            ],
            'app_key' => [
                'title'    => __('App Key', 'QNBPay'),
                'type'     => 'text',
                'desc_tip' => __('API App Key.', 'QNBPay'),
            ],
            'app_secret' => [
                'title'    => __('App Secret', 'QNBPay'),
                'type'     => 'text',
                'desc_tip' => __('API App Secret.', 'QNBPay'),
            ],
            'merchant_id' => [
                'title'    => __('Merchant ID', 'QNBPay'),
                'type'     => 'text',
                'desc_tip' => __('Üye işyeri numarası (Merchant ID).', 'QNBPay'),
            ],
            'sale_webhook_key' => [
                'title'       => __('Satış Webhook Anahtarı', 'QNBPay'),
                'type'        => 'text',
                'desc_tip'    => __('QNB panelinde "Satış Webhook" adresi olarak aşağıdaki URL\'yi tanımlayın.', 'QNBPay'),
                'description' => get_site_url() . '?webhook=1',
            ],
            /*'recurring_sale_webhook_key' => [
                    'title' => __('Yinelenen Satış Webhook Anahtarı', 'QNBPay'),
                    'type' => 'text',
                    'desc_tip' => __('Yinelenen Satış Webhook Anahtarı', 'QNBPay'),
                    'description' => get_site_url() . '?webhook=1&recurring=1',
                ],*/

            'section_payment' => array(
                'title' => __('Ödeme ayarları', 'QNBPay'),
                'type'  => 'title',
            ),
            'environment' => [
                'title'       => __('Test Modu', 'QNBPay'),
                'label'       => __('Etkinleştir', 'QNBPay'),
                'type'        => 'checkbox',
                'description' => __('Test ortamında deneme yapmak için etkinleştirin. Canlıya geçerken kapatın.', 'QNBPay'),
                'default'     => 'no',
            ],
            'transaction_type' => array(
                'title'   => __('Provizyon Türü', 'QNBPay'),
                'type'    => 'select',
                'default' => 'Auth',
                'options' => array('Auth' => __('Auth (anında çekim)', 'QNBPay'), 'PreAuth' => __('PreAuth (ön provizyon)', 'QNBPay'))
            ),
            'installment_type' => array(
                'title'   => __('Vade Farkını Kart Sahibi Ödesin', 'QNBPay'),
                'label'   => __('Etkinleştir', 'QNBPay'),
                'type'    => 'checkbox',
                'default' => 'yes'
            ),
            // Taksit seçenekleri QNB hosted ödeme sayfasında POS tanımınıza göre
            // gösterilir; eklentiden ayrıca sınırlanmaz (selected_installments
            // hosted akışta ödemeyi bozuyor). "Vade Farkını Kart Sahibi Ödesin"
            // seçeneği geçerlidir ve QNB'ye iletilir.

            'section_display' => array(
                'title'       => __('Görünüm', 'QNBPay'),
                'type'        => 'title',
                'description' => __('Ödeme adımında müşterinin gördüğü başlık ve satır görünümü.', 'QNBPay'),
            ),
            'checkout_theme' => array(
                'title'       => __('Ödeme satırı teması', 'QNBPay'),
                'type'        => 'select',
                'default'     => 'kartli',
                'options'     => array(
                    'sade'     => __('Sade', 'QNBPay'),
                    'kartli'   => __('Kartlı (kart logoları)', 'QNBPay'),
                    'vurgulu'  => __('Vurgulu (güvenli ödeme paneli)', 'QNBPay'),
                    'modern'   => __('Modern (gölgeli kart)', 'QNBPay'),
                    'kurumsal' => __('Kurumsal (banka görünümü)', 'QNBPay'),
                    'gradient' => __('Gradyan (premium)', 'QNBPay'),
                    'koyu'     => __('Koyu (dark mode)', 'QNBPay'),
                    'ince'     => __('İnce çerçeve', 'QNBPay'),
                    'guvence'  => __('Güvence rozetleri', 'QNBPay'),
                    'kompakt'  => __('Kompakt çip', 'QNBPay'),
                ),
                'description' => __('Ödeme yönteminin checkout görünümü.', 'QNBPay'),
            ),
            'title' => [
                'title'    => __('Başlık', 'QNBPay'),
                'type'     => 'text',
                'desc_tip' => __('Ödeme adımında görünen başlık.', 'QNBPay'),
                'default'  => __('Banka/Kredi Kartı ile Öde', 'QNBPay'),
            ],
            'description' => [
                'title'    => __('Açıklama', 'QNBPay'),
                'type'     => 'textarea',
                'desc_tip' => __('Başlığın altında görünen kısa açıklama.', 'QNBPay'),
                'default'  => __('Kredi veya banka kartınızla QNB güvenli ödeme sayfasında ödeyin.', 'QNBPay'),
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
        return array('sade', 'kartli', 'vurgulu', 'modern', 'kurumsal', 'gradient', 'koyu', 'ince', 'guvence', 'kompakt');
    }

    public function qnbpay_theme()
    {
        $t = $this->get_option('checkout_theme', 'kartli');
        return in_array($t, self::qnbpay_theme_keys(), true) ? $t : 'kartli';
    }

    /** Icon pixel height per theme. */
    public static function qnbpay_theme_icon_height($theme)
    {
        $map = array(
            'sade' => 20, 'kartli' => 26, 'vurgulu' => 28, 'modern' => 24, 'kurumsal' => 22,
            'gradient' => 22, 'koyu' => 22, 'ince' => 22, 'guvence' => 20, 'kompakt' => 20,
        );
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

    /**
     * Accepted card brand <img> tags at the given pixel height. Pass $chip=true to
     * wrap each logo in a white rounded chip (readable on dark/gradient backgrounds).
     */
    public function card_icons_html($height, $chip = false)
    {
        $base  = plugins_url('assets/images/cards/', __FILE__);
        $cards = array('mastercard', 'visa', 'amex', 'troy');
        $out = '';
        foreach ($cards as $c) {
            $img = '<img src="' . esc_url($base . $c . '.svg') . '" alt="' . esc_attr($c) . '" style="height:' . (int) $height . 'px;width:auto;vertical-align:middle;display:inline-block;' . ($chip ? '' : 'margin-left:4px;') . '" />';
            $out .= $chip
                ? '<span style="background:#fff;border-radius:6px;padding:3px 5px;margin-left:6px;display:inline-flex;align-items:center;">' . $img . '</span>'
                : $img;
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
        $note    = esc_html__('Kartınızla QNB\'nin güvenli ödeme sayfasında ödeyeceksiniz. Kart bilgileriniz bu sitede saklanmaz.', 'QNBPay');
        $secure  = esc_html__('3D Secure ile güvenli ödeme', 'QNBPay');
        $guvenli = esc_html__('Güvenli ödeme', 'QNBPay');

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
                . '<span style="font-weight:600;color:#1f3a5f;">' . esc_html__('QNB ile güvenli ödeme', 'QNBPay') . '</span></div>'
                . '<div style="padding:0 16px 12px;color:#555;">' . $note . '</div>'
                . '<div style="border-top:1px solid #eef1f5;padding:10px 16px;display:flex;justify-content:flex-end;">' . $this->card_icons_html(20) . '</div>'
                . '</div>';
        }
        if ($theme === 'gradient') {
            return '<div class="qnbpay-hosted-note" style="background:linear-gradient(135deg,#7b2ff7 0%,#2b6cff 100%);border-radius:14px;padding:16px 18px;color:#fff;box-shadow:0 8px 24px rgba(43,108,255,0.22);">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">'
                . '<span style="display:flex;align-items:center;gap:8px;font-weight:600;">' . self::qnbpay_lock_svg('#ffffff', 16) . $guvenli . '</span>'
                . '<span style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.35);border-radius:999px;padding:3px 10px;font-size:11px;font-weight:600;">3D Secure</span>'
                . '</div>'
                . '<div style="color:rgba(255,255,255,0.9);margin-bottom:12px;">' . $note . '</div>'
                . '<div style="display:flex;flex-wrap:wrap;">' . $this->card_icons_html(18, true) . '</div>'
                . '</div>';
        }
        if ($theme === 'koyu') {
            return '<div class="qnbpay-hosted-note" style="background:#12141c;border:1px solid #262a36;border-radius:12px;padding:14px 16px;color:#e6e8ef;">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">'
                . '<span style="display:flex;align-items:center;gap:8px;font-weight:600;color:#f2f4f8;">' . self::qnbpay_lock_svg('#34d399', 16) . $guvenli . '</span>'
                . '<span style="display:inline-flex;">' . $this->card_icons_html(18, true) . '</span>'
                . '</div>'
                . '<div style="color:#aab1c0;">' . $note . '</div>'
                . '</div>';
        }
        if ($theme === 'ince') {
            return '<div class="qnbpay-hosted-note" style="border:1.5px solid #2b6cff;border-radius:14px;padding:16px 18px;color:#1d2327;">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;">'
                . '<span style="display:flex;align-items:center;gap:8px;font-weight:600;">' . self::qnbpay_lock_svg('#2b6cff', 15) . $guvenli . '</span>'
                . '<span style="display:inline-flex;align-items:center;">' . $this->card_icons_html(22) . '</span>'
                . '</div>'
                . '<div style="color:#6b7280;">' . $note . '</div>'
                . '</div>';
        }
        if ($theme === 'guvence') {
            $pill = 'background:#fff;border:1px solid #cde9d6;border-radius:999px;padding:3px 10px;font-size:11.5px;color:#166534;font-weight:600;display:inline-flex;align-items:center;gap:5px;';
            return '<div class="qnbpay-hosted-note" style="background:#f6fbf7;border:1px solid #cde9d6;border-radius:10px;padding:12px 14px;color:#1d2327;">'
                . '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">'
                . '<span style="' . $pill . '">' . self::qnbpay_lock_svg('#16a34a', 12) . '3D Secure</span>'
                . '<span style="' . $pill . '">SSL</span>'
                . '<span style="' . $pill . '">PCI DSS</span>'
                . '<span style="' . $pill . '">' . esc_html__('Kart bilgisi saklanmaz', 'QNBPay') . '</span>'
                . '</div>'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
                . '<span style="color:#3f6b4c;">' . $note . '</span>'
                . '<span style="display:inline-flex;align-items:center;">' . $this->card_icons_html(20) . '</span>'
                . '</div></div>';
        }
        if ($theme === 'kompakt') {
            return '<div class="qnbpay-hosted-note">'
                . '<div style="display:flex;align-items:center;gap:10px;background:#eef3ff;border:1px solid #d6e2ff;border-radius:999px;padding:10px 16px;flex-wrap:wrap;">'
                . self::qnbpay_lock_svg('#2b6cff', 16)
                . '<span style="font-weight:600;color:#1d2327;">' . $guvenli . '</span>'
                . '<span style="flex:1;"></span>'
                . '<span style="display:inline-flex;align-items:center;">' . $this->card_icons_html(20) . '</span>'
                . '</div>'
                . '<div style="padding:8px 4px 0;color:#555;">' . $note . '</div>'
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
            $order->add_order_note(__('QNBpay güvenli ödeme sayfasına yönlendirildi (hosted).', 'QNBPay'));
            return array('result' => 'success', 'redirect' => $resp->link);
        }

        $desc = (is_object($resp) && isset($resp->status_description)) ? (string) $resp->status_description : $api->last_error;
        qnbpay_log('purchase/link failed for order ' . $order_id . ' code=' . (is_object($resp) && isset($resp->status_code) ? $resp->status_code : 'n/a') . ' reason=' . $desc, 'error');
        wc_add_notice(__('QNBpay ödemesi başlatılamadı. Lütfen tekrar deneyin.', 'QNBPay') . ($desc !== '' ? ' (' . esc_html($desc) . ')' : ''), 'error');
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
            return new WP_Error('qnbpay_refund', __('Sipariş bulunamadı.', 'QNBPay'));
        }
        $invoice_id = $order->get_meta('_qnbpay_invoice_id');
        if (!$invoice_id) {
            return new WP_Error('qnbpay_refund', __('QNBpay fatura referansı bulunamadı; bu sipariş QNBpay ile alınmamış olabilir.', 'QNBPay'));
        }

        $api  = new QNBPay_Api();
        $resp = $api->refund($invoice_id, $amount);
        if (!is_object($resp)) {
            $err = ($api->last_error !== '') ? $api->last_error : __('QNB iade servisine ulaşılamadı.', 'QNBPay');
            qnbpay_log('refund transport error for order ' . $order_id . ': ' . $err, 'error');
            return new WP_Error('qnbpay_refund', $err);
        }

        $code    = isset($resp->status_code) ? (string) $resp->status_code : '';
        $desc    = isset($resp->status_description) ? (string) $resp->status_description : '';
        $amount_str = ($amount === null || $amount === '') ? __('tam tutar', 'QNBPay') : html_entity_decode(wp_strip_all_tags(wc_price($amount, array('currency' => $order->get_currency()))));

        if ($code === '100') {
            $order->add_order_note(sprintf(__('QNBpay: iade tamamlandı (%1$s). Kod 100. %2$s', 'QNBPay'), $amount_str, $desc));
            return true;
        }
        if ($code === '101') {
            $order->add_order_note(sprintf(__('QNBpay: iade talebi alındı, QNB tarafında tamamlanacak (%1$s). Kod 101: QNB panelinden veya checkstatus ile doğrulayın.', 'QNBPay'), $amount_str));
            return true;
        }

        qnbpay_log('refund rejected for order ' . $order_id . ' code=' . $code . ' reason=' . $desc, 'error');
        $extra = ($code === '49') ? ' ' . __('(Aynı işlemde iki iade arasında en az 30 saniye bekleyin.)', 'QNBPay') : '';
        return new WP_Error('qnbpay_refund', sprintf(__('QNBpay iade reddedildi. Kod %1$s. %2$s', 'QNBPay'), $code, $desc) . $extra);
    }

}
