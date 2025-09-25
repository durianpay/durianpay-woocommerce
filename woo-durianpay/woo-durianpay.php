<?php
/*
 * Plugin Name: Durianpay for WooCommerce
 * Plugin URI: https://durianpay.id/plugins/woocommerce
 * Description: Durianpay Payment Gateway Integration for WooCommerce
 * Version: 2.0.0
 * Stable tag: 2.0.0
 * Author: Team Durianpay
 * WC tested up to: 9.1.2
 * Author URI: https://durianpay.id
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

require_once __DIR__.'/includes/durianpay-webhook.php';
require_once __DIR__.'/durianpay-sdk/Durianpay.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Durianpay\Api\Api;
use Durianpay\Api\Errors;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

add_action('plugins_loaded', 'woocommerce_durianpay_init', 0);
add_action('admin_post_nopriv_dp_wc_webhook', 'durianpay_webhook_init', 10);
add_action('before_woocommerce_init', function() {
	if (class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class))
    {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil'))
    {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('woocommerce_blocks_loaded', 'durianpay_woocommerce_block_support');

function durianpay_woocommerce_block_support()
{
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType'))
    {
        require_once dirname( __FILE__ ) . '/checkout-block.php';

        add_action(
          'woocommerce_blocks_payment_method_type_registration',
          function(PaymentMethodRegistry $payment_method_registry) {
            $container = Automattic\WooCommerce\Blocks\Package::container();
            $container->register(
                WC_Durianpay_Blocks::class,
                function() {
                    return new WC_Durianpay_Blocks();
                }
            );
            $payment_method_registry->register($container->get(WC_Durianpay_Blocks::class));
          },
          5
        );
    }
}

function woocommerce_durianpay_init()
{
    if (!class_exists('WC_Payment_Gateway'))
    {
        return;
    }

    class WC_Durianpay extends WC_Payment_Gateway
    {
         // This one stores the WooCommerce Order Id
         const SESSION_KEY                     = 'durianpay_wc_order_id';
         const DURIANPAY_PAYMENT_ID            = 'durianpay_payment_id';
         const DURIANPAY_PAYMENT_SUCCESS       = 'durianpay_payment_success';
         const DURIANPAY_ORDER_ID              = 'durianpay_order_id';
         const DURIANPAY_SIGNATURE             = 'durianpay_signature';
         const DURIANPAY_WC_FORM_SUBMIT        = 'durianpay_wc_form_submit';

         const IDR                            = 'IDR';
         const WC_ORDER_ID                    = 'woocommerce_order_id';

         const DEFAULT_LABEL                  = 'Payment With Durianpay';
         const DEFAULT_DESCRIPTION            = 'Pay securely by Credit or Debit card or Internet Banking through Durianpay.';
         const DEFAULT_SUCCESS_MESSAGE        = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';
         const DEFAULT_EXPIRED                = '72:00:00';

         protected $visibleSettings = array(
            'enabled',
            'title',
            'description',
            'key_secret',
            'order_success_message',
            'enable_webhook',
            'webhook_events',
            'expired_order',
            'enable_payment_notification',
        );

        public $form_fields = array();

        public $supports = array(
            'products',
            'refunds'
        );

        /**
         * Can be set to true if you want payment fields
         * to show on the checkout (if doing a direct integration).
         * @var boolean
         */
        public $has_fields = false;

        /**
         * Unique ID for the gateway
         * @var string
         */
        public $id = 'durianpay';

        /**
         * Title of the payment method shown on the admin page.
         * @var string
         */
        public $method_title = 'Durianpay';


        /**
         * Description of the payment method shown on the admin page.
         * @var  string
         */
        public $method_description = 'Allow customers to securely pay via Durianpay (Credit/Debit Cards, NetBanking, UPI, Wallets)';

        /**
         * Icon URL, set in constructor
         * @var string
         */
        public $icon;

        /**
         * TODO: Remove usage of $this->msg
         */
        protected $msg = array(
            'message'   =>  '',
            'class'     =>  '',
        );

        /**
         * Return Wordpress plugin settings
         * @param  string $key setting key
         * @return mixed setting value
         */
        public function getSetting($key)
        {
            return $this->get_option($key);
        }

        public function getCustomOrdercreationMessage($thank_you_title, $order)
        {
            $message =  $this->getSetting('order_success_message');
            if (isset($message) === false)
            {
                $message = static::DEFAULT_SUCCESS_MESSAGE;
            }
            return $message;
        }

        /**
         * @param boolean $hooks Whether or not to
         *                       setup the hooks on
         *                       calling the constructor
         */
        public function __construct($hooks = true)
        {
            $this->icon =  "http://durianpay.id/assets/img/DurianPay.svg";

            $this->init_form_fields();
            $this->init_settings();

            // TODO: This is hacky, find a better way to do this
            // See mergeSettingsWithParentPlugin() in subscriptions for more details.
            if ($hooks)
            {
                $this->initHooks();
            }

            $this->title = $this->getSetting('title');
        }

        public function enqueue_checkout_js_script_on_checkout()
        {
            $secret = $this->getSetting('key_secret');
            $jsUrl = "https://js.durianpay.id/0.1.52/durianpay.min.js";
            if (strpos($secret, 'dp_test') === 0) {
                $jsUrl = "https://js.durianpay.id/sandbox/0.1.55/durianpay.min.js";
            }

            if (is_checkout()) 
            {
                wp_enqueue_script(
                    'durianpay-checkout-js',
                    $jsUrl,
                    [],
                    null,
                    false // Load script in footer
                );
            }
        }

        public function add_defer_to_checkout_js($tag, $handle, $src)
        {
            if ('durianpay-checkout-js' === $handle)
            {
                return '<script src="' . esc_url($src) . '" defer="defer"></script>' . "\n";
            }

            return $tag;
        }
        

        protected function initHooks()
        {
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            add_action('woocommerce_api_' . $this->id, array($this, 'check_durianpay_response'));

            $cb = array($this, 'process_admin_options');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", $cb);
                add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'autoEnableWebhook'));            
            }
            else
            {
                add_action('woocommerce_update_options_payment_gateways', $cb);
                add_action( "woocommerce_update_options_payment_gateways", array($this, 'autoEnableWebhook'));
            }

            add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_js_script_on_checkout'));

            add_filter('script_loader_tag', array($this, 'add_defer_to_checkout_js'), 10, 3);

            add_filter( 'woocommerce_thankyou_order_received_text', array($this, 'getCustomOrdercreationMessage'), 20, 2 );
        }

        public function init_form_fields()
        {
            $webhookUrl = esc_url(admin_url('admin-post.php')) . '?action=dp_wc_webhook';

            $defaultFormFields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', $this->id),
                    'type' => 'checkbox',
                    'label' => __('Enable this module?', $this->id),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', $this->id),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', $this->id),
                    'default' => __(static::DEFAULT_LABEL, $this->id)
                ),
                'description' => array(
                    'title' => __('Description', $this->id),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', $this->id),
                    'default' => __(static::DEFAULT_DESCRIPTION, $this->id)
                ),
                'key_secret' => array(
                    'title' => __('Key Secret', $this->id),
                    'type' => 'text',
                    'description' => __('The key secret can be generated from "API Keys" section of Durianpay Dashboard "Settings" tab. Use test or live for test or live mode.', $this->id)
                ),
                'order_success_message' => array(
                    'title' => __('Order Completion Message', $this->id),
                    'type'  => 'textarea',
                    'description' =>  __('Message to be displayed after a successful order', $this->id),
                    'default' =>  __(STATIC::DEFAULT_SUCCESS_MESSAGE, $this->id),
                ),
                'enable_payment_notification' => array(
                    'title' => __('Enable Payment Notification', $this->id),
                    'type' => 'checkbox',
                    'description' =>  "<span>Payment Notification</span>",
                    'label' => __('Enable Payment Notification', $this->id),
                    'default' => 'no'
                ),
                'enable_webhook' => array(
                    'title' => __('Enable Webhook', $this->id),
                    'type' => 'checkbox',
                    'description' =>  "<span>$webhookUrl</span>",
                    'label' => __('Enable Durianpay Webhook', $this->id),
                    'default' => 'no'
                ),
                'expired_order' => array(
                    'title' => __('Order Expiry Timelime', $this->id),
                    'type'=> 'text',
                    'description' => __('This determines when the order will expiry. Please put it in the format `hours:minutes:seconds`. Keep in mind that hours can have a value of more than 24, meaning you can add 1 or more days for an expiry date, minutes can have a value of more than 60, meaning you can add 1 or more hours for an expiry date, likewise for seconds, a value of more than 60 means we are adding 1 or more minutes for an expiry date, example => 100:100:100 100 hours, 100 minutes and 100 seconds meaning order will expire 4 days, 5 hours, 41 minutes and 40 seconds from now', $this->id),
                    'default' => __(static::DEFAULT_EXPIRED, $this->id)
                ),
                'webhook_events' => array(
                    'title'       => __('Webhook Events', $this->id),
                    'type'        => 'multiselect',
                    'description' =>  "",
                    'class'       => 'wc-enhanced-select',
                    'default'     => '',
                    'options'     => array(
                        DP_Webhook::PAYMENT_COMPLETED        => 'payment.completed',
                        DP_Webhook::PAYMENT_FAILED            => 'payment.failed',
                    ),
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Select Webhook Events', 'woocommerce' ),
                    ),
                ),
            );

            foreach ($defaultFormFields as $key => $value)
            {
                if (in_array($key, $this->visibleSettings, true))
                {
                    $this->form_fields[$key] = $value;
                }
            }
        }

        public function autoEnableWebhook()
        {
            $webhookExist = false;
            $webhookUrl   = esc_url(admin_url('admin-post.php')) . '?action=dp_wc_webhook';

            $key_secret  = $this->getSetting('key_secret');
            $enabled     = $this->getSetting('enable_webhook');

            //validating the key id and key secret set properly or not.
            if($key_secret == null)
            {
                ?>
                    <div class="notice error is-dismissible" >
                     <p><b><?php _e( 'Key Id and Key Secret can`t be empty'); ?><b></p>
                    </div>
                <?php

                error_log('Key Id and Key Secret are required to enable the webhook.');
                return;
            }

            $eventsSubscribe = $this->getSetting('webhook_events');

            $prepareEventsData = [];

            if(empty($eventsSubscribe) == false)
            {
                foreach ($eventsSubscribe as $value) 
                {
                    $prepareEventsData[$value] = true;
                }
            }

            if(in_array($_SERVER['SERVER_ADDR'], ["127.0.0.1","::1"]))
            {
                error_log('Could not enable webhook for localhost');
                return;
            }

            if($enabled === 'no')
            {
                $data = [
                    'url'    => $webhookUrl,
                    'active' => false,
                ];
            }
            else
            {
                //validating event is not empty
                if(empty($eventsSubscribe) === true)
                {
                    ?>
                        <div class="notice error is-dismissible" >
                         <p><b><?php _e( 'At least one webhook event needs to be subscribed to enable webhook.'); ?><b></p>
                        </div>
                    <?php

                    error_log('At least one webhook event needs to be subscribed to enable webhook.');
                    return;
                }

                $data = [
                    'url'    => $webhookUrl,
                    'active' => $enabled == 'yes' ? true: false,
                    'events' => $prepareEventsData,
                ];

            }

            $webhook = $this->webhookAPI("GET", "webhooks");

            foreach ($webhook['items'] as $key => $value) 
            {
                if($value['url'] === $webhookUrl)
                {
                    $webhookExist  = true;
                    $webhookId     = $value['id'];
                }
            }

            if($webhookExist)
            {
                $this->webhookAPI('PUT', "webhooks/".$webhookId, $data);
            }
            else
            {
                $this->webhookAPI('POST', "webhooks/", $data);
            }
            
        }

        protected function webhookAPI($method, $url, $data = array())
        {
            $webhook = [];
            try
            {
                $api = $this->getDurianpayApiInstance();

                $webhook = $api->request->request($method, $url, $data);
            }
            catch(Exception $e)
            {
                $log = array(
                    'message' => $e->getMessage(),
                );

                error_log(json_encode($log));
            }

            return $webhook;
        }

        public function admin_options()
        {
            echo '<h3>'.__('Durianpay Payment Gateway', $this->id) . '</h3>';
            echo '<p>'.__('Allows payments by Credit/Debit Cards, NetBanking, and multiple Wallets') . '</p>';
            echo '<table class="form-table">';

            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        public function get_description()
        {
            return $this->getSetting('description');
        }

        /**
         * Receipt Page
         * @param string $orderId WC Order Id
         **/
        function receipt_page($orderId)
        {
            echo $this->generate_durianpay_form($orderId);
        }

        /**
         * Returns key to use in session for storing Durianpay order Id
         * @param  string $orderId Durianpay Order Id
         * @return string Session Key
         */
        protected function getOrderSessionKey($orderId)
        {
            return self::DURIANPAY_ORDER_ID . $orderId;
        }

        /**
         * Given a order Id, find the associated
         * Durianpay Order from the session and verify
         * that is is still correct. If not found
         * (or incorrect), create a new Durianpay Order
         *
         * @param  string $orderId Order Id
         * @return mixed Durianpay Order Id or Exception
         */
        protected function createOrGetDurianpayOrderId($orderId)
        {
            global $woocommerce;

            $sessionKey = $this->getOrderSessionKey($orderId);

            $create = false;

            try
            {
                $durianpayOrderId = $woocommerce->session->get($sessionKey);

                // If we don't have an Order
                // or the if the order is present in session but doesn't match what we have saved
                if ($durianpayOrderId === null)
                {
                    $create = true;
                }
                else
                {
                    return $durianpayOrderId;
                }
            }
            // Order doesn't exist or verification failed
            // So try creating one
            catch (Exception $e)
            {
                $create = true;
            }

            if ($create)
            {
                try
                {
                    return $this->createDurianpayOrderId($orderId, $sessionKey);
                }
                // For the bad request errors, it's safe to show the message to the customer.
                catch (Errors\BadRequestError $e)
                {
                    return $e;
                }
                // For any other exceptions, we make sure that the error message
                // does not propagate to the front-end.
                catch (Exception $e)
                {
                    return new Exception("Payment failed");
                }
            }
        }

        /**
         * Returns redirect URL post payment processing
         * @return string redirect URL
         */
        private function getRedirectUrl()
        {
            return add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) );
        }

           /**
         * Specific payment parameters to be passed to checkout
         * for payment processing
         * @param  string $orderId WC Order Id
         * @return array payment params
         */
        protected function getDurianpayPaymentParams($orderId)
        {
            $durianpayOrderId = $this->createOrGetDurianpayOrderId($orderId);

            if ($durianpayOrderId === null)
            {
                throw new Exception('DURIANPAY ERROR: Durianpay API could not be reached');
            }
            else if ($durianpayOrderId instanceof Exception)
            {
                $message = $durianpayOrderId->getMessage();

                throw new Exception("DURIANPAY ERROR: Order creation failed with the message: '$message'.");
            }

            return [
                'order_id'  =>  $durianpayOrderId
            ];
        }

         /**
         * Generate durianpay button link
         * @param string $orderId WC Order Id
         **/
        public function generate_durianpay_form($orderId)
        {
            $order = new WC_Order($orderId);

            try
            {
                $params = $this->getDurianpayPaymentParams($orderId);
            }
            catch (Exception $e)
            {
                return $e->getMessage();
            }

            $checkoutArgs = $this->getCheckoutArguments($order, $params);

            $html = '<p>'.__('Thank you for your order, please click the button below to pay with Durianpay.', $this->id).'</p>';

            $html .= $this->generateOrderForm($checkoutArgs);

            return $html;
        }


         /**
         * default parameters passed to checkout
         * @param  WC_Order $order WC Order
         * @return array checkout params
         */
        private function getDefaultCheckoutArguments($order)
        {
            $total = parse_number($order->get_total());
            $callbackUrl = $this->getRedirectUrl();
            $orderId = $order->get_order_number();
            $productinfo = "Order $orderId";
            $mod_version = get_plugin_data(plugin_dir_path(__FILE__) . 'woo-durianpay.php')['Version'];
            $order = new WC_Order($orderId);
            $amount = number_format(round($total), 2);

            $api = $this->getDurianpayApiInstance();

            try
            {
                $response = $api->auth->login()->toArray();
            }
            catch (Exception $e)
            {
                return $e->getMessage();
            }

            $accessKey = $response['data']['access_token'];
            
            // Determine environment based on secret key
            $environment = 'production';
            $secret = $this->getSetting('key_secret');
            if (strpos($secret, 'dp_test') === 0) {
                $environment = 'sandbox';
            }

            return array(
                'access_key'          => $accessKey,
                'environment'         => $environment,
                'container_elem'      => "pay-btn-container",
                'order_info'          => array(
		    'id' => $this->createOrGetDurianpayOrderId($orderId),
		    'customer_info' => $this->getCustomerInfo($order),
                ),
            );
        }


        /**
         * @param  WC_Order $order
         * @return string currency
         */
        private function getOrderCurrency($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                return $order->get_currency();
            }

            return $order->get_order_currency();
        }

        /**
         * @param  WC_Order $order
         * @return string shipping fees
         */
        private function getShippingFee($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '<'))
            {
                return number_format(round($order->get_total_shipping()), 2);
            }

            return $order->get_shipping_total();
        }

         /**
         * Returns array of checkout params
         */
        private function getCheckoutArguments($order, $params)
        {
            $args = $this->getDefaultCheckoutArguments($order);

            $currency = $this->getOrderCurrency($order);

            $args = array_merge($args, $params);

            return $args;
        }

        protected function createDurianpayOrderId($orderId, $sessionKey)
        {
            // Calls the helper function to create order data
            global $woocommerce;

            $api = $this->getDurianpayApiInstance();

            $data = $this->getOrderCreationData($orderId);

            try
            {
                $durianpayOrder = $api->order->create($data)->toArray();
            }
            catch (Exception $e)
            {               
                return $e;
            }

            $err = $durianpayOrder['error'] ?? null;
            $requestID = $durianpayOrder['request_id'] ?? null;
            $order = new WC_Order($orderId);
            if ($err != null) {                
                $order->add_order_note("Order failed to be created: $err ($requestID) - Please contact Durianpay's support team: ");
                return null;
            }

            $durianpayOrderId = $durianpayOrder['data']['id'];
            $woocommerce->session->set($sessionKey, $durianpayOrderId);                
            $order->add_order_note("Durianpay OrderId: $durianpayOrderId");
            
            return $durianpayOrderId;
        }

        public function getCartInfo() {
            $cart_data = array();
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
               $product = $cart_item['data'];
               $quantity = intval($cart_item['quantity']);
               $price = $product->get_price();
               $name = html_entity_decode($product->get_name());

	       $image = wp_get_attachment_url( $product->get_image_id() );

	       if ($image == false) {
		       $image = "";
	       }

               $cart_data[] = array(
                       "name" => html_entity_decode($name),
                       "qty" => $quantity,
                       "price" => number_format(round($price), 2),
                       "logo" => $image,
               );
            }

            return $cart_data;
        }

        public function getCustomerInfo($order)
        {
            $phone = $order->get_billing_phone();
            $mobile = $order->get_billing_phone();
            
            if ($phone != "") {
                $phone = preg_replace('/[^0-9]/', '', $phone);
            }

            if ($mobile != "") {
                $mobile = preg_replace('/[^0-9]/', '', $mobile);
            }

            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $args = array(
                    'given_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'   => $order->get_billing_email(),
                    'mobile' => $mobile,
                    'phone' => $phone,
		    'address' => $this->getAddressInfo($order),
                );
            }
            else
            {
                $args = array(
                    'given_name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'email'   => $order->billing_email,
                    'mobile' => $mobile,
                    'phone' => $phone,
		    'address' => $this->getAddressInfo($order),
                );
            }

            return $args;
        }

	public function getAddressInfo($order)
	{
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $address = array(
			'receiver_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'receiver_phone' => $order->get_billing_phone(),
			'label' => $order->get_billing_first_name() . ' Home',
			'address_line_1' => $order->get_billing_address_1(),
			'address_line_2' => $order->get_billing_address_2(),
			'city' => $order->get_billing_city(),
			'region' => $order->get_billing_state(),
			'postal_code' => $order->get_billing_postcode(),
			'landmark' => '',
                );
            }
            else
            {
                $address = array(
			'receiver_name' => $order->billing_first_name . ' ' . $order->billing_last_name,
			'receiver_phone' => $order->billing_phone,
			'label' => $order->billing_first_name . ' Home',
			'address_line_1' => $order->billing_address_1,
			'address_line_2' => $order->billing_address_2,
			'city' => $order->billing_city,
			'region' => $order->billing_state,
			'postal_code' => $order->billing_postcode,
			'landmark' => '',
                );
            }

	    return $address;
	}


        private function getOrderCreationData($orderId)
        {
            $order = new WC_Order($orderId);

            $shippingFee = $this->getShippingFee($order);

            $orderAmount = $order->get_total() - (float) $shippingFee;

            $data = array(
                'amount'          => number_format(round($orderAmount), 2),
                'currency'        => $this->getOrderCurrency($order),
                'customer'        => $this->getCustomerInfo($order),
                'order_ref_id'    => strval($orderId),
                'items'           => $this->getCartInfo(),
                'expiry_date'     => $this->getExpiryTimestamp($this->getSetting('expired_order')),
                'shipping_fee'    => $this->getShippingFee($order),
                'is_payment_link' => $this->getPaymentNotification()
            );

            return $data;
        }

        private function getExpiryTimestamp($expiryRequest) {
            # expiry request is in the format hours:minutes:seconds
            # hours can have a value of more than 24, meaning you can add 1 or more days for an expiry date
            # minutes can have a value of more than 60, meaning you can add 1 or more hours for an expiry date
            # likewise for seconds, a value of more than 60 means we are adding 1 or more minutes for an expiry date
            # example => 100:100:100 100 hours, 100 minutes and 100 seconds meaning order will expire 4 days, 5 hours, 41 minutes and 40 seconds from now
            $array = explode(":",$expiryRequest);

            $hr = intval($array[0]); 
            $min = intval($array[1]);
            $sec = intval($array[2]);

            $today = new DateTime();
            $today->add(new DateInterval('PT'.$hr.'H'.$min.'M'.$sec.'S'));
            return $today->format(DateTimeInterface::RFC3339);
        }

        private function enqueueCheckoutScripts($data)
        {
            $secret = $this->getSetting('key_secret');
            $jsUrl = "https://js.durianpay.id/0.1.52/durianpay.min.js";
            if (strpos($secret, 'dp_test') === 0) {
                $jsUrl = "https://js.durianpay.id/sandbox/0.1.55/durianpay.min.js";
            }
                        
            wp_register_script('durianpay_wc_script', plugin_dir_url(__FILE__)  . 'script.js',
            array('durianpay_checkout'));

            wp_register_script('durianpay_checkout', $jsUrl, null, null);

            wp_localize_script('durianpay_wc_script',
                'durianpay_wc_checkout_vars',
                $data
            );

            wp_enqueue_script('durianpay_wc_script');
        }

         /**
         * Generates the order form
         **/
        function generateOrderForm($data)
        {
            $redirectUrl = $this->getRedirectUrl();
            $data['cancel_url'] = wc_get_checkout_url();


            $this->enqueueCheckoutScripts($data);

            return <<<EOT
            <form name='durianpayform' action="$redirectUrl" method="POST">
                <input type="hidden" name="durianpay_payment_id" id="durianpay_payment_id">
                <input type="hidden" name="durianpay_payment_success" id="durianpay_payment_success">
                <input type="hidden" name="durianpay_wc_form_submit" value="1">
            </form>
            <p id="msg-durianpay-success" class="woocommerce-info woocommerce-message" style="display:none">
                Please wait while we are processing your payment.
            </p>
            <div id="pay-btn-container" class="pay">
                <button id="dpay-checkout-btn" class="dpay-payment-button btn filled">Pay Now</button>
                <button id="btn-durianpay-cancel" onclick="document.durianpayform.submit()">Cancel</button>
            </div>
EOT;
        }

         /**
         * Gets the Order Key from the Order
         * for all WC versions that we suport
         */
        protected function getOrderKey($order)
        {
            $orderKey = null;

            if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>='))
            {
                return $order->get_order_key();
            }

            return $order->order_key;
        }

         /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $woocommerce->session->set(self::SESSION_KEY, $order_id);

            $orderKey = $this->getOrderKey($order);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>='))
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $orderKey, $order->get_checkout_payment_url(true))
                );
            }
            else if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->get_id(),
                        add_query_arg('key', $orderKey, $order->get_checkout_payment_url(true)))
                );
            }
            else
            {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->get_id(),
                        add_query_arg('key', $orderKey, get_permalink(get_option('woocommerce_pay_page_id'))))
                );
            }
        }

        public function getDurianpayApiInstance()
        {
            return new Api($this->getSetting('key_id'), $this->getSetting('key_secret'));
        }

         /**
         * Check for valid durianpay server callback
         **/
        function check_durianpay_response()
        {
            if (!WC()->session) {
                WC()->initialize_session();
            }
            $orderId = WC()->session->get(self::SESSION_KEY);
            $order = new WC_Order($orderId);

            //
            // If the order has already been paid for
            // redirect user to success page
            //
            if ($order->needs_payment() === false)
            {
                $this->redirectUser($order);
            }

            $durianpayPaymentId = null;

            if ($orderId  and !empty($_POST[self::DURIANPAY_PAYMENT_ID]))
            {
                $error = "";
                $success = false;

                try
                {
                    $success = true;
                    $durianpayPaymentId = sanitize_text_field($_POST[self::DURIANPAY_PAYMENT_ID]);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $error = 'WOOCOMMERCE_ERROR: Payment to Durianpay Failed. ' . $e->getMessage();
                }
            }
            else
            {
                $durianpayFormSubmit = $_POST[self::DURIANPAY_WC_FORM_SUBMIT] ?? null;
                $durianpayPaymentSuccess = $_POST[self::DURIANPAY_PAYMENT_SUCCESS] ?? null;
                
                if ($durianpayFormSubmit == 1 && $durianpayPaymentSuccess === "false") {
                    $success = false;
                    $error = "Payment Failed";
                } elseif ($durianpayFormSubmit == 1) {
                    $success = false;
                    $error = "Customer cancelled the payment";
                } else {
                    $success = false;
                    $error = "There is an error processing the payment";
                }

                $this->handleErrorCase($order);
                $this->updateOrder($order, $success, $error, $durianpayPaymentId, null);
                wp_redirect(wc_get_checkout_url());

                exit;
            }

            $this->updateOrder($order, $success, $error, $durianpayPaymentId, null);
            $this->redirectUser($order);
        }

        protected function redirectUser($order)
        {
            $redirectUrl = $this->get_return_url($order);

            wp_redirect($redirectUrl);
            exit;
        }

        protected function getPaymentNotification() {
            $paymentNotification = $this->getSetting('enable_payment_notification');

            return $paymentNotification == 'yes' ? true : false;
        }

        protected function getErrorMessage($orderId)
        {
            // We don't have a proper order id
            if ($orderId !== null)
            {
                $message = 'An error occured while processing this payment';
            }
            if (isset($_POST['error']) === true)
            {
                $error = $_POST['error'];

                $description = htmlentities($error['description']);
                $code = htmlentities($error['code']);

                $message = 'An error occured. Description : ' . $description . '. Code : ' . $code;

                if (isset($error['field']) === true)
                {
                    $fieldError = htmlentities($error['field']);
                    $message .= 'Field : ' . $fieldError;
                }
            }
            else
            {
                $message = 'An error occured. Please contact administrator for assistance';
            }

            return $message;
        }

         /**
         * Modifies existing order and handles success case
         *
         * @param $success, & $order
         */
        public function updateOrder(& $order, $success, $errorMessage, $durianpayPaymentId, $virtualAccountId = null, $webhook = false)
        {
            global $woocommerce;

            $orderId = $order->get_order_number();

            if (($success === true) and ($order->needs_payment() === true))
            {
                $this->msg['class'] = 'success';

                $order->payment_complete($durianpayPaymentId);
                $order->add_order_note("Durianpay payment successful <br/>Durianpay Id: $durianpayPaymentId");

                if($virtualAccountId != null)
                {
                    $order->add_order_note("Virtual Account Id: $virtualAccountId");
                }

                if (isset($woocommerce->cart) === true)
                {
                    $woocommerce->cart->empty_cart();
                }
            }          

            if ($webhook === false)
            {
                $this->add_notice($this->msg['message'], $this->msg['class']);
            }
        }

        protected function handleErrorCase(& $order)
        {
            $orderId = $order->get_order_number();

            $this->msg['class'] = 'error';
            $this->msg['message'] = $this->getErrorMessage($orderId);
        }

         /**
         * Add a woocommerce notification message
         *
         * @param string $message Notification message
         * @param string $type Notification type, default = notice
         */
        protected function add_notice($message, $type = 'notice')
        {
            if (!function_exists('wc_add_notice')) {
                return; // Prevents fatal error if WooCommerce is not loaded
            }

            $type = in_array($type, array('notice', 'error', 'success'), true) ? $type : 'notice';
            wc_add_notice($message, $type);
        }
    }

     /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_durianpay_gateway($methods)
    {
        $methods[] = 'WC_Durianpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_durianpay_gateway' );
}

function parse_number($number, $dec_point=null) {
    if (empty($dec_point)) {
        $locale = localeconv();
        $dec_point = $locale['decimal_point'];
    }
    return floatval(str_replace($dec_point, '.', preg_replace('/[^\d'.preg_quote($dec_point).']/', '', $number)));
}


// This is set to a priority of 10
function durianpay_webhook_init()
{
    $dpWebhook = new DP_Webhook();

    $dpWebhook->process();
}
