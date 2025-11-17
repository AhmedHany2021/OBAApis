<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

/**
 * Comprehensive Membership Service for Paid Memberships Pro Integration
 *
 * This service provides all membership-related functionality for mobile applications
 * including checkout, signup, cancellation, upgrades, and profile management.
 *
 * @package OBA\APIsIntegration\Services
 */


class MembershipService {

    /**
     * Map Apple product ID to PMPro membership level ID
     *
     * @param string $productId Apple product ID (e.g., 'com.yourapp.membership.monthly')
     * @return int|false PMPro level ID or false if not found
     */
    /**
     * Map Apple product ID to PMPro membership level ID
     *
     * @param string $productId Apple product ID (e.g., 'com.yourapp.membership.monthly')
     * @return int|false PMPro level ID or false if not found
     */
    public function mapProductToLevel($productId) {
        // Get all PMPro levels
        $levels = pmpro_getAllLevels(true, true);
        
        if (empty($levels)) {
            return false;
        }
        
        // Define a mapping of product IDs to level IDs
        // You should customize this based on your actual product IDs
        $productToLevelMap = [
            // Example: 'com.yourapp.membership.monthly' => 1,
            // Add your product ID to level ID mappings here
        ];
        
        // Check if we have a direct mapping
        if (isset($productToLevelMap[$productId])) {
            return $productToLevelMap[$productId];
        }
        
        // Fallback: Try to find a level with a matching name or ID in the product ID
        foreach ($levels as $level) {
            // Check if product ID contains level ID or name (case-insensitive)
            if (stripos($productId, (string)$level->id) !== false || 
                (isset($level->name) && stripos($productId, $level->name) !== false)) {
                return $level->id;
            }
        }
        
        // If no match found, return the first level as fallback (or false if you prefer)
        return !empty($levels) ? reset($levels)->id : false;
    }
    
    /**
     * Activate a membership for a user
     *
     * @param int $userId WordPress user ID
     * @param int $levelId PMPro level ID
     * @param string $endDate Membership end date in Y-m-d H:i:s format
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function activateMembership($userId, $levelId, $endDate) {
        try {
            // Check if user exists
            if (!get_user_by('ID', $userId)) {
                return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
            }
            
            // Check if level exists
            $level = pmpro_getLevel($levelId);
            if (empty($level)) {
                return new WP_Error('invalid_level', 'Invalid membership level', ['status' => 400]);
            }
            
            // Create membership record
            $result = pmpro_changeMembershipLevel($levelId, $userId, 'active');
            
            if ($result === false) {
                return new WP_Error('membership_error', 'Failed to activate membership', ['status' => 500]);
            }
            
            // Update end date if provided
            if ($endDate) {
                update_user_meta($userId, 'pmpro_next_payment_date', $endDate);
                
                // Also update the membership end date in the pmpro_memberships_users table
                global $wpdb;
                $wpdb->update(
                    $wpdb->pmpro_memberships_users,
                    ['enddate' => $endDate],
                    [
                        'user_id' => $userId,
                        'membership_id' => $levelId,
                        'status' => 'active'
                    ],
                    ['%s'],
                    ['%d', '%d', '%s']
                );
            }
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error('membership_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Renew a user's membership
     *
     * @param int $userId WordPress user ID
     * @param int $levelId PMPro level ID
     * @param string $endDate New end date in Y-m-d H:i:s format
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function renewMembership($userId, $levelId, $endDate) {
        try {
            // Check if user has an active membership
            $userLevel = pmpro_getMembershipLevelForUser($userId);
            
            if (empty($userLevel) || $userLevel->ID != $levelId) {
                // If user doesn't have this level, activate it
                return $this->activateMembership($userId, $levelId, $endDate);
            }
            
            // Update the end date
            update_user_meta($userId, 'pmpro_next_payment_date', $endDate);
            
            // Update the membership end date in the pmpro_memberships_users table
            global $wpdb;
            $result = $wpdb->update(
                $wpdb->pmpro_memberships_users,
                [
                    'enddate' => $endDate,
                    'modified' => current_time('mysql')
                ],
                [
                    'user_id' => $userId,
                    'membership_id' => $levelId,
                    'status' => 'active'
                ],
                ['%s', '%s'],
                ['%d', '%d', '%s']
            );
            
            if ($result === false) {
                return new WP_Error('membership_error', 'Failed to update membership end date', ['status' => 500]);
            }
            
            // Trigger any hooks that might be needed for renewal
            do_action('pmpro_after_change_membership_level', $levelId, $userId, null);
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error('membership_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Cancel a user's membership
     *
     * @param int $userId WordPress user ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function cancelMembership($userId) {
        try {
            // Get user's current level
            $userLevel = pmpro_getMembershipLevelForUser($userId);
            
            if (empty($userLevel)) {
                // User doesn't have an active membership
                return true;
            }
            
            // Cancel the membership
            $result = pmpro_cancelMembershipLevel($userLevel->ID, $userId, 'cancelled');
            
            if ($result === false) {
                return new WP_Error('membership_error', 'Failed to cancel membership', ['status' => 500]);
            }
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error('membership_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Get comprehensive checkout fields including group fields based on level
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_checkout_fields($request) {
        $level_id = absint($request->get_param('level_id'));
        if (!$level_id) {
            return new WP_Error('invalid_level_id', __('Valid level ID is required.', 'oba-apis-integration'), ['status' => 400]);
        }

        // Get membership level
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return new WP_Error('level_not_found', __('Membership level not found.', 'oba-apis-integration'), ['status' => 404]);
        }

        // Check if level is in a group
        $group_info = $this->get_level_group_info($level_id);

        // Get available payment gateways
        $gateways = function_exists('pmpro_gateways') ? pmpro_gateways() : [];
        $primary_gateway = function_exists('pmpro_getOption') ? pmpro_getOption('gateway') : '';

        // Determine if billing is required
        $require_billing = ($level->initial_payment > 0) || ($level->billing_amount > 0);

        // Get custom user fields
        $custom_fields = $this->get_custom_user_fields();

        // Get billing fields configuration
        $billing_fields = $this->get_billing_fields_config($require_billing);

        // Get countries and states for dropdowns
        $countries = $this->get_countries_list();
        $states = $this->get_states_list();

        $response = [
            'level' => [
                'id' => $level->id,
                'name' => $level->name,
                'description' => $level->description,
                'initial_payment' => (float) $level->initial_payment,
                'billing_amount' => (float) $level->billing_amount,
                'cycle_number' => (int) $level->cycle_number,
                'cycle_period' => $level->cycle_period,
                'billing_limit' => (int) $level->billing_limit,
                'trial_amount' => (float) $level->trial_amount,
                'trial_limit' => (int) $level->trial_limit,
                'expiration_number' => (int) $level->expiration_number,
                'expiration_period' => $level->expiration_period,
                'allow_signups' => (bool) $level->allow_signups,
            ],
            'group' => $group_info,
            'user_fields' => [
                'required' => ['username', 'email', 'password', 'first_name', 'last_name'],
                'custom' => $custom_fields
            ],
            'billing_required' => $require_billing,
            'billing_fields' => $billing_fields,
        ];

        return new WP_REST_Response(['success' => true, 'data' => $response], 200);
    }

    /**
     * Sens verification code to Email
     */
    public function send_verification_email($request)
    {
        $params = $request->get_params();
        $email = isset($params['email']) ? $params['email'] : null;
        if (!$email || !is_email($email)) {
            return new WP_Error('invalid_payload', __('Invalid Email', 'oba-apis-integration'), ['status' => 400]);
        }

        if (email_exists($email)) {
            return new WP_Error('existing_email', __('This email already exists', 'oba-apis-integration'), ['status' => 400]);
        }

        $email = sanitize_email($email);

        global $wpdb;
        $table_name = $wpdb->prefix . 'pmpro_email_otp';
        $code = sprintf('%06d', rand(0, 999999));
        $wpdb->delete($table_name, ['email' => $email], ['%s']);
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $wpdb->insert(
            $table_name,
            [
                'email' => $email,
                'code' => $code,
                'verified' => 0,
                'expires_at' => $expires_at
            ],
            ['%s', '%s', '%d', '%s']
        );

        // Send email
        $subject = 'Your Verification Code';
        $message = "Your verification code is: " . $code . "\n\n";
        $message .= "This code will expire in 15 minutes.\n\n";
        $message .= "If you did not request this code, please ignore this email.\n\n";

        $sent = wp_mail($email, $subject, $message);

        if ($sent) {
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Verification email Sent', 'oba-apis-integration'),
            ]);
        } else {
            return new WP_Error('failed_sending_email', __('Failed to send email. Please try again.', 'oba-apis-integration'), ['status' => 400]);

        }
    }

    /**
     * Verify the code
     */

    public function verify_code($request)
    {
        $params = $request->get_params();
        $code = isset($params['code']) ? $params['code'] : null;
        $email = isset($params['email']) ? $params['email'] : null;
        if (!$email || !$code)
        {
            return new WP_Error('invalid_payload', __('Invalid Email or code', 'oba-apis-integration'), ['status' => 400]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'pmpro_email_otp';

        $email = sanitize_email($email);
        $code = sanitize_text_field($code);

        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name}
            WHERE email = %s AND code = %s AND verified = 0 AND expires_at > NOW()",
            $email,
            $code
        ));

        if (!$verification) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid Code', 'oba-apis-integration'),
            ]);
        }

        // Mark as verified
        $wpdb->update(
            $table_name,
            ['verified' => 1, 'verified_at' => current_time('mysql')],
            ['id' => $verification->id],
            ['%d', '%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Verification code is right', 'oba-apis-integration'),
        ]);

    }

    /**
     * Process membership signup with Stripe integration
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function process_signup($request) {
        $params = $request->get_json_params();
        $source = isset($params['source']) ? $params['source'] : null;
        if (empty($params)) {
            return new WP_Error('invalid_payload', __('Checkout payload is required.', 'oba-apis-integration'), ['status' => 400]);
        }

        // Validate required parameters
        $required_fields = ['level_id', 'email', 'password', 'username'];
        foreach ($required_fields as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'oba-apis-integration'), $field), ['status' => 400]);
            }
        }

        $level_id = absint($params['level_id']);
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return new WP_Error('level_not_found', __('Membership level not found.', 'oba-apis-integration'), ['status' => 404]);
        }

        // Check if user already exists
        $existing_user = get_user_by('email', sanitize_email($params['email']));
        if ($existing_user) {
            return new WP_Error('user_exists', __('User with this email already exists.', 'oba-apis-integration'), ['status' => 409]);
        }

        // Validate payment method for paid levels
        if (($level->initial_payment > 0 || $level->billing_amount > 0) && !$source) {
            if (empty($params['payment_method_id'])) {
                return new WP_Error('payment_required', __('Payment method is required for paid membership.', 'oba-apis-integration'), ['status' => 400]);
            }
        }

        // Create user account
        $user_data = [
            'user_login' => sanitize_user($params['username']),
            'user_email' => sanitize_email($params['email']),
            'user_pass' => (string) $params['password'],
            'role' => 'subscriber',
            'show_admin_bar_front' => false
        ];


        $user_id = wp_insert_user($user_data);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Set custom fields
        if (!empty($params['custom_fields']) && is_array($params['custom_fields'])) {
            $this->set_custom_fields($user_id, $params['custom_fields']);
        }


        // Process payment and create membership
        if (($level->initial_payment > 0 || $level->billing_amount > 0) && !$source) {
            $result = $this->process_paid_signup($user_id, $level, $params);
            if (is_wp_error($result)) {
                // Clean up user if payment fails
                wp_delete_user($user_id);
                return $result;
            }
        } else {
            // Free membership
            if (!$source) {
                $source = 'free';
            }
            $result = $this->process_free_signup($user_id, $level ,$params , $source);
            if (is_wp_error($result)) {
                wp_delete_user($user_id);
                return $result;
            }
        }

        // Get final membership status
        $membership_level = pmpro_getMembershipLevelForUser($user_id);

        // Save user first and last name from custom fields
        if (!empty($params['custom_fields'])) {
            if (!empty($params['custom_fields']['Patient_first_name'])) {
                update_user_meta($user_id, 'first_name', sanitize_text_field($params['custom_fields']['Patient_first_name']));
            }
            if (!empty($params['custom_fields']['Patient_last_name'])) {
                update_user_meta($user_id, 'last_name', sanitize_text_field($params['custom_fields']['Patient_last_name']));
            }

            // Build and save billing address from custom fields
            $billing_data = [
                'first_name' => $params['custom_fields']['Patient_first_name'] ?? '',
                'last_name' => $params['custom_fields']['Patient_last_name'] ?? '',
                'address_1' => $params['custom_fields']['street_address'] ?? '',
                'city' => $params['custom_fields']['town_city'] ?? '',
                'state' => $params['custom_fields']['Patient_State'] ?? '',
                'postcode' => $params['custom_fields']['postcode_zip'] ?? '',
                'country' => $params['custom_fields']['Patient_Country'] ?? '',
                'phone' => $params['custom_fields']['phone_paidmembership'] ?? ''
            ];
            
            $this->set_user_billing_address($user_id, $billing_data);
            
            // Build and save shipping address from custom fields (same as billing by default)
            $shipping_data = [
                'first_name' => $params['custom_fields']['Patient_first_name'] ?? '',
                'last_name' => $params['custom_fields']['Patient_last_name'] ?? '',
                'address_1' => $params['custom_fields']['street_address'] ?? '',
                'city' => $params['custom_fields']['town_city'] ?? '',
                'state' => $params['custom_fields']['Patient_State'] ?? '',
                'postcode' => $params['custom_fields']['postcode_zip'] ?? '',
                'country' => $params['custom_fields']['Patient_Country'] ?? '',
                'phone' => $params['custom_fields']['phone_paidmembership'] ?? ''
            ];
            
            $this->set_user_shipping_address($user_id, $shipping_data);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Membership created successfully.', 'oba-apis-integration'),
            'data' => [
                'user_id' => $user_id,
                'membership_level' => $membership_level ? [
                    'id' => $membership_level->id,
                    'name' => $membership_level->name,
                    'startdate' => $membership_level->startdate,
                    'enddate' => $membership_level->enddate
                ] : null,
                'order_id' => $result['order_id'] ?? null,
                'subscription_id' => $result['subscription_id'] ?? null
            ]
        ], 201);
    }

    /**
     * Cancel user membership
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function cancel_membership($request) {
        $user = $request->get_param('current_user');
        if (!$user) {
            return new WP_Error('unauthorized', __('User not authenticated.', 'oba-apis-integration'), ['status' => 401]);
        }

        $user_id = (int) $user->ID;
        $membership_level = pmpro_getMembershipLevelForUser($user_id);

        if (!$membership_level) {
            return new WP_Error('no_membership', __('User has no active membership to cancel.', 'oba-apis-integration'), ['status' => 400]);
        }

        // Get cancellation parameters
        $params = $request->get_json_params();
        $cancel_at_period_end = !empty($params['cancel_at_period_end']);
        $reason = !empty($params['reason']) ? sanitize_text_field($params['reason']) : '';

        // Process cancellation
        if (function_exists('pmpro_changeMembershipLevel')) {
            $result = pmpro_changeMembershipLevel(0, $user_id, 'cancelled');

            if ($result !== false) {
                // Log cancellation reason
                if ($reason) {
                    update_user_meta($user_id, 'pmpro_cancellation_reason', $reason);
                }

                // Send cancellation email
                if (function_exists('pmpro_send_cancellation_email')) {
                    pmpro_send_cancellation_email($user_id);
                }

                return new WP_REST_Response([
                    'success' => true,
                    'message' => __('Membership cancelled successfully.', 'oba-apis-integration'),
                    'data' => [
                        'cancelled_at' => current_time('mysql'),
                        'reason' => $reason,
                        'cancel_at_period_end' => $cancel_at_period_end
                    ]
                ], 200);
            }
        }

        return new WP_Error('cancellation_failed', __('Failed to cancel membership.', 'oba-apis-integration'), ['status' => 500]);
    }

    /**
     * Upgrade or change membership level
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function change_membership($request) {
        $user = $request->get_param('current_user');
        if (!$user) {
            return new WP_Error('unauthorized', __('User not authenticated.', 'oba-apis-integration'), ['status' => 401]);
        }

        $user_id = (int) $user->ID;
        $params = $request->get_json_params();

        if (empty($params['new_level_id'])) {
            return new WP_Error('missing_level', __('New level ID is required.', 'oba-apis-integration'), ['status' => 400]);
        }

        $new_level_id = absint($params['new_level_id']);
        $new_level = pmpro_getLevel($new_level_id);

        if (!$new_level) {
            return new WP_Error('level_not_found', __('New membership level not found.', 'oba-apis-integration'), ['status' => 404]);
        }

        $current_level = pmpro_getMembershipLevelForUser($user_id);

        if ($current_level && $current_level->id === $new_level->id) {
            return new WP_Error('same_level', __('User is already on this membership level.', 'oba-apis-integration'), ['status' => 400]);
        }

//        if ($current_level && $current_level->id > $new_level->id) {
//            return new WP_Error('downgrade_not_supported', __('Downgrading membership levels is not supported through this endpoint. Please contact support.', 'oba-apis-integration'), ['status' => 400]);
//        }

        $upgrade_cost = $this->calculate_upgrade_cost($current_level, $new_level);

        // Detect source: stripe (default), free, android, ios
        $source = $params['source'] ?? 'stripe';
        $result = null;

        if ($upgrade_cost <= 0 && $source === 'free') {
            $result = $this->process_free_upgrade($user_id, $new_level);
        } elseif (in_array($source, ['android', 'ios'], true)) {
            $result = $this->process_in_app_upgrade($user_id, $new_level, $params, $source);
        } else {
            // Stripe (requires payment_method_id if cost > 0)
            if ($upgrade_cost > 0 && empty($params['payment_method_id'])) {
                return new WP_Error('payment_required', __('Payment method is required for paid membership upgrade.', 'oba-apis-integration'), ['status' => 400]);
            }
            $result = $this->process_membership_upgrade($user_id, $current_level, $new_level, $params);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $updated_level = pmpro_getMembershipLevelForUser($user_id);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Membership upgraded successfully.', 'oba-apis-integration'),
            'data' => [
                'previous_level' => $current_level ? [
                    'id' => $current_level->id,
                    'name' => $current_level->name,
                    'billing_amount' => (float) $current_level->billing_amount,
                    'initial_payment' => (float) $current_level->initial_payment
                ] : null,
                'new_level' => $updated_level ? [
                    'id' => $updated_level->id,
                    'name' => $updated_level->name,
                    'startdate' => $updated_level->startdate,
                    'enddate' => $updated_level->enddate,
                    'billing_amount' => (float) $updated_level->billing_amount,
                    'initial_payment' => (float) $updated_level->initial_payment
                ] : null,
                'upgrade_cost' => $upgrade_cost,
                'order_id' => $result['order_id'] ?? null,
                'subscription_id' => $result['subscription_id'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
                'stripe_payment_intent_id' => $result['stripe_payment_intent_id'] ?? null,
            ]
        ], 200);
    }



    private function process_in_app_upgrade($user_id, $new_level, $params, $source) {
        if (!function_exists('pmpro_changeMembershipLevel')) {
            return new \WP_Error('pmpro_missing', __('PMPro not available.', 'oba-apis-integration'));
        }

        // Verify receipt first (stub — you’d replace with actual Apple/Google verification)
        $transaction_id = $params['transaction_id'] ?? null;
        $receipt_data   = $params['receipt'] ?? null;

        if (!$transaction_id || !$receipt_data) {
            return new \WP_Error('invalid_iap', __('Missing transaction or receipt data for in-app purchase.', 'oba-apis-integration'));
        }

        // 🔒 Here you should call your receipt validation function
        // $verified = $this->verify_iap_receipt($receipt_data, $source);
        // if (!$verified) {
        //     return new \WP_Error('iap_verification_failed', __('In-app purchase verification failed.', 'oba-apis-integration'));
        // }

        // Create PMPro order for tracking
        $order = new \MemberOrder();
        $order->user_id       = $user_id;
        $order->membership_id = $new_level->id;
        $order->gateway       = ($source === 'android') ? 'iap_android' : 'iap_ios';
        $order->billing       = null;

        // Payment info
        $order->subtotal = 0; // handled externally by app store
        $order->tax      = 0;
        $order->total    = 0;
        $order->status   = 'success';
        $order->payment_type = 'in_app_purchase';
        $order->payment_transaction_id = $transaction_id;

        // Save order
        $order->saveOrder();

        // Apply new membership level
        $result = pmpro_changeMembershipLevel($new_level->id, $user_id, 'in-app-upgrade');
        if ($result === false) {
            return new \WP_Error('upgrade_failed', __('Failed to apply new membership level.', 'oba-apis-integration'));
        }

        return [
            'order_id' => $order->id,
            'subscription_id' => null,
            'transaction_id' => $transaction_id,
        ];
    }


    /**
     * Get user membership profile information
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_user_profile($request) {
        $user = $request->get_param('current_user');
        if (!$user) {
            return new WP_Error('unauthorized', __('User not authenticated.', 'oba-apis-integration'), ['status' => 401]);
        }

        $user_id = (int) $user->ID;

        // Get current membership level
        $membership_level = pmpro_getMembershipLevelForUser($user_id);

        // Get user data
        $user_data = get_userdata($user_id);

        // Get billing address
        $billing_address = $this->get_user_billing_address($user_id);

        // Get custom fields
        $custom_fields = $this->get_user_custom_fields($user_id);

        // Get membership history
        $membership_history = $this->get_membership_history($user_id);

        // Get recent orders
        $recent_orders = $this->get_user_recent_orders($user_id, 5);

        $profile_data = [
            'user' => [
                'id' => $user_id,
                'username' => $user_data->user_login,
                'email' => $user_data->user_email,
                'first_name' => $user_data->first_name,
                'last_name' => $user_data->last_name,
                'display_name' => $user_data->display_name,
                'company' => get_user_meta($user_id, 'billing_company', true),
                'phone' => get_user_meta($user_id, 'billing_phone', true),
                'date_created' => $user_data->user_registered,
                'last_login' => get_user_meta($user_id, 'last_login', true)
            ],
            'membership' => $membership_level ? [
                'id' => $membership_level->id,
                'name' => $membership_level->name,
                'description' => $membership_level->description,
                'startdate' => $membership_level->startdate,
                'enddate' => $membership_level->enddate,
                'status' => $this->get_membership_status($membership_level),
                'can_cancel' => $this->can_user_cancel_membership($user_id),
                'can_upgrade' => $this->can_user_upgrade_membership($user_id),
                'group' => $this->get_level_group_info($membership_level->id)
            ] : null,
            'billing_address' => $billing_address,
            'custom_fields' => $custom_fields,
            'membership_history' => $membership_history,
            'recent_orders' => $recent_orders
        ];

        return new WP_REST_Response([
            'success' => true,
            'data' => $profile_data
        ], 200);
    }

    /**
     * Update user profile information
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_profile_fields($request) {
        $user = $request->get_param('current_user');
        if (!$user) {
            return new WP_Error('unauthorized', __('User not authenticated.', 'oba-apis-integration'), ['status' => 401]);
        }

        $user_id = (int) $user->ID;
        $params = $request->get_json_params();

        if (empty($params)) {
            return new WP_Error('invalid_payload', __('Update data is required.', 'oba-apis-integration'), ['status' => 400]);
        }

        $updated_fields = [];
        $errors = [];

        // Update basic user fields
        if (!empty($params['first_name'])) {
            $first_name = sanitize_text_field($params['first_name']);
            if (update_user_meta($user_id, 'first_name', $first_name)) {
                $updated_fields['first_name'] = $first_name;
            }
        }

        if (!empty($params['last_name'])) {
            $last_name = sanitize_text_field($params['last_name']);
            if (update_user_meta($user_id, 'last_name', $last_name)) {
                $updated_fields['last_name'] = $last_name;
            }
        }

        if (!empty($params['display_name'])) {
            $display_name = sanitize_text_field($params['display_name']);
            if (wp_update_user(['ID' => $user_id, 'display_name' => $display_name])) {
                $updated_fields['display_name'] = $display_name;
            }
        }

        // Update billing address
        if (!empty($params['billing']) && is_array($params['billing'])) {
            $billing_result = $this->update_user_billing_address($user_id, $params['billing']);
            if (is_wp_error($billing_result)) {
                $errors[] = $billing_result->get_error_message();
            } else {
                $updated_fields['billing'] = $params['billing'];
            }
        }

        // Update custom fields
        if (!empty($params['custom_fields']) && is_array($params['custom_fields'])) {
            $custom_result = $this->update_user_custom_fields($user_id, $params['custom_fields']);
            if (is_wp_error($custom_result)) {
                $errors[] = $custom_result->get_error_message();
            } else {
                $updated_fields['custom_fields'] = $params['custom_fields'];
            }
        }

        if (empty($updated_fields)) {
            return new WP_Error('no_updates', __('No fields were updated.', 'oba-apis-integration'), ['status' => 400]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Profile updated successfully.', 'oba-apis-integration'),
            'data' => [
                'updated_fields' => $updated_fields,
                'errors' => $errors
            ]
        ], 200);
    }

    /**
     * Get available membership plans
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_plans($request) {
        $levels = pmpro_getAllLevels(true, true);
        $plans = [];
        $Membership_Level = new \PMPro_Membership_Level();

        foreach ($levels as $level) {
            $group_info = $this->get_level_group_info($level->id);
            $level_meta = $Membership_Level->get_membership_level( $level->id);
            $plans[] = [
                'id' => $level->id,
                'name' => $level->name,
                'description' => $level->description,
                'initial_payment' => (float) $level->initial_payment,
                'billing_amount' => (float) $level->billing_amount,
                'cycle_number' => (int) $level->cycle_number,
                'cycle_period' => $level->cycle_period,
                'billing_limit' => (int) $level->billing_limit,
                'trial_amount' => (float) $level->trial_amount,
                'trial_limit' => (int) $level->trial_limit,
                'expiration_number' => (int) $level->expiration_number,
                'expiration_period' => $level->expiration_period,
                'ios_purchase_id' => $level_meta->ios_purchase_id,
                'allow_signups' => (bool) $level->allow_signups,
                'group' => $group_info,
                'features' => $this->get_level_features($level->id)
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $plans
        ], 200);
    }

    /**
     * Get user membership status
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_status($request) {
        $user = $request->get_param('current_user');
        if (!$user) {
            return new WP_Error('unauthorized', __('User not authenticated.', 'oba-apis-integration'), ['status' => 401]);
        }

        $user_id = (int) $user->ID;
        $membership_level = pmpro_getMembershipLevelForUser($user_id);

        if (!$membership_level) {
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'has_membership' => false,
                    'message' => __('No active membership found.', 'oba-apis-integration')
                ]
            ], 200);
        }

        $status_data = [
            'has_membership' => true,
            'level' => [
                'id' => $membership_level->id,
                'name' => $membership_level->name,
                'description' => $membership_level->description,
                'startdate' => $membership_level->startdate,
                'enddate' => $membership_level->enddate,
                'status' => $this->get_membership_status($membership_level),
                'days_remaining' => $this->calculate_days_remaining($membership_level->enddate),
                'group' => $this->get_level_group_info($membership_level->id)
            ],
            'permissions' => [
                'can_cancel' => $this->can_user_cancel_membership($user_id),
                'can_upgrade' => $this->can_user_upgrade_membership($user_id),
                'can_downgrade' => $this->can_user_downgrade_membership($user_id)
            ]
        ];

        return new WP_REST_Response([
            'success' => true,
            'data' => $status_data
        ], 200);
    }

    // ============================================================================
    // PRIVATE HELPER METHODS
    // ============================================================================

    /**
     * Get level group information
     */
    private function get_level_group_info($level_id) {
        if (!function_exists('pmpro_get_level_groups')) {
            return null;
        }

        $groups = pmpro_get_level_groups();
        foreach ($groups as $group) {
            $levels = pmpro_get_level_ids_for_group($group->id);
            if (in_array($level_id, $levels)) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'order' => $group->displayorder
                ];
            }
        }

        return null;
    }

    /**
     * Get custom user fields configuration
     */
    private function get_custom_user_fields() {
        if (!function_exists('pmpro_get_user_fields')) {
            return [];
        }
        $fields = pmpro_get_user_fields();
        $custom_fields = [];

        foreach ($fields as $field) {
            $custom_fields[] = [
                'id' => $field['id'],
                'name' => $field['name'],
                'type' => $field['type'],
                'required' => !empty($field['required']),
                'options' => !empty($field['options']) ? $field['options'] : null
            ];
        }

        return $custom_fields;
    }

    /**
     * Get billing fields configuration
     */
    private function get_billing_fields_config($required) {
        if (!$required) {
            return [];
        }

        return [
            'first_name' => ['required' => true, 'type' => 'text'],
            'last_name' => ['required' => true, 'type' => 'text'],
            'company' => ['required' => false, 'type' => 'text'],
            'address_1' => ['required' => true, 'type' => 'text'],
            'address_2' => ['required' => false, 'type' => 'text'],
            'city' => ['required' => true, 'type' => 'text'],
            'state' => ['required' => true, 'type' => 'select'],
            'postcode' => ['required' => true, 'type' => 'text'],
            'country' => ['required' => true, 'type' => 'select'],
            'phone' => ['required' => false, 'type' => 'tel']
        ];
    }

    /**
     * Get countries list
     */
    private function get_countries_list() {
        if (!function_exists('pmpro_get_countries')) {
            return [];
        }

        return pmpro_get_countries();
    }

    /**
     * Get states list
     */
    private function get_states_list() {
        if (!function_exists('pmpro_get_states')) {
            return [];
        }

        return pmpro_get_states();
    }

    /**
     * Get Stripe public key
     */
    private function get_stripe_public_key() {
        if (!function_exists('pmpro_getOption')) {
            return '';
        }

        $environment = pmpro_getOption('gateway_environment');
        $key_option = $environment === 'live' ? 'stripe_publishablekey' : 'stripe_publishablekey';

        return pmpro_getOption($key_option) ?: '';
    }

    /**
     * Get PayPal client ID
     */
    private function get_paypal_client_id() {
        if (!function_exists('pmpro_getOption')) {
            return '';
        }

        $environment = pmpro_getOption('gateway_environment');
        $key_option = $environment === 'live' ? 'paypal_client_id' : 'paypal_sandbox_client_id';

        return pmpro_getOption($key_option) ?: '';
    }

    /**
     * Process paid membership signup
     */
    private function process_paid_signup($user_id, $level, $params) {
        // Create MemberOrder
        $order = new \MemberOrder();
        $order->user_id = $user_id;
        $order->membership_id = $level->id;
        $order->gateway = 'stripe';
        $order->billing = $this->build_billing_object($params['billing'] ?? []);

        // Set totals
        $order->subtotal = pmpro_round_price((float) $level->initial_payment);
        $order->tax = pmpro_round_price($order->getTax(true));
        $order->total = pmpro_round_price($order->subtotal + $order->tax);

        // Set Stripe API key
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
        // Ensure payment method ID is provided
        if (empty($params['payment_method_id'])) {
            return new \WP_Error('stripe_error', 'Payment method ID is required.');
        }

        $payment_method_id = sanitize_text_field($params['payment_method_id']);

        try {
            // 1. Retrieve or create Stripe Customer
            $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);
            if (!$stripe_customer_id) {
                $customer = \Stripe\Customer::create([
                    'email' => $params['email'] ?? '',
                    'name'  => trim(($params['custom_fields']['Patient_first_name'] ?? '') . ' ' . ($params['custom_fields']['Patient_last_name'] ?? '')),
                    'address' => [
                        'line1'       => $params['custom_fields']['street_address'] ?? '',
                        'city'        => $params['custom_fields']['town_city'] ?? '',
                        'postal_code' => $params['custom_fields']['postcode_zip'] ?? '',
                        'country'     => $params['custom_fields']['Patient_Country'] ?? '',
                    ],
                    'metadata' => ['wp_user_id' => $user_id],
                ]);
                $stripe_customer_id = $customer->id;
                update_user_meta($user_id, 'stripe_customer_id', $stripe_customer_id);
            } else {
                $customer = \Stripe\Customer::retrieve($stripe_customer_id);
            }

            // 2. Retrieve PaymentMethod
            $payment_method = \Stripe\PaymentMethod::retrieve($payment_method_id);

            // Only attach if not already attached
            if (empty($payment_method->customer) || $payment_method->customer !== $customer->id) {
                $payment_method->attach(['customer' => $customer->id]);
            }

            // 3. Set as default payment method
            \Stripe\Customer::update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $payment_method_id]
            ]);

            // 4. Create PaymentIntent to charge immediately
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => intval($order->total * 100), // amount in cents
                'currency' => 'usd', // adjust as needed
                'customer' => $customer->id,
                'payment_method' => $payment_method_id,
                'off_session' => true,
                'confirm' => true,
            ]);

            // 5. Store Stripe info on PMPro order
            $order->stripe_customer_id = $customer->id;
            $order->payment_method_id  = $payment_method_id;
            $order->payment_transaction_id = $payment_intent->id;
            $order->payment_type = 'stripe';
            $order->status = 'success';

        } catch (\Exception $e) {
            return new \WP_Error('stripe_error', $e->getMessage());
        }

        // Complete PMPro checkout
        global $pmpro_level;
        $pmpro_level = $level;

        $completed = pmpro_complete_checkout($order);
        if (!$completed) {
            return new \WP_Error('checkout_failed', __('Checkout completion failed.', 'oba-apis-integration'));
        }

        return [
            'order_id' => $order->id,
            'subscription_id' => $order->subscription_transaction_id ?? null,
            'stripe_payment_intent_id' => $payment_intent->id,
        ];
    }

    /**
     * Process free membership signup
     */
    private function process_free_signup($user_id, $level, $params = [] , $source = '') {
        // Create a PMPro order
        $order = new \MemberOrder();
        $order->user_id       = $user_id;
        $order->membership_id = $level->id;

        // Decide gateway based on source
        if ($source === 'android') {
            $order->gateway = 'android';
            $order->payment_type = 'in_app_purchase';
            $order->payment_transaction_id = 'android-' . ($params['payment_method_id'] ?? uniqid());
        } elseif ($source === 'ios') {
            $order->gateway = 'ios';
            $order->payment_type = 'in_app_purchase';
            $order->payment_transaction_id = 'ios-' . ($params['payment_method_id'] ?? uniqid());
        } else {
            // Default: free
            $order->gateway = 'free';
            $order->payment_type = 'free';
            $order->payment_transaction_id = 'free-' . uniqid();
        }

        // Billing (skip for IAP if not relevant)
        $order->billing = $this->build_billing_object($params['billing'] ?? []);

        // Totals (IAP handled outside, so store as zero in PMPro)
        $order->subtotal = pmpro_round_price((float) $level->initial_payment);
        $order->tax      = 0;
        $order->total    = pmpro_round_price((float) $level->initial_payment);
        $order->status   = 'success';

        // Complete PMPro checkout
        global $pmpro_level;
        $pmpro_level = $level;

        $completed = pmpro_complete_checkout($order);
        if (!$completed) {
            return new \WP_Error(
                'checkout_failed',
                __('Checkout completion failed.', 'oba-apis-integration')
            );
        }

        return [
            'order_id' => $order->id,
            'subscription_id' => null,
            'transaction_id' => $order->payment_transaction_id,
        ];
    }

    /**
     * Process membership upgrade with payment
     */
    private function process_membership_upgrade($user_id, $current_level, $new_level, $params) {
        // Calculate upgrade cost
        $upgrade_cost = $this->calculate_upgrade_cost($current_level, $new_level);

        // If no upgrade cost, just change the level
        if ($upgrade_cost <= 0) {
            return $this->process_free_upgrade($user_id, $new_level);
        }

        // Set up Stripe API key (same as signup)
        $stripe_settings = get_option('woocommerce_stripe_settings', []);
        $is_test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';

        if ($is_test_mode) {
            $secret_key = $stripe_settings['test_secret_key'] ?? '';
        } else {
            $secret_key = $stripe_settings['secret_key'] ?? '';
        }

        if (!empty($secret_key)) {
            \Stripe\Stripe::setApiKey($secret_key);
        } else {
            return new \WP_Error('stripe_error', 'Stripe secret key is missing! Check WooCommerce Stripe settings.');
        }

        // Ensure payment method ID is provided
        if (empty($params['payment_method_id'])) {
            return new \WP_Error('stripe_error', 'Payment method ID is required for upgrade.');
        }

        $payment_method_id = sanitize_text_field($params['payment_method_id']);

        try {
            // 1. Get existing Stripe customer
            $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);

            if (!$stripe_customer_id) {
                // Create new Stripe customer
                $customer = \Stripe\Customer::create([
                    'email' => $params['email'] ?? '',
                    'name'  => trim(($params['custom_fields']['Patient_first_name'] ?? '') . ' ' . ($params['custom_fields']['Patient_last_name'] ?? '')),
                    'address' => [
                        'line1'       => $params['custom_fields']['street_address'] ?? '',
                        'city'        => $params['custom_fields']['town_city'] ?? '',
                        'postal_code' => $params['custom_fields']['postcode_zip'] ?? '',
                        'country'     => $params['custom_fields']['Patient_Country'] ?? '',
                    ],
                    'metadata' => ['wp_user_id' => $user_id],
                ]);

                $stripe_customer_id = $customer->id;
                update_user_meta($user_id, 'stripe_customer_id', $stripe_customer_id);
            } else {
                // Retrieve existing customer
                $customer = \Stripe\Customer::retrieve($stripe_customer_id);
            }

            // 2. Retrieve and attach payment method
            $payment_method = \Stripe\PaymentMethod::retrieve($payment_method_id);

            if (empty($payment_method->customer) || $payment_method->customer !== $customer->id) {
                $payment_method->attach(['customer' => $customer->id]);
            }

            // 3. Set as default payment method
            \Stripe\Customer::update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $payment_method_id]
            ]);

            // 4. Create PaymentIntent for upgrade charge
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => intval($upgrade_cost * 100), // amount in cents
                'currency' => 'usd',
                'customer' => $customer->id,
                'payment_method' => $payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'description' => "Membership upgrade from {$current_level->name} to {$new_level->name}",
                'metadata' => [
                    'user_id' => $user_id,
                    'upgrade_type' => 'membership_level_change',
                    'old_level_id' => $current_level->id,
                    'new_level_id' => $new_level->id
                ]
            ]);

            // 5. Create PMPro order for upgrade
            $order = new \MemberOrder();
            $order->user_id = $user_id;
            $order->membership_id = $new_level->id;
            $order->gateway = 'stripe';
            $order->billing = $this->build_billing_object($params['billing'] ?? []);
            $order->subtotal = pmpro_round_price($upgrade_cost);
            $order->tax = pmpro_round_price($order->getTax(true));
            $order->total = pmpro_round_price($order->subtotal + $order->tax);
            $order->stripe_customer_id = $customer->id;
            $order->payment_method_id = $payment_method_id;
            $order->payment_transaction_id = $payment_intent->id;
            $order->payment_type = 'stripe';
            $order->status = 'success';

            // 6. Change membership level
            if (function_exists('pmpro_changeMembershipLevel')) {
                $result = pmpro_changeMembershipLevel($new_level->id, $user_id, 'changed');
                if ($result === false) {
                    // Refund the payment if membership change fails
                    try {
                        \Stripe\Refund::create([
                            'payment_intent' => $payment_intent->id,
                            'reason' => 'requested_by_customer'
                        ]);
                    } catch (\Exception $e) {
                        error_log('Failed to refund payment after membership change failure: ' . $e->getMessage());
                    }
                    return new \WP_Error('upgrade_failed', __('Failed to upgrade membership level.', 'oba-apis-integration'));
                }
            }

            // 7. Save the order
            $order->saveOrder();

            // 8. Get updated membership details
            $membership = pmpro_getMembershipLevelForUser($user_id);

            return [
                'order_id' => $order->id,
                'subscription_id' => $order->subscription_transaction_id ?? null,
                'stripe_payment_intent_id' => $payment_intent->id,
                'upgrade_cost' => $upgrade_cost,
                'membership_level' => [
                    'id' => $membership->id,
                    'name' => $membership->name,
                    'startdate' => $membership->startdate,
                    'enddate' => $membership->enddate
                ]
            ];

        } catch (\Exception $e) {
            return new \WP_Error('stripe_error', 'Payment processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Process free membership upgrade
     */
    private function process_free_upgrade($user_id, $new_level) {
        if (function_exists('pmpro_changeMembershipLevel')) {
            $result = pmpro_changeMembershipLevel($new_level->id, $user_id, 'changed');
            if ($result !== false) {
                return ['order_id' => null, 'subscription_id' => null];
            }
        }

        return new WP_Error('upgrade_failed', __('Failed to upgrade membership.', 'oba-apis-integration'));
    }

    /**
     * Build billing object for orders
     */
    private function build_billing_object($billing_data) {
        $billing = new \stdClass();
        $billing->name = trim(($billing_data['first_name'] ?? '') . ' ' . ($billing_data['last_name'] ?? ''));
        $billing->street = (string) ($billing_data['address_1'] ?? '');
        $billing->street2 = (string) ($billing_data['address_2'] ?? '');
        $billing->city = (string) ($billing_data['city'] ?? '');
        $billing->state = (string) ($billing_data['state'] ?? '');
        $billing->country = (string) ($billing_data['country'] ?? '');
        $billing->zip = (string) ($billing_data['postcode'] ?? '');
        $billing->phone = (string) ($billing_data['phone'] ?? '');

        return $billing;
    }

    /**
     * Calculate upgrade cost between levels
     */
    private function calculate_upgrade_cost($current_level, $new_level) {
        // Handle null current level (user has no membership)
        if (!$current_level) {
            return (float) $new_level->initial_payment;
        }

        $current_initial = (float) $current_level->initial_payment;
        $current_billing = (float) $current_level->billing_amount;
        $new_initial = (float) $new_level->initial_payment;
        $new_billing = (float) $new_level->billing_amount;

        // Calculate the difference in initial payments
        $initial_difference = $new_initial - $current_initial;

        // Calculate the difference in billing amounts
        $billing_difference = $new_billing - $current_billing;

        // For upgrades, we typically charge the difference
        // If upgrading to a higher tier, charge the difference
        // If downgrading, this should be handled differently (refund logic)

        $upgrade_cost = 0;

        // If new level has initial payment and current doesn't, charge the initial
        if ($new_initial > 0 && $current_initial == 0) {
            $upgrade_cost = $new_initial;
        }
        // If both have initial payments, charge the difference
        elseif ($new_initial > 0 && $current_initial > 0) {
            $upgrade_cost = max(0, $initial_difference);
        }
        // If new level has billing and current doesn't, charge the billing amount
        elseif ($new_billing > 0 && $current_billing == 0) {
            $upgrade_cost = $new_billing;
        }
        // If both have billing, charge the difference
        elseif ($new_billing > 0 && $current_billing > 0) {
            $upgrade_cost = max(0, $billing_difference);
        }

        // Ensure we don't charge negative amounts
        return max(0, $upgrade_cost);
    }

    /**
     * Set user billing address
     */
    private function set_user_billing_address($user_id, $billing_data) {
        $billing_fields = [
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country', 'billing_phone'
        ];

        foreach ($billing_fields as $field) {
            $key = str_replace('billing_', '', $field);
            if (isset($billing_data[$key])) {
                update_user_meta($user_id, $field, sanitize_text_field($billing_data[$key]));
            }
        }
    }

    /**
     * Set user shipping address
     */
    private function set_user_shipping_address($user_id, $shipping_data) {
        $shipping_fields = [
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone'
        ];

        foreach ($shipping_fields as $field) {
            $key = str_replace('shipping_', '', $field);
            if (isset($shipping_data[$key])) {
                update_user_meta($user_id, $field, sanitize_text_field($shipping_data[$key]));
            }
        }
    }

    /**
     * Get user billing address
     */
    private function get_user_billing_address($user_id) {
        return [
            'first_name' => get_user_meta($user_id, 'billing_first_name', true),
            'last_name' => get_user_meta($user_id, 'billing_last_name', true),
            'company' => get_user_meta($user_id, 'billing_company', true),
            'address_1' => get_user_meta($user_id, 'billing_address_1', true),
            'address_2' => get_user_meta($user_id, 'billing_address_2', true),
            'city' => get_user_meta($user_id, 'billing_city', true),
            'state' => get_user_meta($user_id, 'billing_state', true),
            'postcode' => get_user_meta($user_id, 'billing_postcode', true),
            'country' => get_user_meta($user_id, 'billing_country', true),
            'phone' => get_user_meta($user_id, 'billing_phone', true)
        ];
    }



    /**
     * Update user billing address
     */
    private function update_user_billing_address($user_id, $billing_data) {
        $this->set_user_billing_address($user_id, $billing_data);
        return true;
    }

    /**
     * Set custom fields for user
     */
    private function set_custom_fields($user_id, $custom_fields) {
        foreach ($custom_fields as $field_id => $value) {
            update_user_meta($user_id,  $field_id, sanitize_text_field($value));
        }
    }

    /**
     * Get user custom fields
     */
    private function get_user_custom_fields($user_id) {
        if (!function_exists('pmpro_get_user_fields')) {
            return [];
        }

        $fields = ['Patient_first_name', 'patient_last_name', 'Patient_Date_of_Birth', 'Patient_Gender' , 'Patient_Country' , 'street_address' , 'town_city' , 'postcode_zip'];
        $custom_fields = [];

        foreach ($fields as $field) {
            $value = get_user_meta($user_id,  $field , true);
            if ($value) {
                $custom_fields[$field] = [
                    $value
                ];
            }
        }

        return $custom_fields;
    }

    /**
     * Update user custom fields
     */
    private function update_user_custom_fields($user_id, $custom_fields) {
        $this->set_custom_fields($user_id, $custom_fields);
        return true;
    }

    /**
     * Get membership status
     */
    private function get_membership_status($membership_level) {
        if (!$membership_level->enddate) {
            return 'active';
        }

        $now = current_time('timestamp');
        $end_date = strtotime($membership_level->enddate);

        if ($end_date > $now) {
            return 'active';
        } else {
            return 'expired';
        }
    }

    /**
     * Calculate days remaining in membership
     */
    private function calculate_days_remaining($end_date) {
        if (!$end_date) {
            return null;
        }

        $now = current_time('timestamp');
        $end_timestamp = strtotime($end_date);

        if ($end_timestamp <= $now) {
            return 0;
        }

        return ceil(($end_timestamp - $now) / DAY_IN_SECONDS);
    }

    /**
     * Check if user can cancel membership
     */
    private function can_user_cancel_membership($user_id) {
        $membership_level = pmpro_getMembershipLevelForUser($user_id);
        if (!$membership_level) {
            return false;
        }

        // Check if user has cancellation permissions
        return current_user_can('pmpro_cancel_membership') ||
            get_user_meta($user_id, 'pmpro_can_cancel', true);
    }

    /**
     * Check if user can upgrade membership
     */
    private function can_user_upgrade_membership($user_id) {
        $membership_level = pmpro_getMembershipLevelForUser($user_id);
        if (!$membership_level) {
            return false;
        }

        // Check if user has upgrade permissions
        return current_user_can('pmpro_change_membership') ||
            get_user_meta($user_id, 'pmpro_can_upgrade', true);
    }

    /**
     * Check if user can downgrade membership
     */
    private function can_user_downgrade_membership($user_id) {
        $membership_level = pmpro_getMembershipLevelForUser($user_id);
        if (!$membership_level) {
            return false;
        }

        // Check if user has downgrade permissions
        return current_user_can('pmpro_change_membership') ||
            get_user_meta($user_id, 'pmpro_can_downgrade', true);
    }

    /**
     * Get membership history
     */
    private function get_membership_history($user_id) {
        global $wpdb;

        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->pmpro_memberships_users} 
             WHERE user_id = %d 
             ORDER BY startdate DESC",
            $user_id
        ));

        $formatted_history = [];
        foreach ($history as $record) {
            $level = pmpro_getLevel($record->membership_id);
            if ($level) {
                $formatted_history[] = [
                    'level_id' => $record->membership_id,
                    'level_name' => $level->name,
                    'startdate' => $record->startdate,
                    'enddate' => $record->enddate,
                    'status' => $record->status,
                    'modified' => $record->modified
                ];
            }
        }

        return $formatted_history;
    }

    /**
     * Get user recent orders
     */
    private function get_user_recent_orders($user_id, $limit = 5) {
        global $wpdb;

        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->pmpro_membership_orders} 
             WHERE user_id = %d 
             ORDER BY timestamp DESC 
             LIMIT %d",
            $user_id, $limit
        ));

        $formatted_orders = [];
        foreach ($orders as $order) {
            $formatted_orders[] = [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'total' => $order->total,
                'timestamp' => $order->timestamp,
                'gateway' => $order->gateway,
                'gateway_environment' => $order->gateway_environment
            ];
        }

        return $formatted_orders;
    }

    /**
     * Get level features
     */
    private function get_level_features($level_id) {
        // This would typically come from level meta or a features system
        // For now, return basic level information
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return [];
        }

        return [
            'has_trial' => !empty($level->trial_amount) || !empty($level->trial_limit),
            'is_recurring' => !empty($level->billing_amount) && !empty($level->cycle_number),
            'has_expiration' => !empty($level->expiration_number) && !empty($level->expiration_period),
            'billing_cycles' => (int) $level->billing_limit
        ];
    }
} 