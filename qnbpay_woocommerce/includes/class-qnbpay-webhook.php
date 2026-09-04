<?php
/**
 * QNBPay_Webhook: return + webhook settlement layer (third of the three layers:
 * QNBPay_Api, gateway, webhook).
 *
 * The sale webhook (POST /?webhook=1) and the hosted/3D return both land here. Nothing
 * is settled on the notification alone: an incoming hash_key is validated
 * and /api/checkstatus confirms the money server-side (via QNBPay_Api) before
 * payment_complete(). Reads GET and POST (QNB returns with response_method=POST).
 */
if (!defined('ABSPATH')) {
    exit;
}

class QNBPay_Webhook
{
    public static function init()
    {
        add_action('init', array(__CLASS__, 'handle'));
    }

    /** Resolve the order from an invoice_id of the form "<rand>WOO<order_id>" (HPOS-safe). */
    public static function order_from_invoice($invoice_id)
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
     * Validate a QNBpay hash_key (AES-256-CBC "iv:salt:base64", '/' as '__'). Documented
     * inverse of the payment hash; generation is unchanged. Returns
     * [status, total, invoice_id, order_id, currency_code].
     */
    public static function validate_hash_key($hash_key, $app_secret)
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
     * /api/checkstatus confirmation. Returns the response object when the invoice is
     * confirmed paid AND its amount/currency match the order, false otherwise.
     */
    public static function checkstatus_paid($invoice_id, $order)
    {
        $status = (new QNBPay_Api())->check_status($invoice_id);
        if (!is_object($status)) {
            return false;
        }
        $txn_status = isset($status->transaction_status) ? (string) $status->transaction_status : '';
        $ok_code = isset($status->status_code) && ((string) $status->status_code === '100');
        $ok_txn = (strcasecmp($txn_status, 'Completed') === 0);
        if (!$ok_code && !$ok_txn) {
            return false;
        }
        $amount = null;
        foreach (array('transaction_amount', 'product_price', 'total') as $k) {
            if (isset($status->$k) && is_numeric($status->$k)) {
                $amount = (float) $status->$k;
                break;
            }
        }
        if ($amount !== null && abs($amount - (float) $order->get_total()) > 0.01) {
            qnbpay_log('checkstatus: amount mismatch for order ' . $order->get_id(), 'warning');
            return false;
        }
        return $status;
    }

    /**
     * Verify a notification and settle only when the server confirms payment.
     * Returns true (settled), 'preauth' (blocked, not captured) or false (rejected).
     */
    public static function settle($order, $invoice_id, $payment_status, $transaction_type, $order_no, $incoming_hash, $context)
    {
        $api = new QNBPay_Api();
        $app_secret = $api->option('app_secret');

        if ((string) $payment_status !== '1') {
            if (!self::checkstatus_paid($invoice_id, $order)) {
                $order->update_status('failed', __('QNBpay: işlem başarısız (doğrulandı).', 'QNBPay'));
            }
            return true;
        }

        if (is_string($incoming_hash) && $incoming_hash !== '') {
            list($h_status, $h_total, $h_invoice, $h_order, $h_currency) = self::validate_hash_key($incoming_hash, $app_secret);
            $invoice_ok = ($h_invoice === $invoice_id);
            $total_ok = ($h_total === '') ? true : (abs((float) $h_total - (float) $order->get_total()) <= 0.01);
            $currency_ok = ($h_currency === '') ? true : (strcasecmp($h_currency, $order->get_currency()) === 0);
            if (!$invoice_ok || !$total_ok || !$currency_ok) {
                qnbpay_log($context . ': hash_key mismatch for order ' . $order->get_id(), 'warning');
                return false;
            }
        }

        $status = self::checkstatus_paid($invoice_id, $order);
        if ($status === false) {
            qnbpay_log($context . ': checkstatus did not confirm order ' . $order->get_id(), 'warning');
            return false;
        }

        if ($order->is_paid()) {
            return true;
        }

        $ref = ($order_no !== '') ? $order_no : (isset($status->order_id) ? $status->order_id : $invoice_id);

        $is_preauth = (stripos((string) $transaction_type, 'pre') !== false)
            || (isset($status->transaction_type) && stripos((string) $status->transaction_type, 'pre') !== false);
        if ($is_preauth) {
            // Capture the pre-authorised amount, then settle.
            $cap = $api->confirm_payment($invoice_id, 1, number_format((float) $order->get_total(), 2, '.', ''));
            $cap_ok = is_object($cap) && isset($cap->status_code) && in_array((string) $cap->status_code, array('100', '101'), true);
            if (!$cap_ok) {
                $order->update_status('on-hold', sprintf(__('QNBpay: ön provizyon başarılı ama confirmPayment ile çekim yapılamadı. Referans: %s', 'QNBPay'), $ref));
                return 'preauth';
            }
            $order->payment_complete($ref);
            $order->add_order_note(sprintf(__('QNBpay: ön provizyon confirmPayment ile çekildi (%s). Referans: %s', 'QNBPay'), $context, $ref));
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            return true;
        }

        $order->payment_complete($ref);
        $order->add_order_note(sprintf(__('QNBpay: ödeme doğrulandı ve alındı (%s). Referans: %s', 'QNBPay'), $context, $ref));
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        return true;
    }

    /** init handler for the sale webhook and the hosted/3D return. */
    public static function handle()
    {
        $qnb_req = array_merge($_GET, $_POST); // values sanitized individually below

        // (a) Sale webhook: POST /?webhook=1
        if (isset($_GET['webhook']) && $_GET['webhook'] == 1) {
            $invoice_id = isset($qnb_req['invoice_id']) ? sanitize_text_field(wp_unslash($qnb_req['invoice_id'])) : '';
            $payment_status = isset($qnb_req['payment_status']) ? sanitize_text_field(wp_unslash($qnb_req['payment_status'])) : '';
            $order_no = isset($qnb_req['order_no']) ? sanitize_text_field(wp_unslash($qnb_req['order_no'])) : '';
            $transaction_type = isset($qnb_req['transaction_type']) ? sanitize_text_field(wp_unslash($qnb_req['transaction_type'])) : '';
            $incoming_hash = isset($qnb_req['hash_key']) ? wp_unslash($qnb_req['hash_key']) : '';

            $order = self::order_from_invoice($invoice_id);
            if (!$order) {
                status_header(400);
                qnbpay_log('webhook: order not found for invoice', 'warning');
                exit;
            }
            $verdict = self::settle($order, $invoice_id, $payment_status, $transaction_type, $order_no, $incoming_hash, 'webhook');
            if ($verdict === false) {
                status_header(400);
                exit;
            }
            status_header(200);
            exit;
        }

        // (b) Hosted / 3D return (buyer lands here; invoice_id/payment_status in GET or POST)
        $return_invoice = isset($qnb_req['invoice_id']) ? sanitize_text_field(wp_unslash($qnb_req['invoice_id'])) : '';
        if ($return_invoice !== '' && strpos($return_invoice, 'WOO') !== false && isset($qnb_req['payment_status'])) {
            $payment_status = sanitize_text_field(wp_unslash($qnb_req['payment_status']));
            $order_no = isset($qnb_req['order_no']) ? sanitize_text_field(wp_unslash($qnb_req['order_no'])) : '';
            $transaction_type = isset($qnb_req['transaction_type']) ? sanitize_text_field(wp_unslash($qnb_req['transaction_type'])) : '';
            $incoming_hash = isset($qnb_req['hash_key']) ? wp_unslash($qnb_req['hash_key']) : '';

            $order = self::order_from_invoice($return_invoice);
            if (!$order) {
                wc_add_notice(__('Ödeme doğrulanamadı.', 'QNBPay'), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }
            $verdict = self::settle($order, $return_invoice, $payment_status, $transaction_type, $order_no, $incoming_hash, 'return');
            if ($verdict === false) {
                wc_add_notice(__('Ödeme sunucu tarafında doğrulanamadı.', 'QNBPay'), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }
    }
}
