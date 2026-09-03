<?php
/*
    Plugin Name: QNBPay SanalPos
    Plugin URI: https://github.com/bucagdas/qnbpay-woocommerce
    Description: WooCommerce icin QNBPay odeme gecidi. Klasik ve Cart/Checkout Blocks checkout, hosted odeme sayfasi.
    Version: 1.0.2
    Author: bucagdas
    Requires Plugins: woocommerce
    Requires at least: 6.5
    Tested up to: 7.1
    Requires PHP: 7.4
    WC requires at least: 8.0
    WC tested up to: 11.0
    Text Domain: QNBPay
    Domain Path: /i18n/languages/
    License: GPL-2.0-or-later
    License URI: https://www.gnu.org/licenses/gpl-2.0.html
    */
if (!defined('ABSPATH')) {
    exit;
}

// Automatic updates from the GitHub repo releases (vendored plugin-update-checker).
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
$qnbpay_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/bucagdas/qnbpay-woocommerce/',
    __FILE__,
    'qnbpay_woocommerce'
);
$qnbpay_update_checker->getVcsApi()->enableReleaseAssets();

add_action('plugins_loaded', 'qnb_pos', 0);
// Declare High-Performance Order Storage compatibility (all order access is via wc_get_order/$order).
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Register the Cart/Checkout Blocks payment method integration.
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
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    include_once __DIR__ . '/includes/class-qnbpay-api.php';
    include_once __DIR__ . '/includes/class-qnbpay-webhook.php';
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
    // Require a nonce, login, and row ownership so a member cannot delete another customer's card.
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
        CURLOPT_VERBOSE => false, // verbose would dump the bearer token to logs
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}
