<?php

namespace OBA\APIsIntegration\Services;

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
			$woo_data = [];

			// Billing address
			$billing_fields = [
				'billing_first_name' => 'billing_first_name',
				'billing_last_name' => 'billing_last_name',
				'billing_company' => 'billing_company',
				'billing_address_1' => 'billing_address_1',
				'billing_address_2' => 'billing_address_2',
				'billing_city' => 'billing_city',
				'billing_state' => 'billing_state',
				'billing_postcode' => 'billing_postcode',
				'billing_country' => 'billing_country',
				'billing_phone' => 'billing_phone',
			];

			foreach ( $billing_fields as $request_field => $customer_field ) {
				$value = $request->get_param( $request_field );
				if ( ! empty( $value ) ) {
					$woo_data[ $customer_field ] = sanitize_text_field( $value );
				}
			}

			// Shipping address
			$shipping_fields = [
				'shipping_first_name' => 'shipping_first_name',
				'shipping_last_name' => 'shipping_last_name',
				'shipping_company' => 'shipping_company',
				'shipping_address_1' => 'shipping_address_1',
				'shipping_address_2' => 'shipping_address_2',
				'shipping_city' => 'shipping_city',
				'shipping_state' => 'shipping_state',
				'shipping_postcode' => 'shipping_postcode',
				'shipping_country' => 'shipping_country',
			];

			foreach ( $shipping_fields as $request_field => $customer_field ) {
				$value = $request->get_param( $request_field );
				if ( ! empty( $value ) ) {
					$woo_data[ $customer_field ] = sanitize_text_field( $value );
				}
			}

			// Update customer data
			if ( ! empty( $woo_data ) ) {
				foreach ( $woo_data as $field => $value ) {
					$customer->{"set_{$field}"}( $value );
				}
				$customer->save();
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
} 