<?php

require_once __DIR__.'/../woo-durianpay.php';
require_once __DIR__.'/../durianpay-sdk/Durianpay.php';

use Durianpay\Api\Api;
use Durianpay\Api\Errors;

class DP_Webhook
{
    /**
     * Instance of the durianpay payments class
     * @var WC_Durianpay
     */
    protected $durianpay;

    /**
     * API client instance to communicate with Durianpay API
     * @var Durianpay\Api\Api
     */
    protected $api;

    /**
     * Event constants
     */
    const PAYMENT_COMPLETED        = 'payment.completed';
    const PAYMENT_FAILED            = 'payment.failed';

    public function __construct()
    {
        $this->durianpay = new WC_Durianpay(false);

        $this->api = $this->durianpay->getDurianpayApiInstance();
    }

    /**
     * Process a Durianpay Webhook. We exit in the following cases:
     * - Successful processed
     * - Exception while fetching the payment
     *
     * It passes on the webhook in the following cases:
     * - invoice_id set in payment.completed
     * - order refunded
     * - Invalid JSON
     * - Signature mismatch
     * - Secret isn't setup
     * - Event not recognized
     *
     * @return void|WP_Error
     * @throws Exception
     */
    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0)
        {
            return;
        }

        error_log(serialize($data));

        $enabled = $this->durianpay->getSetting('enable_webhook');

        if (($enabled === 'yes') and
            (empty($data['event']) === false))
        {
            switch ($data['event'])
            {
                case self::PAYMENT_COMPLETED:
                    return $this->paymentCompleted($data);

                case self::PAYMENT_FAILED:
                    return $this->paymentFailed($data);

                default:
                    return;
            }
            
        }
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function paymentFailed(array $data)
    {
        return;
    }

    /**
     * Handling the payment completed webhook
     *
     * @param array $data Webook Data
     */
    protected function paymentCompleted(array $data)
    {
        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = intval($data['data']['order_ref_id']);

        $order = wc_get_order($orderId);

        // If it is already marked as completed, ignore the event
        if ($order->get_status() === "processing")
        {
            return;
        }

        $durianpayPaymentId = $data['data']['id'];

        $this->durianpay->updateOrder($order, true, "", $durianpayPaymentId, null, true);
        $order->update_status('processing');
        exit;
    }
}
