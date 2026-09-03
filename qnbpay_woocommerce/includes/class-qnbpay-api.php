<?php
/**
 * QNBPay_Api — QNB (Sipay) REST client layer.
 *
 * REFACTOR (three-layer separation): this owns the QNB API logic (endpoints,
 * order, hash generation, field names, is_3d modes). The logic is preserved from
 * the original plugin; it is only reorganized here so the gateway and webhook
 * layers never talk to curl directly. The bearer token is fetched and cached
 * SERVER-SIDE and is never emitted to the browser (BULGULAR #4).
 */
if (!defined('ABSPATH')) {
    exit;
}

class QNBPay_Api
{
    /** @var array gateway settings (woocommerce_QNBPay_sanalpos_settings) */
    private $settings;

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
     * BULGULAR #4: the token is never printed to HTML or sent to JS.
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
     * POST JSON to a QNB endpoint via wp_remote_post (TLS verified; BULGULAR #6).
     * Pass $authorize=true to attach the cached bearer token. Never send Accept/
     * Content-Type twice (BULGULAR #11: the gateway WAF rejected duplicates).
     */
    public function post($path, array $body, $authorize = false)
    {
        $headers = array('Accept' => 'application/json', 'Content-Type' => 'application/json');
        if ($authorize) {
            $token = $this->get_token();
            if ($token === '') {
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
            error_log('QNBpay API ' . $path . ': ' . $res->get_error_message());
            return null;
        }
        return json_decode(wp_remote_retrieve_body($res));
    }
}
