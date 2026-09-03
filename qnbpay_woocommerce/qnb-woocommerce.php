<?php

if (!defined('ABSPATH')) {
    exit;
}

class QNBPay_sanalpos extends WC_Payment_Gateway
{

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
            error_log($e->getMessage());
        }
    }

    public function init_form_fields()
    {

        $installments = $this->get_admin_installment();
        $this->form_fields = [

            'merchant_key' => [
                'title' => __('Merchant Key', 'qnb'),
                'type' => 'text',
                'desc_tip' => __('Merchant Key', 'qnb'),
            ],
            'app_key' => [
                'title' => __('App Key', 'qnb'),
                'type' => 'text',
                'desc_tip' => __('App key', 'qnb'),
            ],
            'app_secret' => [
                'title' => __('App Secret', 'qnb'),
                'type' => 'text',
                'desc_tip' => __('App Secret', 'qnb'),
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'qnb'),
                'type' => 'text',
                'desc_tip' => __('Merchant ID', 'qnb'),
            ],
            'sale_webhook_key' => [
                'title' => __('Satış Webhook Anahtarı', 'qnb'),
                'type' => 'text',
                'desc_tip' => __('Satış Webhook Anahtarı', 'qnb'),
                'description' => get_site_url() . '?webhook=1',
            ],
            /*'recurring_sale_webhook_key' => [
                    'title' => __('Yinelenen Satış Webhook Anahtarı', 'qnb'),
                    'type' => 'text',
                    'desc_tip' => __('Yinelenen Satış Webhook Anahtarı', 'qnb'),
                    'description' => get_site_url() . '?webhook=1&recurring=1',
                ],*/
            'environment' => [
                'title' => __('Test Modu', 'qnb'),
                'label' => __('Etkinleştir', 'qnb'),
                'type' => 'checkbox',
                'description' => __('Test ortamında deneme yapmak için etkinleştirin', 'qnb'),
                'default' => 'no',
            ],
            'transaction_type' => array(
                'title' => __('Provizyon Türü', 'qnb'),
                'type' => 'select',
                'default' => 'Auth',
                'options' => array('Auth' => __('Auth', 'qnb'), 'PreAuth' => __('PreAuth', 'qnb'))
            ),
            'installment_type' => array(
                'title' => __('Vade Farkını Kart Sahibi Ödesin', 'qnb'),
                'label' => __('Etkinleştir', 'qnb'),
                'type' => 'checkbox',
                'default' => 'yes'
            ),
            'installments' => array(
                'title' => 'Taksit Sayısı',
                'type' => 'multiselect',
                'options' => $installments,
                'description' => __('Shift ile çoklu seçim yapabilirsiniz.', 'qnb'),
                'custom_attributes' => array(
                    'data-placeholder' => __('Taksit Seçiniz', 'qnb'),
                ),
            ),
            'enabled' => [
                'title' => __('Ödeme Yöntemi <br> Etkin/Pasif', 'qnb'),
                'label' => __('Bu yöntemi etkinleştir', 'qnb'),
                'type' => 'checkbox',
                'default' => 'no',
            ],
            'checkout_theme' => array(
                'title'       => __('Odeme satiri temasi', 'qnb'),
                'type'        => 'select',
                'default'     => 'kartli',
                'options'     => array(
                    'sade'    => __('Sade', 'qnb'),
                    'kartli'  => __('Kartli (kart logolari)', 'qnb'),
                    'vurgulu' => __('Vurgulu (guvenli odeme paneli)', 'qnb'),
                ),
                'description' => __('Odeme yonteminin checkout gorunumu.', 'qnb'),
            ),
            'title' => [
                'title' => __('Başlık', 'qnb'),
                'type' => 'text',
                'desc_tip' => __('Başlık', 'qnb'),
                'default' => __('Banka/Kredi Karti ile Ode', 'qnb'),
            ],
            'description' => [
                'title' => __('Açıklama', 'qnb'),
                'type' => 'textarea',
                'desc_tip' => __('Açıklama', 'qnb'),
                'default' => __('Kredi veya banka kartinizla QNB guvenli odeme sayfasinda odeyin.', 'qnb'),
                'css' => 'max-width:450px;',
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
    public function qnbpay_theme()
    {
        $t = $this->get_option('checkout_theme', 'kartli');
        return in_array($t, array('sade', 'kartli', 'vurgulu'), true) ? $t : 'kartli';
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
        $h = ($theme === 'sade') ? 20 : (($theme === 'vurgulu') ? 28 : 26);
        $html = '<span class="qnbpay-card-icons" style="float:right;display:inline-flex;align-items:center;">' . $this->card_icons_html($h) . '</span>';
        return apply_filters('woocommerce_gateway_icon', $html, $this->id);
    }

    public function payment_fields()
    {
        // No card fields here: the buyer enters the card on QNB's hosted page.
        if ($description = $this->get_description()) {
            echo wpautop(wptexturize($description));
        }
        $theme = $this->qnbpay_theme();
        $note = esc_html__('Kartinizla QNB\'nin guvenli odeme sayfasinda odeyeceksiniz. Kart bilgileriniz bu sitede saklanmaz.', 'QNBPay');
        if ($theme === 'sade') {
            echo '<div class="qnbpay-hosted-note" style="padding:8px 0;color:#555;">' . $note . '</div>';
        } elseif ($theme === 'vurgulu') {
            echo '<div class="qnbpay-hosted-note" style="border-left:4px solid #6c2bd9;background:#faf9ff;border-radius:8px;padding:14px 16px;color:#333;">'
                . '<div style="font-weight:600;margin-bottom:6px;">' . esc_html__('3D Secure ile guvenli odeme', 'QNBPay') . '</div>'
                . '<div style="margin-bottom:10px;color:#555;">' . $note . '</div>'
                . '<div>' . $this->card_icons_html(26) . '</div>'
                . '</div>';
        } else {
            echo '<div class="qnbpay-hosted-note" style="background:#f7f8fa;border:1px solid #e6e8eb;border-radius:8px;padding:12px 14px;color:#333;">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
                . '<span style="color:#555;">' . $note . '</span>'
                . '<span style="display:inline-flex;align-items:center;">' . $this->card_icons_html(24) . '</span>'
                . '</div></div>';
        }
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
        error_log('QNBpay purchase/link failed for order ' . $order_id . ' code=' . (is_object($resp) && isset($resp->status_code) ? $resp->status_code : 'n/a') . ' reason=' . $desc);
        wc_add_notice(__('QNBpay odemesi baslatilamadi. Lutfen tekrar deneyin.', 'QNBPay') . ($desc !== '' ? ' (' . esc_html($desc) . ')' : ''), 'error');
        return array('result' => 'failure');
    }

    function get_admin_installment()
    {

        $inst = [];

        $post = [
            'app_id' => $this->get_option('app_key'),

            'app_secret' => $this->get_option('app_secret'),
        ];


        $environment = $this->get_option('environment') == "yes" ? 'TRUE' : 'FALSE';

        // Decide which URL to post to
        $environment_url = "FALSE" == $environment ? 'https://portal.qnbpay.com.tr/ccpayment/api/token' : 'https://test.qnbpay.com.tr/ccpayment/api/token';


        if (!empty($this->get_option('app_key')) && !empty($this->get_option('app_secret')) && !empty($this->get_option('merchant_key')) && !empty($this->get_option('environment'))) {

            $token = getCurl($environment_url, 'POST', $post);

            $token = $token->data->token;
            $environment_url = "FALSE" == $environment ? 'https://portal.qnbpay.com.tr/ccpayment/api/installments' : 'https://test.qnbpay.com.tr/ccpayment/api/installments';
            $headers = ["Authorization: Bearer $token"];
            $a['merchant_key'] = $this->get_option('merchant_key');

            $installments = getCurl($environment_url, 'POST', $a, $headers);
            //error_log(json_encode($installments));
            $inst = [];

            foreach ($installments->installments as $key => $installment) {
                $inst[$key + 1] = $installment;
            }


            return $inst;
        }

        return $inst;
    }

}
