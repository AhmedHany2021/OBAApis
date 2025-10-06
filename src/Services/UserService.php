<?php

namespace OBA\APIsIntegration\Services;

use OBA\APIsIntegration\Helpers\ProductHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

/**
 * User service
 *
 * @package OBA\APIsIntegration\Services
 */
class UserService {

	/**
	 * Get current user information
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_current_user( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		$user_data = $this->format_user_data( $user );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $user_data,
		], 200 );
	}

	/**
	 * Update user profile
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		$user_data = [];
		$errors = [];

		// Update basic user fields
		$fields_to_update = [
			'first_name' => 'first_name',
			'last_name' => 'last_name',
			'display_name' => 'display_name',
			'user_email' => 'user_email',
		];

		foreach ( $fields_to_update as $request_field => $user_field ) {
			$value = $request->get_param( $request_field );
			if ( ! empty( $value ) ) {
				$user_data[ $user_field ] = sanitize_text_field( $value );
			}
		}

		// Validate email if provided
		if ( isset( $user_data['user_email'] ) ) {
			if ( ! is_email( $user_data['user_email'] ) ) {
				$errors[] = __( 'Invalid email format.', 'oba-apis-integration' );
			} else {
				$existing_user = get_user_by( 'email', $user_data['user_email'] );
				if ( $existing_user && $existing_user->ID !== $user->ID ) {
					$errors[] = __( 'Email address is already in use.', 'oba-apis-integration' );
				}
			}
		}

		// Update WooCommerce customer data if available
        if ( class_exists( 'WC_Customer' ) ) {
            $customer = new \WC_Customer( $user->ID );

            // Get billing and shipping data from request
            $billing_data  = $request->get_param( 'billing' ) ?: [];
            $shipping_data = $request->get_param( 'shipping' ) ?: [];

            // Billing fields
            $billing_fields = [
                'first_name' => 'billing_first_name',
                'last_name'  => 'billing_last_name',
                'company'    => 'billing_company',
                'address_1'  => 'billing_address_1',
                'address_2'  => 'billing_address_2',
                'city'       => 'billing_city',
                'state'      => 'billing_state',
                'postcode'   => 'billing_postcode',
                'country'    => 'billing_country',
                'email'      => 'billing_email',
                'phone'      => 'billing_phone',
            ];

            foreach ( $billing_fields as $req_field => $cust_field ) {
                if ( isset( $billing_data[ $req_field ] ) ) {
                    $value = sanitize_text_field( $billing_data[ $req_field ] );
                    $method = "set_{$cust_field}";
                    if ( method_exists( $customer, $method ) ) {
                        $customer->$method( $value );
                    }
                }
            }

            // Shipping fields
            $shipping_fields = [
                'first_name' => 'shipping_first_name',
                'last_name'  => 'shipping_last_name',
                'company'    => 'shipping_company',
                'address_1'  => 'shipping_address_1',
                'address_2'  => 'shipping_address_2',
                'city'       => 'shipping_city',
                'state'      => 'shipping_state',
                'postcode'   => 'shipping_postcode',
                'country'    => 'shipping_country',
            ];

            foreach ( $shipping_fields as $req_field => $cust_field ) {
                if ( isset( $shipping_data[ $req_field ] ) ) {
                    $value = sanitize_text_field( $shipping_data[ $req_field ] );
                    $method = "set_{$cust_field}";
                    if ( method_exists( $customer, $method ) ) {
                        $customer->$method( $value );
                    }
                }
            }

            // Save all updates
            $customer->save();

            // Optional: Sync shipping if empty but billing exists
            if ( empty( $shipping_data ) ) {
                $customer->set_shipping_first_name( $customer->get_billing_first_name() );
                $customer->set_shipping_last_name( $customer->get_billing_last_name() );
                $customer->set_shipping_company( $customer->get_billing_company() );
                $customer->set_shipping_address_1( $customer->get_billing_address_1() );
                $customer->set_shipping_address_2( $customer->get_billing_address_2() );
                $customer->set_shipping_city( $customer->get_billing_city() );
                $customer->set_shipping_state( $customer->get_billing_state() );
                $customer->set_shipping_postcode( $customer->get_billing_postcode() );
                $customer->set_shipping_country( $customer->get_billing_country() );
                $customer->save();
            }

            // Custom fields
            $custom_fields = $request->get_param( 'custom_fields' );
            if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
                foreach ( $custom_fields as $meta_key => $meta_value ) {
                    update_user_meta( $user->ID, $meta_key, sanitize_text_field( $meta_value ) );
                }
            }
        }

		// Update Dokan vendor data if available
		if ( class_exists( 'WeDevs_Dokan' ) && in_array( 'seller', $user->roles, true ) ) {
			$vendor_data = get_user_meta( $user->ID, 'dokan_profile_settings', true ) ?: [];
			$dokan_fields = [
				'store_name' => 'store_name',
				'store_description' => 'store_description',
				'store_phone' => 'phone',
				'store_email' => 'email',
			];

			foreach ( $dokan_fields as $request_field => $vendor_field ) {
				$value = $request->get_param( $request_field );
				if ( ! empty( $value ) ) {
					$vendor_data[ $vendor_field ] = sanitize_text_field( $value );
				}
			}

			// Update vendor data
			if ( ! empty( $vendor_data ) ) {
				update_user_meta( $user->ID, 'dokan_profile_settings', $vendor_data );
			}
		}

		// Return errors if any
		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'validation_failed',
				__( 'Validation failed.', 'oba-apis-integration' ),
				[ 'status' => 400, 'errors' => $errors ]
			);
		}

		// Update user data
		if ( ! empty( $user_data ) ) {
			$user_data['ID'] = $user->ID;
			$result = wp_update_user( $user_data );
			
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Get updated user data
		$updated_user = get_user_by( 'ID', $user->ID );
		$formatted_data = $this->format_user_data( $updated_user );

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Profile updated successfully.', 'oba-apis-integration' ),
			'data' => $formatted_data,
		], 200 );
	}

	/**
	 * Format user data for API response
	 *
	 * @param WP_User $user User object.
	 * @return array
	 */
	private function format_user_data( $user ) {
		$user_data = [
			'id' => $user->ID,
			'email' => $user->user_email,
			'username' => $user->user_login,
			'display_name' => $user->display_name,
			'first_name' => $user->first_name,
			'last_name' => $user->last_name,
			'roles' => $user->roles,
			'capabilities' => array_keys( $user->allcaps ),
			'registered_date' => $user->user_registered,
			'last_login' => get_user_meta( $user->ID, 'last_login', true ),
		];

		// Add WooCommerce customer data if available
		if ( class_exists( 'WC_Customer' ) ) {
			$customer = new \WC_Customer( $user->ID );
			if ( $customer->get_id() ) {
				$user_data['woocommerce'] = [
					'customer_id' => $customer->get_id(),
					'billing_address' => [
						'first_name' => $customer->get_billing_first_name(),
						'last_name' => $customer->get_billing_last_name(),
						'company' => $customer->get_billing_company(),
						'address_1' => $customer->get_billing_address_1(),
						'address_2' => $customer->get_billing_address_2(),
						'city' => $customer->get_billing_city(),
						'state' => $customer->get_billing_state(),
						'postcode' => $customer->get_billing_postcode(),
						'country' => $customer->get_billing_country(),
						'email' => $customer->get_billing_email(),
						'phone' => $customer->get_billing_phone(),
					],
					'shipping_address' => [
						'first_name' => $customer->get_shipping_first_name(),
						'last_name' => $customer->get_shipping_last_name(),
						'company' => $customer->get_shipping_company(),
						'address_1' => $customer->get_shipping_address_1(),
						'address_2' => $customer->get_shipping_address_2(),
						'city' => $customer->get_shipping_city(),
						'state' => $customer->get_shipping_state(),
						'postcode' => $customer->get_shipping_postcode(),
						'country' => $customer->get_shipping_country(),
					],
				];
			}
		}

		// Add Dokan vendor data if available
		if ( class_exists( 'WeDevs_Dokan' ) && in_array( 'seller', $user->roles, true ) ) {
			$vendor_data = get_user_meta( $user->ID, 'dokan_profile_settings', true );
			if ( $vendor_data ) {
				$user_data['dokan'] = [
					'store_name' => $vendor_data['store_name'] ?? '',
					'store_url' => $vendor_data['store_url'] ?? '',
					'store_description' => $vendor_data['store_description'] ?? '',
					'store_address' => $vendor_data['address'] ?? [],
					'store_banner' => $vendor_data['banner'] ?? '',
					'store_logo' => $vendor_data['logo'] ?? '',
					'store_phone' => $vendor_data['phone'] ?? '',
					'store_email' => $vendor_data['email'] ?? '',
				];
			}
		}

		// Add Paid Memberships Pro data if available
		if ( class_exists( 'PMPro_Member' ) ) {
			$member = new \PMPro_Member( $user->ID );
			if ( $member->membership_level ) {
				$user_data['membership'] = [
					'level_id' => $member->membership_level->id,
					'level_name' => $member->membership_level->name,
					'level_description' => $member->membership_level->description,
					'start_date' => $member->membership_level->startdate,
					'end_date' => $member->membership_level->enddate,
					'status' => $member->status,
				];
			}
		}

		return $user_data;
	}

    public function get_recommended_medications ( $request )
    {
        $user_id = $request->get_param( 'current_user' )->ID;
        $product_ids = (array)get_user_meta($user_id, 'user_product_recommendations', true);

        if (empty($product_ids) || $product_ids === [ 0 => ""]) {
            return new WP_REST_Response( [
                'success' => true,
                'data' => [],
                'message' => __( 'No recommended medications found.', 'oba-apis-integration' ),
            ], 200 );
        }
        
        $product_ids = array_slice( $product_ids, 1);
        $products = [];
        
        // Check if WooCommerce is available
        if ( ! class_exists( 'WC_Product' ) ) {
            return new WP_Error(
                'woocommerce_required',
                __( 'WooCommerce is required for product operations.', 'oba-apis-integration' ),
                [ 'status' => 400 ]
            );
        }
        
        // Fetch and format each product
        foreach ( $product_ids as $product_id ) {
            $product_id = absint( $product_id );
            if ( $product_id > 0 ) {
                $product = wc_get_product( $product_id );
                if ( $product ) {
                    $products[] = ProductHelper::format_product( $product );
                }
            }
        }
        
        return new WP_REST_Response( [
            'success' => true,
            'data' => $products,
        ], 200 );
    }

    public function get_recommended_doctors ( $request )
    {
        $user_id = $request->get_param( 'current_user' )->ID;
        $speciality_ids = (array)get_user_meta($user_id, 'user_speciality_recommendations', true);

        if (empty($speciality_ids) || $speciality_ids === [ 0 => ""]) {
            return;
        }
        $speciality_ids = array_slice( $speciality_ids, 1);
        return new WP_REST_Response( [
            'success' => true,
            'speciality_ids' => $speciality_ids,
        ], 200 );
    }

    /**
     * Generate one-time access token with call_id for user login
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function generate_one_time_token( $request ) {
        $user = $request->get_param( 'current_user' );
        
        if ( ! $user ) {
            return new WP_Error(
                'authentication_required',
                __( 'Authentication required.', 'oba-apis-integration' ),
                [ 'status' => 401 ]
            );
        }

        $call_id = $request->get_param( 'call_id' );
        $expires_in = 3600; // Default 1 hour

        // Validate call_id is provided
        if ( empty( $call_id ) ) {
            return new WP_Error(
                'missing_call_id',
                __( 'Call ID is required.', 'oba-apis-integration' ),
                [ 'status' => 400 ]
            );
        }

        // Generate secure token
        $token = wp_generate_password( 64, false );
        $token_hash = hash( 'sha256', $token );
        
        // Store token data
        $token_data = [
            'user_id' => $user->ID,
            'call_id' => sanitize_text_field( $call_id ),
            'expires_at' => time() + $expires_in,
            'created_at' => time(),
            'used' => false,
        ];

        // Store in database with expiration
        $result = set_transient( "oba_one_time_token_{$token_hash}", $token_data, $expires_in );
        
        if ( ! $result ) {
            return new WP_Error(
                'token_generation_failed',
                __( 'Failed to generate one-time token.', 'oba-apis-integration' ),
                [ 'status' => 500 ]
            );
        }

        // Log token generation
        $this->log_token_activity( $user->ID, 'generated', $token_hash, $call_id );

        return new WP_REST_Response( [
            'success' => true,
            'message' => __( 'One-time token generated successfully.', 'oba-apis-integration' ),
            'data' => [
                'token' => $token,
                'call_id' => $call_id,
                'expires_in' => $expires_in,
                'expires_at' => $token_data['expires_at'],
                'site_url' => home_url( "/?oba_token={$token}" ),
            ],
        ], 200 );
    }

    /**
     * Log token activity
     *
     * @param int    $user_id User ID.
     * @param string $action Action performed.
     * @param string $token_hash Token hash.
     * @param string $call_id Call ID (optional).
     * @return void
     */
    private function log_token_activity( $user_id, $action, $token_hash, $call_id = null ) {
        $log_data = [
            'user_id' => $user_id,
            'action' => $action,
            'token_hash' => $token_hash,
            'call_id' => $call_id,
            'timestamp' => current_time( 'mysql' ),
        ];

        // Store in user meta for recent activity
        $recent_activity = get_user_meta( $user_id, 'oba_token_activity', true ) ?: [];
        $recent_activity[] = $log_data;
        
        // Keep only last 20 activities
        if ( count( $recent_activity ) > 20 ) {
            $recent_activity = array_slice( $recent_activity, -20 );
        }
        
        update_user_meta( $user_id, 'oba_token_activity', $recent_activity );
    }

} 