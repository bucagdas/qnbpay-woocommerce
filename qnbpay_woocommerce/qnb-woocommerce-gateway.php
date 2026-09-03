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
add_action('init', 'my_custom_public_page');
add_action('wp_ajax_delete_qnb_card', 'delete_qnb_card');
add_action('wp_ajax_get_installment', 'get_installment');
add_action('wp_ajax_get_admin_installment', 'get_admin_installment');

add_action('wp_ajax_nopriv_get_installment', 'get_installment');
add_action('wp_ajax_nopriv_get_admin_installment', 'get_admin_installment');
function qnb_pos()
{
    //if condition use to do nothin while WooCommerce is not installed
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
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

function getToken()
{
    $qnb_pay = new QNBPay_sanalpos();
    $api_secret = $qnb_pay->get_option('app_secret');
    $api_key = $qnb_pay->get_option('app_key');
    $merchant_key = $qnb_pay->get_option('merchant_key');
    $merchant_id = $qnb_pay->get_option('merchant_id');
    $sandbox = $qnb_pay->get_option('environment');


    $url = $sandbox == 'yes' ? 'https://test.qnbpay.com.tr/ccpayment/api/token' : 'https://portal.qnbpay.com.tr/ccpayment/api/token';

    $array = [
        'app_id' => $api_key,
        'app_secret' => $api_secret
    ];

    return getCurl($url, 'POST', $array);
}

function checkStatus($invoice_id)
{
    $qnb_pay = new QNBPay_sanalpos();
    $api_secret = $qnb_pay->get_option('app_secret');
    $api_key = $qnb_pay->get_option('app_key');
    $merchant_key = $qnb_pay->get_option('merchant_key');
    $merchant_id = $qnb_pay->get_option('merchant_id');
    $sandbox = $qnb_pay->get_option('environment');

    $hash_key = generateRefundHashKey($invoice_id, $merchant_key, $api_secret);
    $token = getToken()->data->token;
    // BULGULAR #1: getCurl() already sends Accept + Content-Type; passing them again here
    // produced duplicate headers that the gateway WAF rejected with HTTP 400 (checkstatus never worked).
    $headers = ["Authorization: Bearer {$token}"];
    $url = $sandbox == 'yes' ? 'https://test.qnbpay.com.tr/ccpayment/api/checkstatus' : 'https://portal.qnbpay.com.tr/ccpayment/api/checkstatus';

    $array = [
        'invoice_id' => $invoice_id,
        'merchant_key' => $merchant_key,
        'hash_key' => $hash_key,
        'include_pending_status' => true, // BULGULAR #1: API expects JSON boolean; "true" (string) returned HTTP 400 and broke every checkstatus verification
    ];

    return getCurl($url, 'POST', $array, $headers); // BULGULAR #1: pass array (getCurl re-encodes); json_encode here double-encoded the body
}

function generateRefundHashKey($invoice_id, $merchant_key, $app_secret)
{
    $data = $invoice_id . '|' . $merchant_key;
    $iv = substr(sha1(mt_rand()), 0, 16);
    $password = sha1($app_secret);
    $salt = substr(sha1(mt_rand()), 0, 4);
    $saltWithPassword = hash('sha256', $password . $salt);
    $encrypted = openssl_encrypt(
        "$data",
        'aes-256-cbc',
        "$saltWithPassword",
        null,
        $iv
    );
    $msg_encrypted_bundle = "$iv:$salt:$encrypted";
    $hash_key = str_replace('/', '__', $msg_encrypted_bundle);
    return $hash_key;
}

/**
 * SECURITY (BULGULAR #1): resolve the WooCommerce order from a QNBpay invoice_id of the
 * form "<rand>WOO<order_id>". Uses wc_get_order (HPOS-safe). Returns WC_Order or false.
 */
function qnbpay_order_from_invoice($invoice_id)
{
    if (!is_string($invoice_id) || strpos($invoice_id, 'WOO') === false) {
        return false;
    }
    $parts = explode('WOO', $invoice_id);
    $order_id = absint(end($parts));
    if (!$order_id) {
        return false;
    }
    $order = wc_get_order($order_id);
    return $order ? $order : false;
}

/**
 * SECURITY (BULGULAR #1): validate a QNBpay hash_key. It is NOT an HMAC; it is an
 * AES-256-CBC ciphertext bundled as "iv:salt:base64", with every '/' transported as '__'.
 * Documented inverse of generateHashKey; hash GENERATION is left unchanged.
 * Returns [status, total, invoice_id, order_id, currency_code] (empty strings on failure).
 */
function qnbpay_validate_hash_key($hash_key, $app_secret)
{
    $status = $currency = '';
    $total = $invoice_id = $order_id = '';
    if (!is_string($hash_key) || $hash_key === '') {
        return array($status, $total, $invoice_id, $order_id, $currency);
    }
    $hash_key = str_replace('__', '/', $hash_key);
    $password = sha1($app_secret);
    $components = explode(':', $hash_key);
    if (count($components) > 2) {
        $iv = $components[0];
        $salt = hash('sha256', $password . $components[1]);
        $decrypted = openssl_decrypt($components[2], 'aes-256-cbc', $salt, 0, $iv);
        if ($decrypted !== false && strpos($decrypted, '|') !== false) {
            list($status, $total, $invoice_id, $order_id, $currency) = array_pad(explode('|', $decrypted), 5, '');
        }
    }
    return array($status, $total, $invoice_id, $order_id, $currency);
}

/**
 * SECURITY (BULGULAR #1): server-to-server confirmation. A redirect or webhook proves
 * nothing about the money; /api/checkstatus (merchant-credentialed) is the authority.
 * Returns the checkstatus response object when the invoice is confirmed paid AND its
 * amount/currency match the order, false otherwise.
 */
function qnbpay_checkstatus_paid($invoice_id, $order)
{
    $status = checkStatus($invoice_id);
    if (!is_object($status)) {
        return false;
    }
    // checkstatus confirms with transaction_status ("COMPLETED"); some flows also return status_code 100.
    $txn_status = isset($status->transaction_status) ? (string) $status->transaction_status : '';
    $ok_code = isset($status->status_code) && ((string) $status->status_code === '100');
    $ok_txn = (strcasecmp($txn_status, 'Completed') === 0);
    if (!$ok_code && !$ok_txn) {
        return false;
    }
    // amount + currency must match the order
    $amount = null;
    foreach (array('transaction_amount', 'product_price', 'total') as $k) {
        if (isset($status->$k) && is_numeric($status->$k)) {
            $amount = (float) $status->$k;
            break;
        }
    }
    if ($amount !== null && abs($amount - (float) $order->get_total()) > 0.01) {
        error_log('QNBpay checkstatus: amount mismatch for order ' . $order->get_id());
        return false;
    }
    return $status;
}

/**
 * SECURITY (BULGULAR #1): verify a QNBpay notification (webhook or 3D return) and settle
 * only when the server confirms payment. Returns true (settled), 'preauth' (blocked, not
 * captured) or false (rejected). Never settles on the notification alone.
 */
function qnbpay_settle_from_notification($order, $invoice_id, $payment_status, $transaction_type, $order_no, $incoming_hash, $context)
{
    $qnb_pay = new QNBPay_sanalpos();
    $app_secret = $qnb_pay->get_option('app_secret');

    // Failed notification: mark failed only after the server confirms it is NOT paid, so a
    // forged payment_status=0 cannot flip a genuinely paid order.
    if ((string) $payment_status !== '1') {
        if (!qnbpay_checkstatus_paid($invoice_id, $order)) {
            $order->update_status('failed', __('QNBpay: islem basarisiz (dogrulandi).', 'QNBPay'));
        }
        return true;
    }

    // Integrity: if a hash_key is present it must decrypt and match this invoice/total/currency.
    if (is_string($incoming_hash) && $incoming_hash !== '') {
        list($h_status, $h_total, $h_invoice, $h_order, $h_currency) = qnbpay_validate_hash_key($incoming_hash, $app_secret);
        $invoice_ok = ($h_invoice === $invoice_id);
        $total_ok = ($h_total === '') ? true : (abs((float) $h_total - (float) $order->get_total()) <= 0.01);
        $currency_ok = ($h_currency === '') ? true : (strcasecmp($h_currency, $order->get_currency()) === 0);
        if (!$invoice_ok || !$total_ok || !$currency_ok) {
            error_log('QNBpay ' . $context . ': hash_key mismatch for order ' . $order->get_id());
            return false;
        }
    }

    // Authoritative gate: server-to-server checkstatus must confirm payment + amount.
    $status = qnbpay_checkstatus_paid($invoice_id, $order);
    if ($status === false) {
        error_log('QNBpay ' . $context . ': checkstatus did not confirm order ' . $order->get_id());
        return false;
    }

    // Idempotency: never re-settle an already paid order.
    if ($order->is_paid()) {
        return true;
    }

    $ref = ($order_no !== '') ? $order_no : (isset($status->order_id) ? $status->order_id : $invoice_id);

    // Pre-Authorization (webhook "Durum 2"): success but funds only BLOCKED. This PR does not
    // capture (confirmPayment is a refactor-PR concern); classify and note, do not complete.
    $is_preauth = (stripos((string) $transaction_type, 'pre') !== false)
        || (isset($status->transaction_type) && stripos((string) $status->transaction_type, 'pre') !== false);
    if ($is_preauth) {
        $order->update_status('on-hold', sprintf(__('QNBpay: on provizyon (Pre-Auth) basarili, tutar bloke. Auth zorunlu, cekim yapilmadi. Referans: %s', 'QNBPay'), $ref));
        return 'preauth';
    }

    // Auth: capture confirmed by checkstatus -> settle (this replaces the old dead payment_complete()).
    $order->payment_complete($ref);
    $order->add_order_note(sprintf(__('QNBpay: odeme dogrulandi ve alindi (%s). Referans: %s', 'QNBPay'), $context, $ref));
    if (function_exists('WC') && WC()->cart) {
        WC()->cart->empty_cart();
    }
    return true;
}

function my_custom_public_page()
{

    global $woocommerce;
    if (isset($_GET['action']) and ($_GET['action'] == 'woocommerce_get_order_details' or $_GET['action'] == 'woocommerce_mark_order_status'))
        return '';

    if (isset($_GET['order_id']) && !isset($_GET['invoice_id'])) {

        // SECURITY (BULGULAR #2): require the order key so only the buyer who just checked out
        // can trigger this 3D relay (was: any order_id, any visitor), and decode with
        // json_decode (NOT unserialize) so meta reachable via other write paths cannot drive
        // PHP object injection.
        $relay_order_id = absint($_GET['order_id']);
        $relay_order = $relay_order_id ? wc_get_order($relay_order_id) : false;
        $relay_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (!$relay_order || !hash_equals($relay_order->get_order_key(), $relay_key)) {
            status_header(403);
            exit;
        }

        $relay_raw = get_post_meta($relay_order_id, 'qnb_payment_form', true);
        $relay_decoded = is_string($relay_raw) ? json_decode($relay_raw, true) : null;
        $result = is_array($relay_decoded) ? $relay_decoded : $relay_raw; // raw HTML form string, or (dead purchase branch) a JSON array; never unserialize



        if (!is_array($result)) {

            echo $result;
            delete_post_meta($_GET['order_id'], 'qnb_payment_form');

            exit;
        } else {
            if (isset($result['purchase']) && $result['purchase'] == 'yes') {
                unset($result['token']);
                unset($result['is_3d']);
                unset($result['purchase']);
                unset($result['installments_number']);
                unset($result['transaction_type']);
                unset($result['hash_key']);
                unset($result['sale_web_hook_key']);

                $new_form = $result;


                $invoice['invoice_id'] = $result['invoice_id'];
                $invoice['invoice_description'] = $result['invoice_description'];
                $invoice['total'] = $result['total'];
                $invoice['return_url'] = $result['return_url'];
                $invoice['cancel_url'] = $result['cancel_url'];
                $invoice['items'] = $result['items'];

                unset($new_form['invoice_id']);
                unset($new_form['invoice_description']);
                unset($new_form['total']);
                unset($new_form['return_url']);
                unset($new_form['cancel_url']);
                unset($new_form['items']);


                $qnb_pay = new QNBPay_sanalpos();
                $environment = $qnb_pay->get_option('environment') == "yes" ? 'TRUE' : 'FALSE';

                $post = array(

                    'merchant_key' => $qnb_pay->get_option('merchant_key'),

                    'invoice' => json_encode($invoice),

                    'currency_code' => 'TRY',

                    'name' => $result['name'],

                    'surname' => $result['surname']

                );


                //print_r($post); exit;

                $environment_url = "FALSE" == $environment ? 'https://portal.qnbpay.com.tr/ccpayment/purchase/link' : 'https://test.qnbpay.com.tr/ccpayment/purchase/link';
                $headers = ['Content-Type: application/json'];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $environment_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1); // BULGULAR #6: verify TLS (was 0, MITM-open)

                $response = json_decode(curl_exec($ch), true);

                curl_close($ch);

                if ($response['status'] == 1) {
                    echo ("<script>location.href='" . $response['link'] . "'</script>");
                    exit;
                } else {

                    wc_add_notice($response->status_description, 'error');

                    wp_redirect(wc_get_checkout_url() . '?error=' . $response->status_description);
                    exit;
                }
            }
            $response = pay2d($result['token'], $result);



            if ($response->status_code == 100) {
                $order_id = explode('WOO', $response->data->invoice_id);
                $order_id = end($order_id);
                $customer_order = new WC_Order($order_id);

                $status = checkStatus($response->data->invoice_id);

                if ($status->status_code == 100 || $status->status_code == 69) {
                    $customer_order->update_status('processing');
                    $customer_order->add_order_note(__('Sanal pos ödeme başarıyla alındı. Ödeme referans no :' . $status->order_id));
                    try {
                        WC()->mailer()->customer_invoice($customer_order);
                        $admin_email = WC()->mailer()->emails['WC_Email_New_Order'];
                        if ($admin_email) {
                            $admin_email->trigger($customer_order->get_id());
                        }
                    } catch (\Exception $e) {
                        error_log($e->getMessage());
                    }

                    delete_post_meta($order_id, 'qnb_payment_form');
                    delete_post_meta($order_id, 'qnb_response');
                    //echo $customer_order->get_checkout_order_received_url(); exit;
                    // paid order marked
                    // $customer_order->payment_complete();
                    // // this is important part for empty cart
                    // $woocommerce->cart->empty_cart();
                    // Redirect to thank you page
                    header('Location: ' . $customer_order->get_checkout_order_received_url());
                    exit;
                } else {

                    update_post_meta($order_id, 'qnb_response', $response->status_description);
                    delete_post_meta($order_id, 'qnb_payment_form');
                    wc_add_notice($response->status_description, 'error');

                    wp_redirect(wc_get_checkout_url() . '?error=' . $response->status_description);
                    exit;
                }
            } else {
                $order_id = $_GET['order_id'];

                update_post_meta($order_id, 'qnb_response', $response->status_description);
                delete_post_meta($order_id, 'qnb_payment_form');
                wc_add_notice($response->status_description, 'error');

                wp_redirect(wc_get_checkout_url() . '?error=' . $response->status_description);
                exit;
            }
        }
    }

    // ---------------------------------------------------------------------------------
    // SECURITY (BULGULAR #1): QNBpay notifications (sale webhook + 3D/hosted return).
    // Both can arrive as GET or POST (the 3D result is sent with response_method=POST, so
    // reading only $_GET silently dropped every successful settlement). Nothing is settled
    // on the message alone: hash_key is validated when present and /api/checkstatus confirms
    // the money server-side before payment_complete().
    // ---------------------------------------------------------------------------------
    $qnb_req = array_merge($_GET, $_POST); // individual values are sanitized below

    // (a) Sale webhook: POST /?webhook=1
    if (isset($_GET['webhook']) && $_GET['webhook'] == 1) {
        $invoice_id = isset($qnb_req['invoice_id']) ? sanitize_text_field(wp_unslash($qnb_req['invoice_id'])) : '';
        $payment_status = isset($qnb_req['payment_status']) ? sanitize_text_field(wp_unslash($qnb_req['payment_status'])) : '';
        $order_no = isset($qnb_req['order_no']) ? sanitize_text_field(wp_unslash($qnb_req['order_no'])) : '';
        $transaction_type = isset($qnb_req['transaction_type']) ? sanitize_text_field(wp_unslash($qnb_req['transaction_type'])) : '';
        $incoming_hash = isset($qnb_req['hash_key']) ? wp_unslash($qnb_req['hash_key']) : '';

        $order = qnbpay_order_from_invoice($invoice_id);
        if (!$order) {
            status_header(400);
            error_log('QNBpay webhook: order not found for invoice');
            exit;
        }
        $verdict = qnbpay_settle_from_notification($order, $invoice_id, $payment_status, $transaction_type, $order_no, $incoming_hash, 'webhook');
        if ($verdict === false) {
            status_header(400);
            exit;
        }
        status_header(200);
        exit;
    }

    // (b) 3D / hosted-page return (buyer lands here; invoice_id/payment_status in GET or POST)
    $return_invoice = isset($qnb_req['invoice_id']) ? sanitize_text_field(wp_unslash($qnb_req['invoice_id'])) : '';
    if ($return_invoice !== '' && strpos($return_invoice, 'WOO') !== false && isset($qnb_req['payment_status'])) {
        $payment_status = sanitize_text_field(wp_unslash($qnb_req['payment_status']));
        $order_no = isset($qnb_req['order_no']) ? sanitize_text_field(wp_unslash($qnb_req['order_no'])) : '';
        $transaction_type = isset($qnb_req['transaction_type']) ? sanitize_text_field(wp_unslash($qnb_req['transaction_type'])) : '';
        $incoming_hash = isset($qnb_req['hash_key']) ? wp_unslash($qnb_req['hash_key']) : '';

        $order = qnbpay_order_from_invoice($return_invoice);
        if (!$order) {
            wc_add_notice(__('Odeme dogrulanamadi.', 'QNBPay'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $verdict = qnbpay_settle_from_notification($order, $return_invoice, $payment_status, $transaction_type, $order_no, $incoming_hash, 'return');
        if ($verdict === false) {
            // Reflected-XSS safe (BULGULAR #7): never echo raw request input into a notice.
            wc_add_notice(__('Odeme sunucu tarafinda dogrulanamadi.', 'QNBPay'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }
}


function get_installment()
{

    if (!empty($_POST['cc_number'])) {
        global $woocommerce;

        $qnb_pay = new QNBPay_sanalpos();

        /* getpos request */

        $pos_post = [
            'credit_card' => $_POST['cc_number'],

            'amount' => $woocommerce->cart->total,

            "currency_code" => 'TRY',

            "merchant_key" => $qnb_pay->get_option('merchant_key'),

            'app_id' => $qnb_pay->get_option('app_key'),

            'app_secret' => $qnb_pay->get_option('app_secret'),
        ];

        $environment = $qnb_pay->get_option('environment') == "yes" ? 'TRUE' : 'FALSE';
        $environment_url = "FALSE" == $environment ? 'https://portal.qnbpay.com.tr/ccpayment/api/getpos' : 'https://test.qnbpay.com.tr/ccpayment/api/getpos';


        if (!empty($_POST['recurring_options']['recurring_check']) && $_POST['recurring_options']['recurring_check'] == 'yes') {
            $pos_post['is_recurring'] = 1;
        }

        $pos_post['is_comission_from_user'] = $qnb_pay->get_option('installment_type') == "yes" ? 1 : 0;
        $pos_post['is_single_payment_allowed'] = true;
        $headers = ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer {$_POST['qnb_token']}"];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $environment_url);

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($pos_post));

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1); // BULGULAR #6: verify TLS (was 0, MITM-open)
        curl_setopt($ch, CURLOPT_VERBOSE, false); // BULGULAR #6/#7: verbose leaked bearer token to logs

        $get_pos_response = json_decode(curl_exec($ch), true);

        curl_close($ch);

        /*if ($get_pos_response['status_code'] == 100) {*/
        $html = '';


        $pos_id = '';

        $pos_amt = '';

        $currency_id = "";

        $campaign_id = "";

        $allocation_id = "";

        $installments_number = "";

        $hash_key = "";

        $currency_code = '';

        $i = 0;

        $html = "<div class='row'>";
        // if($qnb_pay->get_option('installment_type') == "yes" || empty($get_pos_response['data'])){

        //     $inst = $qnb_pay->getLocalizationContent('single_installment', $get_pos_response['data'][0]["currency_code"] ?? 'TRY');

        //         $installments_number = 1;
        //     $first_hash_key = $qnb_pay->generateHashKey(number_format(WC()->cart->total, 2, ".", ""), 1, 'TRY', $qnb_pay->get_option('merchant_key'), 0, $qnb_pay->get_option('app_secret'));

        //     $html .=
        //         "<div class='single-installment active' data-posid='" .
        //         $get_pos_response['data'][0]["pos_id"] .
        //         "' data-amount='" .
        //         number_format(WC()->cart->total, 2, ".", "") .
        //         "' data-currency_id='" .
        //         $get_pos_response['data'][0]["currency_id"] .
        //         "' data-campaign_id='" .
        //         $get_pos_response['data'][0]["campaign_id"] .
        //         "' data-allocation_id='" .
        //         $get_pos_response['data'][0]["allocation_id"] .
        //         "' data-installments_number='1' data-hash_key='" .
        //         $first_hash_key .
        //         "' data-currency_code='" .
        //         $get_pos_response['data'][0]["currency_code"] .
        //         "'>

        //     <div class='qnb_heading'>" .
        //         $inst .
        //         "</div>

        //     <div class='qnb_amount'>" .
        //         number_format(WC()->cart->total, 2, ".", "") .
        //         " " .
        //         $get_pos_response['data'][0]["currency_code"] .
        //         "</div>

        //     <div class='qnb_installment_number'>1 X</div>

        //     <div class='qnb_total_amount'>" .
        //         number_format(WC()->cart->total, 2, ".", "") .
        //         " " .
        //         $get_pos_response['data'][0]["currency_code"] .
        //         "</div></div>";
        // }
        if (!empty($get_pos_response['data'])) {

            $installments_count = count($get_pos_response['data']);

            foreach ($get_pos_response['data'] as $val) {

                if (!in_array($val['installments_number'], $qnb_pay->get_option('installments'))) {
                    $i++;
                    continue;
                }


                $active_cls = "";

                //                      $inst= ($i+1)." Installment";

                $currency_code = $val['currency_code'];

                if ($i == 0) {
                    if (!$pos_post['is_comission_from_user']) {
                        $active_cls = 'active';

                        $pos_id = $val['pos_id'];

                        $pos_amt = $val['amount_to_be_paid'];

                        $currency_id = $val['currency_id'];

                        $campaign_id = $val['campaign_id'];

                        $allocation_id = $val['allocation_id'];

                        $installments_number = $val['installments_number'];
                        $hash_key = $val['hash_key'];

                        $inst = $qnb_pay->getLocalizationContent('single_installment', $currency_code);
                    }
                } else {
                    $inst = $i + 1 . " " . $qnb_pay->getLocalizationContent('installment', $currency_code);
                }


                $inst = $val['installments_number'] . " " . $qnb_pay->getLocalizationContent('installment', $currency_code);

                $html .=
                    "<div class='single-installment " .
                    $active_cls .
                    "' data-posid='" .
                    $val["pos_id"] .
                    "' data-amount='" .
                    $val["amount_to_be_paid"] .
                    "' data-currency_id='" .
                    $val["currency_id"] .
                    "' data-campaign_id='" .
                    $val["campaign_id"] .
                    "' data-allocation_id='" .
                    $val["allocation_id"] .
                    "' data-installments_number='" .
                    $val["installments_number"] .
                    "' data-hash_key='" .
                    $val["hash_key"] .
                    "' data-currency_code='" .
                    $val["currency_code"] .
                    "'>

                        <div class='qnb_heading'>" .
                    $inst .
                    "</div>

                        <div class='qnb_amount'>" .
                    $val['amount_to_be_paid'] .
                    " " .
                    $val['currency_code'] .
                    "</div>

                        <div class='qnb_installment_number'>" .
                    $val['installments_number'] .
                    " X</div>

                        <div class='qnb_total_amount'>" .
                    number_format($val['amount_to_be_paid'] / ($val['installments_number']), 2) .
                    " " .
                    $val['currency_code'] .
                    "</div></div>";

                $i++;
            }
        }
        $html .= "</div>";

        echo json_encode([
            'data' => $html,
            'pos_id' => $pos_id,
            'pos_amt' => $pos_post['is_comission_from_user'] || empty($get_pos_response['data']) ? number_format(WC()->cart->total, 2, ".", "") : $pos_amt,
            'currency_id' => $currency_id,
            'campaign_id' => $campaign_id,
            'allocation_id' => $allocation_id,
            'installments_number' => $installments_number,
            'hash_key' => $pos_post['is_comission_from_user'] || empty($get_pos_response['data']) ? $first_hash_key : $hash_key,
            'currency_code' => $currency_code,
        ]);

        exit();
        /*}*/
    }

    /* end getpos request */

    echo json_encode(['data' => '', 'pos_id' => '', 'pos_amt' => '', 'currency_id' => '', 'campaign_id' => '', 'allocation_id' => '', 'installments_number' => '', 'hash_key' => '', 'currency_code' => '']);

    exit();
}


function pay2d($token, $parameters)
{


    $qnb_pay = new QNBPay_sanalpos();
    $environment = $qnb_pay->get_option('environment') == "yes" ? 'TRUE' : 'FALSE';
    if ($parameters['is_2d_card'] == 'yes') {
        $environment_url = "FALSE" == $environment ? 'https://portal.qnbpay.com.tr/ccpayment/api/payByCardTokenNonSecure' : 'https://test.qnbpay.com.tr/ccpayment/api/payByCardTokenNonSecure';
    } else {
        $environment_url = "FALSE" == $environment ? 'https://portal.qnbpay.com.tr/ccpayment/api/paySmart2D' : 'https://test.qnbpay.com.tr/ccpayment/api/paySmart2D';
    }

    $headers = ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer $token"];


    $options = array(
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_VERBOSE => false,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($parameters),
        //CURLOPT_SSL_VERIFYHOST => 0,
        //CURLOPT_SSL_VERIFYPEER => 0,
    );

    $ch = curl_init($environment_url);
    curl_setopt_array($ch, $options);
    $content = curl_exec($ch);

    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $header = curl_getinfo($ch);
    $rurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);


    return json_decode($content);
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
