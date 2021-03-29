<?php
/*
 * Plugin Name: Durianpay for WooCommerce
 * Plugin URI: https://durianpay.id
 * Description: Durianpay Payment Gateway Integration for WooCommerce
 * Version: 1.1.0
 * Stable tag: 1.1.0
 * Author: Team Durianpay
 * Author URI: https://durianpay.id
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

add_action('plugins_loaded', 'woocommerce_durianpay_init', 0);
add_action('admin_post_nopriv_dp_wc_webhook', 'durianpay_webhook_init', 10);


function woocommerce_durianpay_init()
{
    if (!class_exists('WC_Payment_Gateway'))
    {
        return;
    }

    class WC_Durianpay extends WC_Payment_Gateway
    {
         // This one stores the WooCommerce Order Id
         const SESSION_KEY                    = 'durianpay_wc_order_id';
         const DURIANPAY_PAYMENT_ID            = 'durianpay_payment_id';
         const DURIANPAY_ORDER_ID              = 'durianpay_order_id';
         const DURIANPAY_SIGNATURE             = 'durianpay_signature';
         const DURIANPAY_WC_FORM_SUBMIT        = 'durianpay_wc_form_submit';

         const IDR                            = 'IDR';
         const CAPTURE                        = 'capture';
         const AUTHORIZE                      = 'authorize';
         const WC_ORDER_ID                    = 'woocommerce_order_id';

         const DEFAULT_LABEL                  = 'Credit Card/Debit Card/NetBanking';
         const DEFAULT_DESCRIPTION            = 'Pay securely by Credit or Debit card or Internet Banking through Durianpay.';
         const DEFAULT_SUCCESS_MESSAGE        = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';

         protected $visibleSettings = array(
            'enabled',
            'title',
            'description',
            'key_id',
            'key_secret',
            'payment_action',
            'order_success_message',
            'enable_webhook',
            'webhook_secret',
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

        protected function getCustomOrdercreationMessage()
        {
            $message =  $this->getSetting('order_success_message');
            if (isset($message) === false)
            {
                $message = STATIC::DEFAULT_SUCCESS_MESSAGE;
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

        protected function initHooks()
        {
            add_action('init', array(&$this, 'check_durianpay_response'));

            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            add_action('woocommerce_api_' . $this->id, array($this, 'check_durianpay_response'));

            $cb = array($this, 'process_admin_options');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", $cb);
            }
            else
            {
                add_action('woocommerce_update_options_payment_gateways', $cb);
            }
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
                'key_id' => array(
                    'title' => __('Key ID', $this->id),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Durianpay Dashboard. Use test or live for test or live mode.', $this->id)
                ),
                'key_secret' => array(
                    'title' => __('Key Secret', $this->id),
                    'type' => 'text',
                    'description' => __('The key Id and key secret can be generated from "API Keys" section of Durianpay Dashboard. Use test or live for test or live mode.', $this->id)
                ),
                'payment_action' => array(
                    'title' => __('Payment Action', $this->id),
                    'type' => 'select',
                    'description' =>  __('Payment action on order compelete', $this->id),
                    'default' => self::CAPTURE,
                    'options' => array(
                        self::AUTHORIZE => 'Authorize',
                        self::CAPTURE   => 'Authorize and Capture'
                    )
                ),
                'order_success_message' => array(
                    'title' => __('Order Completion Message', $this->id),
                    'type'  => 'textarea',
                    'description' =>  __('Message to be displayed after a successful order', $this->id),
                    'default' =>  __(STATIC::DEFAULT_SUCCESS_MESSAGE, $this->id),
                ),
                'enable_webhook' => array(
                    'title' => __('Enable Webhook', $this->id),
                    'type' => 'checkbox',
                    'description' =>  "<span>$webhookUrl</span><br/><br/>Instructions and guide to <a href='https://github.com/durianpay/durianpay-woocommerce/wiki/Durianpay-Woocommerce-Webhooks'>Durianpay webhooks</a>",
                    'label' => __('Enable Durianpay Webhook <a href="https://dashboard.durianpay.com/#/app/webhooks">here</a> with the URL listed below.', $this->id),
                    'default' => 'no'
                ),
                'webhook_secret' => array(
                    'title' => __('Webhook Secret', $this->id),
                    'type' => 'text',
                    'description' => __('Webhook secret is used for webhook signature verification. This has to match the one added <a href="https://dashboard.durianpay.com/#/app/webhooks">here</a>', $this->id),
                    'default' => ''
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

        public function admin_options()
        {
            echo '<h3>'.__('Durianpay Payment Gateway', $this->id) . '</h3>';
            echo '<p>'.__('Allows payments by Credit/Debit Cards, NetBanking, UPI, and multiple Wallets') . '</p>';
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
            $callbackUrl = $this->getRedirectUrl();

            $orderId = $order->get_order_number();

            $productinfo = "Order $orderId";
            $mod_version = get_plugin_data(plugin_dir_path(__FILE__) . 'woo-durianpay.php')['Version'];

            $order = new WC_Order($orderId);

            $amount = number_format(round($order->get_total()), 2);

            return array(
                'access_key'          => 'dp_live_9gf2gbgk1rdazqq4',
                'environment'         => 'staging',
                'container_elem'      => "pay-btn-container",
                'order_info'          => array(
			        'id' => $this->createOrGetDurianpayOrderId($orderId),
                    'amount' => $amount,
                    'currency' => self::IDR,
                    'items' => $this->getCartInfo(),
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

            $durianpayOrderId = $durianpayOrder['data']['id'];

            $woocommerce->session->set($sessionKey, $durianpayOrderId);

            //update it in order comments
            $order = new WC_Order($orderId);

            $order->add_order_note("Durianpay OrderId: $durianpayOrderId");

            return $durianpayOrderId;
        }

        public function getCartInfo() {
            $cart_data = array();
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
               $product = $cart_item['data'];
               $quantity = $cart_item['quantity'];
               $price = $product->get_price();
               $name = $product->get_name();

               $cart_data[] = array(
                       "name" => $name,
                       "qty" => $quantity,
                       "price" => number_format(round($price), 2),
                       "logo" => wp_get_attachment_url( $product->get_image_id() ),
               );
            }
            return $cart_data;
        }

        public function getCustomerInfo($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $args = array(
                    'given_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'   => $order->get_billing_email(),
                    'mobile' => $order->get_billing_phone(),
                );
            }
            else
            {
                $args = array(
                    'given_name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'email'   => $order->billing_email,
                    'mobile' => $order->billing_phone,
                );
            }

            return $args;
        }


        private function getOrderCreationData($orderId)
        {
            $order = new WC_Order($orderId);

            $data = array(
                'amount'          => number_format(round($order->get_total()), 2),
                'currency'        => $this->getOrderCurrency($order),
                'customer'        => $this->getCustomerInfo($order),
                'items'           => $this->getCartInfo(),
            );

            return $data;
        }

        private function enqueueCheckoutScripts($data)
        {

            wp_register_script('durianpay_wc_script', plugin_dir_url(__FILE__)  . 'script.js',
            array('durianpay_checkout'));

            wp_register_script('durianpay_checkout',
                'http://js-staging.durianpay.id/0.1.5/durianpay.js',
                null, null);

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
            global $woocommerce;

            $orderId = $woocommerce->session->get(self::SESSION_KEY);
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
                if($_POST[self::DURIANPAY_WC_FORM_SUBMIT] ==1)
                {
                    $success = false;
                    $error = 'Customer cancelled the payment';
                }
                else
                {
                    $success = false;
                    $error = "Payment Failed.";
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
                $this->msg['message'] = $this->getCustomOrdercreationMessage() . "&nbsp; Order Id: $orderId";
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
            else
            {
                $this->msg['class'] = 'error';
                $this->msg['message'] = $errorMessage;

                if ($durianpayPaymentId)
                {
                    $order->add_order_note("Payment Failed. Please check Durianpay Dashboard. <br/> Durianpay Id: $durianpayPaymentId");
                }

                $order->add_order_note("Transaction Failed: $errorMessage<br/>");
                $order->update_status('failed');
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
            global $woocommerce;
            $type = in_array($type, array('notice','error','success'), true) ? $type : 'notice';
            // Check for existence of new notification api. Else use previous add_error
            if (function_exists('wc_add_notice'))
            {
                wc_add_notice($message, $type);
            }
            else
            {
                // Retrocompatibility WooCommerce < 2.1
                switch ($type)
                {
                    case "error" :
                        $woocommerce->add_error($message);
                        break;
                    default :
                        $woocommerce->add_message($message);
                        break;
                }
            }
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

// This is set to a priority of 10
function durianpay_webhook_init()
{
    $dpWebhook = new DP_Webhook();

    $dpWebhook->process();
}