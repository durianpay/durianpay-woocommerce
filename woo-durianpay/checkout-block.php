<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Durianpay_Blocks extends AbstractPaymentMethodType 
{
    protected $name = 'durianpay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_durianpay_settings', []);
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'durianpay-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout_block.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        if (function_exists('wp_set_script_translations')) 
        {
            wp_set_script_translations('durianpay-blocks-integration');
        }

        return ['durianpay-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => 'Pay by Durianpay',
            'description' => $this->settings['description'],
        ]; 
    }
}
