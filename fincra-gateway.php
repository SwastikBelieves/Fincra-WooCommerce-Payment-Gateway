<?php
/**
 * Plugin Name: WooCommerce Fincra Payment Gateway
 * Plugin URI: https://swastik.dev/
 * Description: A secure, production-ready WooCommerce payment gateway for Fincra.
 * Version: 1.3.0
 * Author: Swastik Chakraborty
 * Author URI: https://swastik.dev/
 * License: GPL-2.0+
 * Text Domain: fincra-woo-gateway
 */

if (!defined('ABSPATH')) exit;

// Register Gateway
add_filter('woocommerce_payment_gateways', 'add_fincra_gateway');
function add_fincra_gateway($gateways) {
    $gateways[] = 'WC_Fincra_Gateway';
    return $gateways;
}

// Initialize Gateway
add_action('plugins_loaded', 'init_fincra_gateway');
function init_fincra_gateway() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Fincra_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'fincra';
            $this->method_title = 'Fincra Payment';
            $this->method_description = 'Accept cards, bank transfers, and mobile money via Fincra.';
            $this->has_fields = false;

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user settings
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->public_key = $this->get_option('public_key');
            $this->secret_key = $this->get_option('secret_key');
            $this->business_id = $this->get_option('business_id');
            $this->mode = $this->get_option('mode', 'sandbox');
            $this->fee_bearer = $this->get_option('fee_bearer', 'customer');
            $this->settlement_destination = $this->get_option('settlement_destination', 'wallet');
            $this->default_payment_method = $this->get_option('default_payment_method', 'card');

            // Save settings hook
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

            // Webhook Listener Hook
            add_action('woocommerce_api_fincra_webhook', [$this, 'handle_webhook']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => ['title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable Fincra', 'default' => 'yes'],
                'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'Fincra Payment'],
                'description' => ['title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely using Fincra.'],
                'mode' => [
                    'title' => 'Mode', 'type' => 'select', 
                    'options' => ['sandbox' => 'Sandbox', 'production' => 'Production'], 'default' => 'sandbox'
                ],
                'public_key' => ['title' => 'Public Key', 'type' => 'text'],
                'secret_key' => ['title' => 'Secret Key', 'type' => 'password'],
                'business_id' => ['title' => 'Business ID', 'type' => 'text', 'description' => 'Required for all transactions.'],
                'fee_bearer' => [
                    'title' => 'Fee Bearer', 'type' => 'select',
                    'options' => ['customer' => 'Customer', 'business' => 'Business'], 'default' => 'customer'
                ],
                'settlement_destination' => [
                    'title' => 'Settlement Destination', 'type' => 'select',
                    'options' => ['wallet' => 'Wallet'], 'default' => 'wallet'
                ],
                'default_payment_method' => [
                    'title' => 'Default Payment Method', 'type' => 'select',
                    'options' => ['card' => 'Card', 'bank_transfer' => 'Bank Transfer', 'payattitude' => 'Payattitude'],
                    'default' => 'card'
                ],
            ];
        }

        public function payment_fields() {
            if ($this->description) {
                echo wp_kses_post(wpautop(wptexturize($this->description)));
            }
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $supported = ['NGN', 'GHS', 'KES', 'UGX', 'ZAR', 'ZMW'];

            if (!in_array($order->get_currency(), $supported)) {
                wc_add_notice('Fincra does not support ' . $order->get_currency(), 'error');
                return;
            }

            $response = $this->generate_fincra_url($order);

            if ($response && isset($response['data']['link'])) {
                return [
                    'result'   => 'success',
                    'redirect' => $response['data']['link'],
                ];
            }

            wc_add_notice('Payment gateway is currently unreachable. Please try again.', 'error');
            return;
        }

        private function generate_fincra_url($order) {
            $url = ($this->mode === 'sandbox') 
                ? 'https://sandboxapi.fincra.com/checkout/payments' 
                : 'https://api.fincra.com/checkout/payments';

            $payload = [
                'customer' => [
                    'name'  => sanitize_text_field($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'email' => sanitize_email($order->get_billing_email()),
                    'phoneNumber' => sanitize_text_field($order->get_billing_phone()),
                ],
                'amount'      => floatval($order->get_total()),
                'currency'    => $order->get_currency(),
                'redirectUrl' => $this->get_return_url($order),
                'reference'   => (string)$order->get_id(), // CRITICAL for Webhook tracking
                'feeBearer'   => $this->fee_bearer,
                'paymentMethods' => ['card', 'bank_transfer', 'payattitude'],
                'defaultPaymentMethod' => $this->default_payment_method,
                'settlementDestination' => $this->settlement_destination,
            ];

            $response = wp_remote_post($url, [
                'method'    => 'POST',
                'headers'   => [
                    'accept'        => 'application/json',
                    'content-type'  => 'application/json',
                    'x-business-id' => sanitize_text_field($this->business_id),
                    'api-key'       => sanitize_text_field($this->secret_key),
                    'x-pub-key'     => sanitize_text_field($this->public_key),
                ],
                'body'      => json_encode($payload),
                'timeout'   => 45,
            ]);

            if (is_wp_error($response)) {
                wc_get_logger()->error('Fincra Request Failed: ' . $response->get_error_message());
                return false;
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }

        /**
         * Handle Webhook confirmating from Fincra
         * URL: https://yourdomain.com/wc-api/fincra_webhook
         */
        public function handle_webhook() {
            $payload = file_get_contents('php://input');
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            
            // Validate that request actually came from someone who knows your Secret Key (Basic Security)
            // Ideally, Fincra provides a signature header to verify.
            
            $data = json_decode($payload, true);

            if (empty($data) || !isset($data['event'])) {
                status_header(400);
                exit;
            }

            if ($data['event'] === 'collection.successful') {
                $order_id = $data['data']['reference'];
                $order    = wc_get_order($order_id);

                if ($order && !$order->is_paid()) {
                    $order->payment_complete($data['data']['reference']);
                    $order->add_order_note('Payment successfully verified via Fincra Webhook.');
                    wc_empty_cart();
                }
            }

            status_header(200);
            exit;
        }
    }
}
