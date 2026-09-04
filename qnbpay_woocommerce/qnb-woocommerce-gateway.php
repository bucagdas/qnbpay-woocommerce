<?php
/*
    Plugin Name: QNBPay SanalPos
    Plugin URI: https://github.com/bucagdas/qnbpay-woocommerce
    Description: WooCommerce icin QNBPay odeme gecidi. Klasik ve Cart/Checkout Blocks checkout, hosted odeme sayfasi.
    Version: 1.1.2
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

/**
 * Write to the WooCommerce log channel "qnbpay" (WooCommerce > Status > Logs),
 * so store owners can read diagnostics from wp-admin without shell access.
 * Falls back to error_log if WooCommerce logging is unavailable.
 *
 * @param string $message
 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
 */
function qnbpay_log($message, $level = 'error')
{
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->log($level, $message, array('source' => 'qnbpay'));
        return;
    }
    error_log('QNBpay: ' . $message);
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
    $plugin_links = [
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=QNBPay_sanalpos') . '">' . __('Settings', 'QNBPay_sanalpos') . '</a>',
        '<a href="https://github.com/bucagdas/qnbpay-woocommerce" target="_blank" rel="noopener noreferrer">GitHub</a>',
    ];
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


// Settings-page diagnostic: show whether the server can reach QNB and what the
// account allows (is_3d). Guarded so it can never break the admin page.
add_action('admin_enqueue_scripts', 'qnbpay_admin_settings_assets');
function qnbpay_admin_settings_assets($hook)
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
    $section = isset($_GET['section']) ? strtolower(sanitize_text_field(wp_unslash($_GET['section']))) : '';
    if ($tab !== 'checkout' || $section !== 'qnbpay_sanalpos') {
        return;
    }
    wp_enqueue_script('qnbpay-admin-settings', plugins_url('assets/js/admin-settings.js', __FILE__), array(), '1.1.2', true);

    // Provide the backed-up real credentials (if any) so the settings page can
    // offer a "restore real keys" action after the sandbox test keys are loaded.
    $backup = get_option('qnbpay_live_keys_backup', array());
    $live = null;
    if (is_array($backup) && !empty($backup['merchant_key'])) {
        $live = array(
            'merchant_key' => isset($backup['merchant_key']) ? (string) $backup['merchant_key'] : '',
            'app_key'      => isset($backup['app_key']) ? (string) $backup['app_key'] : '',
            'app_secret'   => isset($backup['app_secret']) ? (string) $backup['app_secret'] : '',
            'merchant_id'  => isset($backup['merchant_id']) ? (string) $backup['merchant_id'] : '',
            'environment'  => isset($backup['environment']) ? (string) $backup['environment'] : 'no',
        );
    }
    // Build a live preview of each theme, reusing the real checkout renderers so
    // the panel preview matches the storefront exactly. The title text is filled
    // in by JS from the Title field (kept live).
    $previews = array();
    $gateways = (function_exists('WC') && WC()->payment_gateways()) ? WC()->payment_gateways()->payment_gateways() : array();
    $gw = isset($gateways['QNBPay_sanalpos']) ? $gateways['QNBPay_sanalpos'] : null;
    if ($gw && class_exists('QNBPay_sanalpos')) {
        foreach (QNBPay_sanalpos::qnbpay_theme_keys() as $t) {
            $h = QNBPay_sanalpos::qnbpay_theme_icon_height($t);
            $label = '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">'
                . '<strong class="qnb-preview-title" style="font-weight:600;color:#1d2327;"></strong>'
                . '<span style="display:inline-flex;align-items:center;">' . $gw->card_icons_html($h) . '</span>'
                . '</div>';
            $previews[$t] = $label . $gw->render_hosted_note($t);
        }
    }
    wp_localize_script('qnbpay-admin-settings', 'qnbpayAdminData', array(
        'liveBackup'      => $live,
        'testMerchantKey' => class_exists('QNBPay_sanalpos') ? QNBPay_sanalpos::QNB_TEST_MERCHANT_KEY : '',
        'previews'        => $previews,
        'defaultTitle'    => __('Banka/Kredi Karti ile Ode', 'qnb'),
    ));
}

add_action('admin_notices', 'qnbpay_settings_connection_notice');
function qnbpay_settings_connection_notice()
{
    if (!is_admin() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
    $section = isset($_GET['section']) ? strtolower(sanitize_text_field(wp_unslash($_GET['section']))) : '';
    if ($tab !== 'checkout' || $section !== 'qnbpay_sanalpos') {
        return;
    }
    if (!class_exists('QNBPay_Api')) {
        return;
    }
    try {
        $api = new QNBPay_Api();
        if ($api->option('app_key') === '' || $api->option('app_secret') === '') {
            return;
        }
        $mode = $api->is_sandbox() ? 'test' : 'canli';
        $t = $api->test_connection();
        if (!empty($t['ok'])) {
            $is3d = isset($t['is_3d']) ? (string) $t['is_3d'] : '';
            echo '<div class="notice notice-success"><p><strong>QNBPay (' . esc_html($mode) . '):</strong> QNB baglantisi ve odeme baslatma testi basarili. Hesap is_3d=' . esc_html($is3d) . '.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p><strong>QNBPay (' . esc_html($mode) . ') sorun:</strong> ' . esc_html($t['message']) . '</p></div>';
        }
    } catch (\Throwable $e) {
        // never break the admin page
    }
}
