<?php
/**
 * QNBPay_Blocks — Cart/Checkout Blocks payment method integration.
 *
 * Registers the gateway for the Checkout block so it is selectable there (it was invisible
 * before: the block showed "no payment methods available"). No card fields are rendered;
 * the server's hosted process_payment() drives the redirect for both classic and blocks.
 */
if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class QNBPay_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'QNBPay_sanalpos';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_QNBPay_sanalpos_settings', array());
    }

    public function is_active()
    {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'qnbpay-blocks',
            plugins_url('assets/js/blocks.js', dirname(__FILE__)),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities'),
            '1.0.0',
            true
        );
        return array('qnbpay-blocks');
    }

    public function get_payment_method_data()
    {
        return array(
            'title'       => isset($this->settings['title']) ? $this->settings['title'] : 'QNBPay',
            'description' => isset($this->settings['description']) ? $this->settings['description'] : '',
            'supports'    => array('products'),
        );
    }
}
