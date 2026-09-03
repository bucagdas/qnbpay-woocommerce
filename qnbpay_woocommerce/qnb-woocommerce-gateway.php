<?php
/*
    Plugin Name: QNBPay SanalPos
    Plugin URI: https://www.qnbpay.com.tr/
    Description: Woocommerce için QNBPay Entegrasyonu
    Domain Path: /i18n/languages/
    Text Domain: QNBPay

    */
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'qnb_pos', 0);
// REFACTOR: declare HPOS (custom order tables) compatibility. The active hosted flow and
// the verified return/webhook handler read and write order data via wc_get_order()/$order,
// so the plugin is compatible with High-Performance Order Storage.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// REFACTOR (Blocks): register the Cart/Checkout Blocks payment method integration so the
// gateway is selectable in the Checkout block (classic and blocks share the hosted server flow).
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    require_once __DIR__ . '/includes/class-qnbpay-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new QNBPay_Blocks());
    });
});
add_action('wp_ajax_delete_qnb_card', 'delete_qnb_card');
add_action('wp_ajax_get_admin_installment', 'get_admin_installment');

add_action('wp_ajax_nopriv_get_admin_installment', 'get_admin_installment');
function qnb_pos()
{
    //if condition use to do nothin while WooCommerce is not installed
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    include_once __DIR__ . '/includes/class-qnbpay-api.php'; // REFACTOR: QNB API layer
    include_once __DIR__ . '/includes/class-qnbpay-webhook.php'; // REFACTOR: webhook/settlement layer
    QNBPay_Webhook::init();
    include_once 'qnb-woocommerce.php';
    include_once 'qnb-woocommerce-recurring.php';
    // class add it too WooCommerce

    add_filter('woocommerce_payment_gateways', 'qnb_gateway');
    function qnb_gateway($methods)
    {
        $methods[] = 'QNBPay_sanalpos';
        return $methods;
    }
}

function delete_qnb_card()
{
    // SECURITY (BULGULAR #3): previously ANY logged-in user could delete ANY customer's saved
    // card by its token (no nonce, no ownership check). Require a valid nonce, an authenticated
    // user, and scope the delete to the current customer's own rows.
    check_ajax_referer('qnbpay_ajax', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error('unauthorized', 403);
    }
    global $wpdb;
    $card = isset($_POST['card']) ? sanitize_text_field(wp_unslash($_POST['card'])) : '';
    if ($card === '') {
        wp_send_json_error('bad_request', 400);
    }
    $table = $wpdb->prefix . 'qnb_cards';
    $deleted = $wpdb->delete($table, array('card_token' => $card, 'customer_id' => get_current_user_id()));
    wp_send_json_success(array('deleted' => (int) $deleted));
}


// Add custom action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'qnb_settings');
function qnb_settings($links)
{
    $plugin_links = ['<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=QNBPay_sanalpos') . '">' . __('Settings', 'QNBPay_sanalpos') . '</a>'];
    return array_merge($plugin_links, $links);
}

function getCurl($url, $method, $array, $header = [])
{
    $curl = curl_init();
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if (count($header) > 0) {
        $headers = array_merge($headers, $header);
    }
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($array),
        CURLOPT_VERBOSE => false, // BULGULAR #6/#7: verbose leaked bearer token to logs
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}
