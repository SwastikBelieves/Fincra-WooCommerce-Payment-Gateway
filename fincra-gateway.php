<?php
/*
 * Plugin Name: WooCommerce Fincra Payment Gateway
 * Plugin URI: https://swastik.dev/
 * Description: A custom WooCommerce payment gateway that integrates with Fincra.
 * Version: 1.2
 * Author: Swastik Chakraborty
 * Author URI: https://swastik.dev/
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Add Fincra gateway to WooCommerce gateways
add_filter('woocommerce_payment_gateways', 'add_fincra_gateway');
function add_fincra_gateway($gateways) {
    $gateways[] = 'WC_Fincra_Gateway';
    return $gateways;
}

// Initialize the Fincra Gateway
add_action('plugins_loaded', 'init_fincra_gateway');
function init_fincra_gateway() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Fincra_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'fincra';
            $this->method_title = 'Fincra Payment';
            $this->method_description = 'Pay securely using Fincra Payment Gateway';
            $this->has_fields = false;

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->public_key = $this->get_option('public_key');
            $this->secret_key = $this->get_option('secret_key');
            $this->business_id = $this->get_option('business_id');
            $this->redirect_url = $this->get_option('redirect_url');
            $this->fee_bearer = $this->get_option('fee_bearer');
            $this->success_message = $this->get_option('success_message');
            $this->settlement_destination = $this->get_option('settlement_destination');
            $this->default_payment_method = $this->get_option('default_payment_method');
            $this->mode = $this->get_option('mode'); // Store the selected mode

            // Save admin settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        // Admin settings fields
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Fincra Payment Gateway',
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'Fincra Payment',
                ],
                'description' => [
                    'title' => 'Description',
                    'type' => 'textarea',
                    'default' => 'Pay securely using Fincra.',
                ],
                'mode' => [
                    'title' => 'Mode',
                    'type' => 'select',
                    'description' => 'Select the mode of operation. Business ID is required in Sandbox mode.',
                    'options' => [
                        'sandbox' => 'Sandbox',
                        'production' => 'Production',
                    ],
                    'default' => 'sandbox',
                ],
                'public_key' => [
                    'title' => 'Fincra Public Key',
                    'type' => 'text',
                ],
                'secret_key' => [
                    'title' => 'Fincra Secret Key',
                    'type' => 'password',
                ],
                'business_id' => [
                    'title' => 'Fincra Business ID (Required for Sandbox)',
                    'type' => 'text',
                ],
                'redirect_url' => [
                    'title' => 'Redirect URL (Optional)',
                    'type' => 'text',
                    'description' => 'Optional URL to redirect after payment.',
                ],
                'fee_bearer' => [
                    'title' => 'Fee Bearer',
                    'type' => 'select',
                    'options' => [
                        'customer' => 'Customer',
                        'business' => 'Business',
                    ],
                    'description' => 'Select who will pay the fee.',
                ],
                'success_message' => [
                    'title' => 'Success Message (Optional)',
                    'type' => 'textarea',
                    'description' => 'Message to show after successful payment.',
                ],
                'settlement_destination' => [
                    'title' => 'Settlement Destination',
                    'type' => 'select',
                    'options' => [
                        'wallet' => 'Wallet',
                    ],
                    'description' => 'Select the settlement destination.',
                ],
                'default_payment_method' => [
                    'title' => 'Default Payment Method',
                    'type' => 'select',
                    'options' => [
                        'card' => 'Card',
                        'bank_transfer' => 'Bank Transfer',
                        'payattitude' => 'Payattitude',
                    ],
                    'description' => 'Select the default payment method.',
                ],
            ];
        }

        // Show Fincra title and description on checkout page
        public function payment_fields() {
            if ($this->description) {
                echo '<p>' . wp_kses_post($this->description) . '</p>';
            }
        }

        // Validate currency
        private function validate_currency($currency) {
            $valid_currencies = ['NGN', 'GHS', 'KES', 'UGX', 'ZAR', 'ZMW'];
            return in_array($currency, $valid_currencies);
        }

        // Process the payment
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $currency = get_woocommerce_currency();

            // Validate the currency
            if (!$this->validate_currency($currency)) {
                wc_add_notice('Error: Only NGN, GHS, KES, UGX, ZAR, ZMW currencies are supported.', 'error');
                return;
            }

            // Prepare payment data
            $payment_url = $this->generate_fincra_payment_url($order);

            if ($payment_url) {
                return [
                    'result'   => 'success',
                    'redirect' => $payment_url,
                ];
            } else {
                wc_add_notice('Unable to initiate Fincra payment. Please try again.', 'error');
                return;
            }
        }

        // Generate the payment link via Fincra API
        private function generate_fincra_payment_url($order) {
            $customer_data = [
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phoneNumber' => $order->get_billing_phone(),
            ];

            $amount = $order->get_total();
            $currency = get_woocommerce_currency();
            $callback_url = $this->redirect_url ?: $this->get_return_url($order);
            $reference = strval($order->get_id());

            $data = [
                'currency' => $currency,
                'customer' => $customer_data,
                'amount' => floatval($amount),
                'redirectUrl' => $callback_url,
                'feeBearer' => $this->fee_bearer,
                'paymentMethods' => ['card', 'bank_transfer', 'payattitude'],
                'defaultPaymentMethod' => $this->default_payment_method,
                // 'reference' => $reference,
                'settlementDestination' => $this->settlement_destination,
            ];

            $response = $this->send_fincra_request($data);
            if (isset($response['data']['link'])) {
                return $response['data']['link'];
            }
            return false;
        }

        // Send request to Fincra API
        private function send_fincra_request($data) {
            $url = $this->mode === 'sandbox'
                ? 'https://sandboxapi.fincra.com/checkout/payments'
                : 'https://api.fincra.com/checkout/payments';

            $headers = [
                'accept: application/json',
                'content-type: application/json',
                'x-business-id: ' . $this->business_id,
                'api-key: ' . $this->secret_key,
                'x-pub-key: ' . $this->public_key,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                wc_get_logger()->error('Fincra API Error: ' . $error);
                wc_add_notice('Payment gateway is temporarily unavailable. Please try again later.', 'error');
                return false;
            }

            return json_decode($response, true);
        }
    }
}
?>
