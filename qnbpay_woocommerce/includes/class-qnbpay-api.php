<?php
/**
 * QNBPay_Api: QNB (Sipay) REST client layer.
 *
 * This class owns the QNB API logic (endpoints,
 * order, hash generation, field names, is_3d modes). The logic is preserved from
 * the original plugin; it is only reorganized here so the gateway and webhook
 * layers never talk to curl directly. The bearer token is fetched and cached
 * SERVER-SIDE and is never emitted to the browser.
 */
if (!defined('ABSPATH')) {
    exit;
}

class QNBPay_Api
{
    /** @var array gateway settings (woocommerce_QNBPay_sanalpos_settings) */
    private $settings;

    /** @var string last transport/HTTP error, for on-screen diagnostics */
    public $last_error = '';

    public function __construct($settings = null)
    {
        $this->settings = is_array($settings)
            ? $settings
            : (array) get_option('woocommerce_QNBPay_sanalpos_settings', array());
    }

    public function option($key, $default = '')
    {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    public function is_sandbox()
    {
        return $this->option('environment') === 'yes';
    }

    public function base_url()
    {
        return $this->is_sandbox()
            ? 'https://test.qnbpay.com.tr/ccpayment'
            : 'https://portal.qnbpay.com.tr/ccpayment';
    }

    /**
     * Bearer token, cached server-side (valid ~2h at QNB; cached under that).
     * The token is never printed to HTML or sent to JS.
     */
    public function get_token()
    {
        $cache_key = 'qnbpay_token_' . md5($this->option('app_key') . '|' . ($this->is_sandbox() ? 'test' : 'live'));
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $resp = $this->post('/api/token', array(
            'app_id'     => $this->option('app_key'),
            'app_secret' => $this->option('app_secret'),
        ));
        if (is_object($resp) && isset($resp->data->token) && $resp->data->token !== '') {
            set_transient($cache_key, $resp->data->token, 90 * MINUTE_IN_SECONDS);
            return $resp->data->token;
        }
        return '';
    }

    /**
     * POST JSON to a QNB endpoint via wp_remote_post (TLS verified; ).
     * Pass $authorize=true to attach the cached bearer token. Never send Accept/
     * Content-Type twice (the gateway WAF rejected duplicates).
     */
    public function post($path, array $body, $authorize = false)
    {
        $headers = array('Accept' => 'application/json', 'Content-Type' => 'application/json');
        if ($authorize) {
            $token = $this->get_token();
            if ($token === '') {
                if ($this->last_error === '') { $this->last_error = 'Token alinamadi (kimlik bilgileri ya da QNB erisimi).'; }
                return null;
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $res = wp_remote_post($this->base_url() . $path, array(
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ));
        if (is_wp_error($res)) {
            $this->last_error = $res->get_error_message();
            error_log('QNBpay API ' . $path . ': ' . $res->get_error_message());
            return null;
        }
        return json_decode(wp_remote_retrieve_body($res));
    }

    /**
     * Create a hosted-page payment and return the response
     * ({link, order_id, status_code}). Card data never touches our server (PCI SAQ A);
     * the buyer enters the card, picks installments and sees the amount on QNB's page.
     * $invoice_id must be unique and encode the order as "<rand>WOO<order_id>".
     */
    public function purchase_link(WC_Order $order, $invoice_id, $return_url, $cancel_url)
    {
        $total = number_format((float) $order->get_total(), 2, '.', '');
        $items = array();
        foreach ($order->get_items() as $item) {
            $qty = max(1, (int) $item->get_quantity());
            // per-unit price so sum(price*qty) == order total (QNB status_code 13 otherwise)
            $unit = number_format(((float) $item->get_total()) / $qty, 2, '.', '');
            $items[] = array(
                'name'        => $item->get_name(),
                'price'       => $unit,
                'quantity'    => $qty,
                'description' => $item->get_name(),
            );
        }
        // reconcile any rounding drift (shipping, fees, coupons) into a single line so the
        // items sum matches the order total exactly.
        $sum = 0.0;
        foreach ($items as $it) { $sum += (float) $it['price'] * (int) $it['quantity']; }
        $diff = round((float) $total - $sum, 2);
        if (abs($diff) >= 0.01) {
            $items[] = array(
                'name'        => __('Diger (kargo/vergi/indirim)', 'QNBPay'),
                'price'       => number_format($diff, 2, '.', ''),
                'quantity'    => 1,
                'description' => __('Toplam duzeltme', 'QNBPay'),
            );
        }
        if (empty($items)) {
            $items[] = array('name' => 'Order', 'price' => $total, 'quantity' => 1, 'description' => 'Order');
        }

        $body = array(
            'merchant_key'  => $this->option('merchant_key'),
            'currency_code' => $order->get_currency(),
            'invoice'       => array(
                'invoice_id'          => $invoice_id,
                'invoice_description' => sprintf(__('Siparis #%s', 'QNBPay'), $order->get_order_number()),
                'total'               => $total,
                'return_url'          => $return_url,
                'cancel_url'          => $cancel_url,
                'response_method'     => 'POST',
                'items'               => $items,
            ),
            'name'          => $order->get_billing_first_name(),
            'surname'       => $order->get_billing_last_name(),
        );
        $txn = $this->option('transaction_type');
        if ($txn !== '') {
            $body['invoice']['transaction_type'] = $txn; // Auth / PreAuth
        }
        $webhook = $this->option('sale_webhook_key');
        if ($webhook !== '') {
            $body['invoice']['sale_web_hook_key'] = $webhook;
        }
        return $this->post('/purchase/link', $body, true);
    }

    /**
     * AES-256-CBC hash bundle used by QNB (same scheme as the payment hash, preserved
     * from the plugin). Plaintext $data is the pipe-joined field list per endpoint.
     */
    public function generate_hash($data)
    {
        $iv = substr(sha1((string) mt_rand()), 0, 16);
        $salt = substr(sha1((string) mt_rand()), 0, 4);
        $salt_with_password = hash('sha256', sha1($this->option('app_secret')) . $salt);
        $encrypted = openssl_encrypt((string) $data, 'aes-256-cbc', $salt_with_password, 0, $iv);
        return str_replace('/', '__', "$iv:$salt:$encrypted");
    }

    /**
     * Capture or cancel a pre-authorised payment. status 1 = capture,
     * 2 = cancel. Payload hash is "merchant_key|invoice_id|status". A PreAuth left
     * uncaptured is released by QNB after 20 days.
     */
    public function confirm_payment($invoice_id, $status, $total = null)
    {
        $hash = $this->generate_hash($this->option('merchant_key') . '|' . $invoice_id . '|' . $status);
        $body = array(
            'invoice_id'   => $invoice_id,
            'merchant_key' => $this->option('merchant_key'),
            'status'       => (int) $status,
            'hash_key'     => $hash,
        );
        if ($total !== null) {
            $body['total'] = $total;
        }
        return $this->post('/api/confirmPayment', $body, true);
    }

    /**
     * Transaction status from QNB, the authority for
     * settlement. Payload hash is "invoice_id|merchant_key"; include_pending_status is a
     * JSON boolean; post() sends the body once and the auth/accept headers once (the three
     * bugs that made the old checkStatus() return HTTP 400).
     */
    public function check_status($invoice_id)
    {
        $hash = $this->generate_hash($invoice_id . '|' . $this->option('merchant_key'));
        return $this->post('/api/checkstatus', array(
            'invoice_id'             => $invoice_id,
            'merchant_key'           => $this->option('merchant_key'),
            'hash_key'               => $hash,
            'include_pending_status' => true,
        ), true);
    }

    /**
     * Diagnostic: try a token call and report whether QNB is reachable and what the
     * merchant account allows (is_3d). Used by the settings-page connection notice.
     * Returns array{ok:bool, message:string, is_3d?:mixed}.
     */
    public function test_connection()
    {
        $this->last_error = '';
        $resp = $this->post('/api/token', array(
            'app_id'     => $this->option('app_key'),
            'app_secret' => $this->option('app_secret'),
        ));
        if (!is_object($resp)) {
            return array('ok' => false, 'message' => $this->last_error !== '' ? $this->last_error : 'QNB sunucusuna baglanilamadi.');
        }
        $code = isset($resp->status_code) ? (string) $resp->status_code : '';
        if ($code !== '100' || !isset($resp->data->token)) {
            $desc = isset($resp->status_description) ? $resp->status_description : ('status_code ' . $code);
            return array('ok' => false, 'message' => 'QNB kimlik/token reddetti: ' . $desc);
        }
        $is3d = isset($resp->data->is_3d) ? $resp->data->is_3d : null;

        // Probe the actual operation that fails at checkout: a throwaway purchase/link.
        $this->last_error = '';
        $probe = $this->post('/purchase/link', array(
            'merchant_key'  => $this->option('merchant_key'),
            'currency_code' => 'TRY',
            'invoice'       => array(
                'invoice_id'          => 'PROBE' . time() . 'WOO0',
                'invoice_description' => 'baglanti testi',
                'total'               => '1.00',
                'return_url'          => home_url('/'),
                'cancel_url'          => home_url('/'),
                'response_method'     => 'POST',
                'items'               => array(array('name' => 'test', 'price' => '1.00', 'quantity' => 1, 'description' => 'test')),
            ),
            'name'          => 'Test',
            'surname'       => 'Baglanti',
        ), true);
        if (!is_object($probe)) {
            return array('ok' => false, 'is_3d' => $is3d, 'message' => 'Token alindi ama purchase/link yanit vermedi: ' . ($this->last_error !== '' ? $this->last_error : 'bilinmeyen'));
        }
        $pcode = isset($probe->status_code) ? (string) $probe->status_code : '';
        if ($pcode !== '100' || empty($probe->link)) {
            $pdesc = isset($probe->status_description) ? $probe->status_description : ('status_code ' . $pcode);
            return array('ok' => false, 'is_3d' => $is3d, 'message' => 'purchase/link reddedildi: ' . $pdesc . ' (kod ' . $pcode . ')');
        }
        return array('ok' => true, 'is_3d' => $is3d, 'message' => 'baglanti ve purchase/link OK');
    }
}
