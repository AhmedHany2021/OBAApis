<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Checkout;
use WC_Coupon;
use WC_Discounts;

class CheckoutService
{
    public function __construct()
    {
        $this->init_wc_context();
    }

    /**
     * Ensure WC cart, customer, checkout exist (like CartService does for the cart)
     */
    private function init_wc_context()
    {
        if (function_exists('WC')) {
            if (null === WC()->cart) {
                wc_load_cart();
            }
            if (null === WC()->checkout) {
                wc()->checkout = new WC_Checkout();
            }
        }
    }

    /**
     * Helper: get authenticated user ID
     */
    private function get_authenticated_user(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        if (!$user || !isset($user->ID)) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }
        return (int)$user->ID;
    }

    /**
     * GET checkout data: cart, addresses, payment methods
     */
    public function get_checkout_data(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) return $user_id;

        if (!class_exists('WC_Cart')) {
            return new WP_Error(
                'woocommerce_required',
                __('WooCommerce is required for checkout operations.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        if (WC()->cart->is_empty()) {
            return new WP_Error(
                'empty_cart',
                __('Cart is empty. Cannot proceed with checkout.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Recalculate to be safe
        WC()->cart->calculate_fees();
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        $cart = $this->format_cart();

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'cart'             => $cart,
                'payment_methods'  => $this->get_available_payment_methods(),
                'shipping_methods' => $this->list_current_package_methods(), // labels of currently calculated methods (if any)
                'addresses'        => $this->get_user_addresses($user_id),
            ],
        ]);
    }

    /**
     * Endpoint: get live shipping rates based on provided address
     * Accepts: country, state, postcode, city (shipping_* or general keys)
     */
    public function get_shipping_rates(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) return $user_id;

        if (WC()->cart->is_empty()) {
            return new WP_Error('empty_cart', __('Cart is empty.', 'oba-apis-integration'), ['status' => 400]);
        }

        $country = sanitize_text_field($request->get_param('country') ?: $request->get_param('shipping_country'));
        $state = sanitize_text_field($request->get_param('state') ?: $request->get_param('shipping_state'));
        $postcode = sanitize_text_field($request->get_param('postcode') ?: $request->get_param('shipping_postcode'));
        $city = sanitize_text_field($request->get_param('city') ?: $request->get_param('shipping_city'));

        // Update customer location (both billing & shipping to keep taxes coherent)
        WC()->customer->set_shipping_country($country);
        WC()->customer->set_shipping_state($state);
        WC()->customer->set_shipping_postcode($postcode);
        WC()->customer->set_shipping_city($city);

        WC()->customer->set_billing_country($country);
        WC()->customer->set_billing_state($state);
        WC()->customer->set_billing_postcode($postcode);
        WC()->customer->set_billing_city($city);
        WC()->customer->save();

        // Recalculate shipping & totals (ShipStation will be queried here)
        $packages = WC()->cart->get_shipping_packages();
        WC()->shipping()->calculate_shipping($packages);
        WC()->cart->calculate_totals();

        $rates_response = $this->collect_package_rates();

        return new WP_REST_Response([
            'success'     => true,
            'packages'    => $rates_response['packages'],
            'cart_totals' => [
                'subtotal' => WC()->cart->get_subtotal(),
                'shipping' => WC()->cart->get_shipping_total(),
                'discount' => WC()->cart->get_discount_total(),
                'tax'      => WC()->cart->get_total_tax(),
                'total'    => WC()->cart->get_total('edit'),
                'currency' => get_woocommerce_currency(),
            ],
        ]);
    }

    /**
     * Endpoint: set chosen shipping method(s)
     * Accepts:
     * - rate_id (string) for single package carts
     * - OR chosen (array of rate_ids keyed by package index) for Dokan multi-vendor multi-package
     */
    public function set_shipping_method(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) return $user_id;

        if (WC()->cart->is_empty()) {
            return new WP_Error('empty_cart', __('Cart is empty.', 'oba-apis-integration'), ['status' => 400]);
        }

        $chosen = $request->get_param('chosen'); // array of rate ids keyed by package index
        $single = $request->get_param('rate_id'); // single rate id for one-package carts

        $packages = WC()->cart->get_shipping_packages();

        if (is_array($chosen) && !empty($chosen)) {
            // Normalize to keep order with packages
            $final = [];
            foreach ($packages as $idx => $pkg) {
                $final[$idx] = sanitize_text_field($chosen[$idx] ?? '');
            }
            WC()->session->set('chosen_shipping_methods', $final);
        } else {
            // Single
            if (!$single) {
                return new WP_Error('missing_rate_id', __('Shipping rate ID is required.', 'oba-apis-integration'), ['status' => 400]);
            }
            WC()->session->set('chosen_shipping_methods', [sanitize_text_field($single)]);
        }

        // Recalculate totals
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success'     => true,
            'message'     => __('Shipping method(s) updated.', 'oba-apis-integration'),
            'cart_totals' => [
                'subtotal' => WC()->cart->get_subtotal(),
                'shipping' => WC()->cart->get_shipping_total(),
                'discount' => WC()->cart->get_discount_total(),
                'tax'      => WC()->cart->get_total_tax(),
                'total'    => WC()->cart->get_total('edit'),
                'currency' => get_woocommerce_currency(),
            ],
        ]);
    }

    /**
     * Validate checkout payload (basic)
     */
    public function validate_checkout(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) return $user_id;

        if (WC()->cart->is_empty()) {
            return new WP_Error('empty_cart', __('Cart is empty.', 'oba-apis-integration'), ['status' => 400]);
        }

        $data = $request->get_json_params();
        if (empty($data)) {
            return new WP_Error('invalid_checkout_data', __('Checkout data is required.', 'oba-apis-integration'), ['status' => 400]);
        }

        $errors = [];
        if (empty($data['billing'])) {
            $errors['billing'] = __('Billing address is required.', 'oba-apis-integration');
        } else {
            $e = $this->validate_address($data['billing'], 'billing');
            if (!empty($e)) $errors['billing'] = $e;
        }

        // Optional shipping; if provided, validate; if not, we’ll copy billing later
        if (!empty($data['shipping'])) {
            $e = $this->validate_address($data['shipping'], 'shipping');
            if (!empty($e)) $errors['shipping'] = $e;
        }

        if (empty($data['payment_method'])) {
            $errors['payment_method'] = __('Payment method is required.', 'oba-apis-integration');
        } else {
            $available = $this->get_available_payment_methods();
            if (!isset($available[$data['payment_method']])) {
                $errors['payment_method'] = __('Invalid payment method.', 'oba-apis-integration');
            }
        }

        if (!empty($errors)) {
            return new WP_REST_Response(['success' => false, 'errors' => $errors], 400);
        }

        // Validation passed - update user meta with billing and shipping addresses
        $billing = $this->sanitize_address($data['billing']);
        $shipping = isset($data['shipping']) ? $this->sanitize_address($data['shipping']) : $billing;
        $this->update_user_addresses($user_id, $billing, $shipping);
            
        return new WP_REST_Response(['success' => true, 'message' => __('Checkout data is valid.', 'oba-apis-integration')]);
    }

    /**
     * Process checkout: creates order + handles COD/Stripe
     * Request JSON fields (examples):
     *  - billing: { first_name, last_name, address_1, city, state, postcode, country, email, phone, ... }
     *  - shipping: (optional; if missing, billing is copied)
     *  - payment_method: 'cod' | 'stripe'
     *  - order_notes: (optional)
     */
    public function process_checkout(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) return $user_id;

        if (!class_exists('WC_Order')) {
            return new WP_Error('woocommerce_required', __('WooCommerce is required for checkout operations.', 'oba-apis-integration'), ['status' => 400]);
        }

        if (WC()->cart->is_empty()) {
            return new WP_Error('empty_cart', __('Cart is empty. Cannot proceed with checkout.', 'oba-apis-integration'), ['status' => 400]);
        }

        $data = $request->get_json_params();
        if (empty($data)) {
            return new WP_Error('invalid_checkout_data', __('Checkout data is required.', 'oba-apis-integration'), ['status' => 400]);
        }

        $billing = isset($data['billing']) ? $this->sanitize_address($data['billing']) : [];
        $shipping = isset($data['shipping']) ? $this->sanitize_address($data['shipping']) : $billing;
        $payment_method = sanitize_text_field($data['payment_method'] ?? '');

        if (!empty($data['shipping_method'])) {
            $chosen = [sanitize_text_field($data['shipping_method'])];
            WC()->session->set('chosen_shipping_methods', $chosen);
        }

        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        $checkout_fields = array_merge(
            $this->prefix_address($billing, 'billing_'),
            $this->prefix_address($shipping, 'shipping_'),
            [
                'payment_method' => $payment_method,
                'terms'          => 1,
                'customer_id'    => $user_id,
                'order_comments' => isset($data['order_notes']) ? sanitize_textarea_field($data['order_notes']) : '',
            ]
        );

        $order_id = WC()->checkout()->create_order($checkout_fields);
        if (is_wp_error($order_id)) {
            return $order_id;
        }

        $order = wc_get_order($order_id);

        if (!$order->get_shipping_first_name() && !empty($billing)) {
            $order->set_address($shipping, 'shipping');
        }
        $order->set_customer_id($user_id);

        if (!empty($data['order_notes'])) {
            $order->add_order_note(sanitize_textarea_field($data['order_notes']));
        }

        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (empty($gateways[$payment_method])) {
            return new WP_Error('invalid_payment_method', __('Selected payment method is not available.', 'oba-apis-integration'), ['status' => 400]);
        }

        $order->set_payment_method($gateways[$payment_method]);
        $order->calculate_totals();
        $order->save();

        /**
         * COD FLOW
         */
        if ($payment_method === 'cod') {
            $order->update_status('processing', __('Cash on delivery order.', 'oba-apis-integration'));
            WC()->cart->empty_cart();
            $this->update_user_credit($order);
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Order placed with Cash on Delivery.', 'oba-apis-integration'),
                'order'   => $this->format_order($order, true),
            ], 201);
        }

        /**
         * STRIPE
         * NEWLY ADDED SECTION
         */
        if ($payment_method === 'stripe') {
            $stripe_payment_method_id = sanitize_text_field($data['stripe_payment_method_id'] ?? '');
            if (empty($stripe_payment_method_id)) {
                return new WP_Error(
                    'missing_stripe_payment_method',
                    __('Stripe payment method ID is required.', 'oba-apis-integration'),
                    ['status' => 400]
                );
            }

            try {
                // Load Stripe SDK
                if (!class_exists(\Stripe\Stripe::class)) {
                    require_once WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/vendor/autoload.php';
                }

                // Get Stripe plugin settings
                $stripe_settings = get_option('woocommerce_stripe_settings', []);

                // Check if test mode is enabled
                $is_test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';

                // Pick the right secret key based on mode
                if ($is_test_mode) {
                    $secret_key = $stripe_settings['test_secret_key'] ?? '';
                } else {
                    $secret_key = $stripe_settings['secret_key'] ?? '';
                }

                // Set the Stripe API key
                if (!empty($secret_key)) {
                    \Stripe\Stripe::setApiKey($secret_key);
                } else {
                    error_log('Stripe secret key is missing! Check WooCommerce Stripe settings.');
                }
                // Create a PaymentIntent manually
                $payment_intent = \Stripe\PaymentIntent::create([
                    'amount'              => intval($order->get_total() * 100), // Stripe uses cents
                    'currency'            => strtolower(get_woocommerce_currency()),
                    'payment_method'      => $stripe_payment_method_id,
                    'confirmation_method' => 'automatic',
                    'confirm'             => true, // charges immediately
                    'off_session'         => true, // avoids requiring auth if possible
                    'metadata'            => [
                        'order_id' => $order_id,
                        'customer' => $user_id,
                    ],
                ]);

                if ($payment_intent->status === 'succeeded') {
                    $order->payment_complete($payment_intent->id);
                    WC()->cart->empty_cart();
                    $this->update_user_credit($order);
                    return new WP_REST_Response([
                        'success'        => true,
                        'message'        => __('Stripe payment succeeded.', 'oba-apis-integration'),
                        'order_id'       => $order_id,
                        'payment_intent' => $payment_intent->id,
                        'status'         => $order->get_status(),
                    ], 201);
                }

                // If not succeeded, require action
                return new WP_REST_Response([
                    'success'        => false,
                    'message'        => __('Payment requires further action.', 'oba-apis-integration'),
                    'order_id'       => $order_id,
                    'payment_intent' => $payment_intent,
                    'status'         => $order->get_status(),
                ], 400);
            } catch (\Stripe\Exception\CardException $e) {
                $order->update_status('failed', 'Stripe Card Error: ' . $e->getMessage());
                return new WP_Error('stripe_card_error', $e->getMessage(), ['status' => 402]);
            } catch (\Exception $e) {
                $order->update_status('failed', 'Stripe API Error: ' . $e->getMessage());
                return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
            }
        }

        /**
         * FALLBACK - Other gateways
         */
        return new WP_REST_Response([
            'success'  => true,
            'message'  => __('Order created. Awaiting payment.', 'oba-apis-integration'),
            'order_id' => $order_id,
            'status'   => $order->get_status(),
        ], 201);
    }

    /* -------------------------- Helpers -------------------------- */

    /**
     * Update user meta with billing and shipping addresses
     */
    private function update_user_addresses(int $user_id, array $billing, array $shipping)
    {
        if (empty($user_id)) {
            return;
        }

        // Update billing address meta
        $billing_fields = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'email', 'phone'
        ];

        foreach ($billing_fields as $field) {
            if (isset($billing[$field])) {
                update_user_meta($user_id, 'billing_' . $field, $billing[$field]);
            }
        }

        // Update shipping address meta
        $shipping_fields = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country'
        ];

        foreach ($shipping_fields as $field) {
            if (isset($shipping[$field])) {
                update_user_meta($user_id, 'shipping_' . $field, $shipping[$field]);
            }
        }

        // Also update WC_Customer object to keep it in sync
        if (class_exists('WC_Customer')) {
            $customer = new \WC_Customer($user_id);
            
            // Set billing address
            if (isset($billing['first_name'])) $customer->set_billing_first_name($billing['first_name']);
            if (isset($billing['last_name'])) $customer->set_billing_last_name($billing['last_name']);
            if (isset($billing['company'])) $customer->set_billing_company($billing['company']);
            if (isset($billing['address_1'])) $customer->set_billing_address_1($billing['address_1']);
            if (isset($billing['address_2'])) $customer->set_billing_address_2($billing['address_2']);
            if (isset($billing['city'])) $customer->set_billing_city($billing['city']);
            if (isset($billing['state'])) $customer->set_billing_state($billing['state']);
            if (isset($billing['postcode'])) $customer->set_billing_postcode($billing['postcode']);
            if (isset($billing['country'])) $customer->set_billing_country($billing['country']);
            if (isset($billing['email'])) $customer->set_billing_email($billing['email']);
            if (isset($billing['phone'])) $customer->set_billing_phone($billing['phone']);

            // Set shipping address
            if (isset($shipping['first_name'])) $customer->set_shipping_first_name($shipping['first_name']);
            if (isset($shipping['last_name'])) $customer->set_shipping_last_name($shipping['last_name']);
            if (isset($shipping['company'])) $customer->set_shipping_company($shipping['company']);
            if (isset($shipping['address_1'])) $customer->set_shipping_address_1($shipping['address_1']);
            if (isset($shipping['address_2'])) $customer->set_shipping_address_2($shipping['address_2']);
            if (isset($shipping['city'])) $customer->set_shipping_city($shipping['city']);
            if (isset($shipping['state'])) $customer->set_shipping_state($shipping['state']);
            if (isset($shipping['postcode'])) $customer->set_shipping_postcode($shipping['postcode']);
            if (isset($shipping['country'])) $customer->set_shipping_country($shipping['country']);

            $customer->save();
        }
    }

    private function update_user_credit($order)
    {
        $chargeable = false;
        $total = 0;
        $wallet_id = get_option('wps_wsfw_rechargeable_product_id', '');

        // Check if order contains the wallet product
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!empty($product_id) && (int) $product_id === (int) $wallet_id) {
                $chargeable = true;
                $total = (float) $item->get_total();
                break;
            }
        }

        if (!$chargeable) {
            return;
        }

        $wps_keys = get_option('wps_wsfw_wallet_rest_api_keys');
        if (empty($wps_keys['consumer_key']) || empty($wps_keys['consumer_secret'])) {
            return;
        }

        $user_id = $order->get_user_id();
        if (empty($user_id)) {
            return;
        }

        $url = home_url("/wp-json/wsfw-route/v1/wallet/{$user_id}");

        $body = [
            'amount'             => $total,
            'action'             => 'credit',
            'consumer_key'       => $wps_keys['consumer_key'],
            'consumer_secret'    => $wps_keys['consumer_secret'],
            'transaction_detail' => sprintf('Wallet top-up from order #%d', $order->get_id()),
            'payment_method'     => $order->get_payment_method(),
            'note'               => 'Auto wallet credit after purchase',
            'order_id'           => $order->get_id(),
        ];

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200 || $status_code === 201) {
            $order->update_status('completed', __('Auto-completed because it contains wallet credit product.', 'oba-apis-integration'));
        } else {
            return;
        }
        return;
    }

    private function sanitize_address(array $addr): array
    {
        $out = [];
        foreach ($addr as $k => $v) {
            $out[$k] = is_string($v) ? sanitize_text_field($v) : $v;
        }
        // keep expected keys explicit if needed
        return $out;
    }

    private function prefix_address(array $addr, string $prefix): array
    {
        $out = [];
        foreach ($addr as $k => $v) {
            $out[$prefix . $k] = $v;
        }
        return $out;
    }

    private function get_available_payment_methods(): array
    {
        $payment_methods = [];
        if (class_exists('WC_Payment_Gateways')) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            foreach ($gateways as $gateway) {
                if ($gateway->enabled === 'yes') {
                    $payment_methods[$gateway->id] = [
                        'id'           => $gateway->id,
                        'title'        => $gateway->title,
                        'description'  => wp_kses_post($gateway->description),
                        'method_title' => $gateway->method_title,
                    ];
                }
            }
        }
        // Fallback examples (optional)
        if (empty($payment_methods)) {
            $payment_methods = [
                'stripe' => [
                    'id'           => 'stripe',
                    'title'        => 'Credit Card (Stripe)',
                    'description'  => 'Pay securely with your card.',
                    'method_title' => 'Stripe',
                ],
                'cod'    => [
                    'id'           => 'cod',
                    'title'        => 'Cash on Delivery',
                    'description'  => 'Pay when you receive your order.',
                    'method_title' => 'Cash on Delivery',
                ],
            ];
        }
        return $payment_methods;
    }

    private function get_user_addresses(int $user_id): array
    {
        $addresses = ['billing' => [], 'shipping' => []];
        if (class_exists('WC_Customer')) {
            $c = new \WC_Customer($user_id);
            $addresses['billing'] = [
                'first_name' => $c->get_billing_first_name(),
                'last_name'  => $c->get_billing_last_name(),
                'company'    => $c->get_billing_company(),
                'address_1'  => $c->get_billing_address_1(),
                'address_2'  => $c->get_billing_address_2(),
                'city'       => $c->get_billing_city(),
                'state'      => $c->get_billing_state(),
                'postcode'   => $c->get_billing_postcode(),
                'country'    => $c->get_billing_country(),
                'email'      => $c->get_billing_email(),
                'phone'      => $c->get_billing_phone(),
            ];
            $addresses['shipping'] = [
                'first_name' => $c->get_shipping_first_name(),
                'last_name'  => $c->get_shipping_last_name(),
                'company'    => $c->get_shipping_company(),
                'address_1'  => $c->get_shipping_address_1(),
                'address_2'  => $c->get_shipping_address_2(),
                'city'       => $c->get_shipping_city(),
                'state'      => $c->get_shipping_state(),
                'postcode'   => $c->get_shipping_postcode(),
                'country'    => $c->get_shipping_country(),
            ];
        }
        return $addresses;
    }

    private function validate_address(array $address, string $type): array
    {
        $errors = [];
        $required = ('billing' === $type)
            ? ['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email']
            : ['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country'];

        foreach ($required as $field) {
            if (empty($address[$field])) {
                $errors[$field] = sprintf(__('%s is required.', 'oba-apis-integration'), ucfirst(str_replace('_', ' ', $field)));
            }
        }

        if ('billing' === $type && !empty($address['email']) && !is_email($address['email'])) {
            $errors['email'] = __('Invalid email format.', 'oba-apis-integration');
        }

        if (!empty($address['phone']) && !preg_match('/^[\+]?[0-9][\d]{5,15}$/', $address['phone'])) {
            // phone optional but if provided validate roughly
            $errors['phone'] = __('Invalid phone number format.', 'oba-apis-integration');
        }

        return $errors;
    }

    private function format_cart(): array
    {
        $rechargeable_product_id = get_option('wps_wsfw_rechargeable_product_id');
        $virtual = false;
        $items = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $product = $item['data'];
            if ($product->get_id() == $rechargeable_product_id) {
                $virtual = true;
            }
            $items[] = [
                'cart_item_key' => $key,
                'product_id'    => $product->get_id(),
                'name'          => $product->get_name(),
                'quantity'      => (int)$item['quantity'],
                'price'         => (float)$product->get_price(),
                'subtotal'      => (float)$item['line_subtotal'],
                'total'         => (float)$item['line_total'],
                'thumbnail'     => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
            ];
        }

        // Collect fees
        $fees = [];
        foreach (WC()->cart->get_fees() as $fee) {
            $fees[] = [
                'id'     => $fee->id,
                'name'   => $fee->name,
                'amount' => wc_format_decimal($fee->amount, wc_get_price_decimals()),
                'tax'    => wc_format_decimal($fee->tax, wc_get_price_decimals()),
                'total'  => wc_format_decimal($fee->total, wc_get_price_decimals()),
            ];
        }

        // Collect taxes (detailed breakdown like frontend)
        $taxes = [];
        foreach (WC()->cart->get_tax_totals() as $code => $tax) {
            $taxes[] = [
                'code'   => $code,
                'label'  => $tax->label,
                'amount' => $tax->amount,
            ];
        }

        return [
            'items'      => $items,
            'subtotal'   => WC()->cart->get_subtotal(),
            'shipping'   => WC()->cart->get_shipping_total(),
            'discount'   => WC()->cart->get_discount_total(),
            'tax'        => WC()->cart->get_total_tax(),
            'total'      => WC()->cart->get_total('edit'),
            'currency'   => get_woocommerce_currency(),
            'item_count' => WC()->cart->get_cart_contents_count(),
            'fees'       => $fees,
            'taxes'      => $taxes,
            'virtual'    => $virtual,
        ];
    }

    private function format_order(\WC_Order $order, bool $detailed = false): array
    {
        $data = [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'date_created'         => $order->get_date_created() ? $order->get_date_created()->format('c') : null,
            'date_modified'        => $order->get_date_modified() ? $order->get_date_modified()->format('c') : null,
            'total'                => $order->get_total(),
            'currency'             => $order->get_currency(),
            'customer_id'          => $order->get_customer_id(),
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'shipping_method'      => $order->get_shipping_method(),
            'subtotal'             => $order->get_subtotal(),
            'shipping_total'       => $order->get_shipping_total(),
            'tax_total'            => $order->get_total_tax(),
            'discount_total'       => $order->get_total_discount(),
            'billing_address'      => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ],
            'shipping_address'     => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'company'    => $order->get_shipping_company(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ],
        ];

        if ($detailed) {
            $line_items = [];
            foreach ($order->get_items() as $item) {
                $line_items[] = [
                    'product_id'   => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'name'         => $item->get_name(),
                    'quantity'     => $item->get_quantity(),
                    'subtotal'     => $item->get_subtotal(),
                    'total'        => $item->get_total(),
                ];
            }
            $data['items'] = $line_items;
        }

        return $data;
    }

    /**
     * Helper: list methods calculated for current packages (for UI hints)
     */
    private function list_current_package_methods(): array
    {
        $result = [];
        $packages = WC()->shipping()->get_packages();
        foreach ($packages as $idx => $package) {
            $pkgRates = [];
            if (!empty($package['rates'])) {
                foreach ($package['rates'] as $rate_id => $rate_obj) {
                    $pkgRates[] = [
                        'id'    => $rate_id,
                        'label' => $rate_obj->get_label(),
                        'cost'  => wc_price($rate_obj->get_cost()),
                    ];
                }
            }
            $result[] = [
                'package_index' => $idx,
                'rates'         => $pkgRates,
            ];
        }
        return $result;
    }

    /**
     * Helper: collect rates in a response-friendly structure (multi-package ready)
     */
    private function collect_package_rates(): array
    {
        $packages_out = [];
        $packages = WC()->shipping()->get_packages();

        foreach ($packages as $idx => $package) {
            $rates = [];
            foreach ($package['rates'] as $rate_id => $rate) {
                $rates[] = [
                    'id'        => $rate_id,                   // e.g. shipstation:1:GROUND
                    'label'     => $rate->get_label(),         // e.g. UPS® Ground
                    'cost'      => wc_price($rate->get_cost()),
                    'raw_cost'  => $rate->get_cost(),
                    'taxes'     => $rate->get_taxes(),
                    'method_id' => $rate->get_method_id(),
                ];
            }
            $packages_out[] = [
                'package_index' => $idx,
                'destination'   => $package['destination'],
                'rates'         => $rates,
                'chosen'        => WC()->session->get('chosen_shipping_methods')[$idx] ?? null,
            ];
        }

        return ['packages' => $packages_out];
    }

    /**
     * Check if a coupon is valid without applying it
     */
    public function check_coupon(WP_REST_Request $request)
    {
        $code = sanitize_text_field($request->get_param('coupon_code'));

        if (empty($code)) {
            return new WP_Error('coupon_code_required', 'Coupon code is required', ['status' => 400]);
        }

        $coupon = new WC_Coupon($code);

        if (!$coupon->get_id()) {
            return new WP_Error('invalid_coupon', 'Invalid coupon code', ['status' => 404]);
        }

        // Check coupon validity against current cart
        $discounts = new WC_Discounts(WC()->cart);
        $validity = $discounts->is_coupon_valid($coupon);

        if (is_wp_error($validity)) {
            return $validity; // Return detailed WC error
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Coupon is valid',
            'coupon'  => [
                'code'   => $coupon->get_code(),
                'amount' => $coupon->get_amount(),
                'type'   => $coupon->get_discount_type(),
                'expiry' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d H:i:s') : null
            ]
        ], 200);
    }

    /**
     * Apply a coupon to the cart
     */
    public function apply_coupon(WP_REST_Request $request)
    {
        $code = sanitize_text_field($request->get_param('coupon_code'));

        if (empty($code)) {
            return new WP_Error('coupon_code_required', 'Coupon code is required', ['status' => 400]);
        }

        if (!WC()->cart->apply_coupon($code)) {
            return new WP_Error('coupon_apply_failed', 'Coupon could not be applied', ['status' => 400]);
        }

        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Coupon applied successfully',
            'totals'  => WC()->cart->get_totals()
        ], 200);
    }

    /**
     * Remove a coupon from the cart
     */
    public function remove_coupon(WP_REST_Request $request)
    {
        $code = sanitize_text_field($request->get_param('coupon_code'));

        if (empty($code)) {
            return new WP_Error('coupon_code_required', 'Coupon code is required', ['status' => 400]);
        }

        WC()->cart->remove_coupon($code);
        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Coupon removed successfully',
            'totals'  => WC()->cart->get_totals()
        ], 200);
    }
}
