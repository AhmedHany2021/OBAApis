<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Membership service
 *
 * @package OBA\APIsIntegration\Services
 */
class MembershipService {

	/**
	 * Get membership status
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_status( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		$member = new \PMPro_Member( $user->ID );
		$membership_data = [];

		if ( $member->membership_level ) {
			$membership_data = [
				'has_membership' => true,
				'level_id' => $member->membership_level->id,
				'level_name' => $member->membership_level->name,
				'level_description' => $member->membership_level->description,
				'level_cost' => $member->membership_level->initial_payment,
				'level_billing_amount' => $member->membership_level->billing_amount,
				'level_billing_limit' => $member->membership_level->billing_limit,
				'level_cycle_number' => $member->membership_level->cycle_number,
				'level_cycle_period' => $member->membership_level->cycle_period,
				'level_trial_amount' => $member->membership_level->trial_amount,
				'level_trial_limit' => $member->membership_level->trial_limit,
				'start_date' => $member->membership_level->startdate,
				'end_date' => $member->membership_level->enddate,
				'status' => $member->status,
				'is_active' => $this->is_membership_active( $member ),
				'days_remaining' => $this->get_days_remaining( $member ),
			];
		} else {
			$membership_data = [
				'has_membership' => false,
				'level_id' => null,
				'level_name' => null,
				'level_description' => null,
				'level_cost' => null,
				'level_billing_amount' => null,
				'level_billing_limit' => null,
				'level_cycle_number' => null,
				'level_cycle_period' => null,
				'level_trial_amount' => null,
				'level_trial_limit' => null,
				'start_date' => null,
				'end_date' => null,
				'status' => null,
				'is_active' => false,
				'days_remaining' => 0,
			];
		}

		return new WP_REST_Response( [
			'success' => true,
			'data' => $membership_data,
		], 200 );
	}

	/**
	 * Get membership plans
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_plans( $request ) {
//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		// Get query parameters
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$active_only = $request->get_param( 'active_only' ) !== 'false';

		// Get all membership levels
		$levels = pmpro_getAllLevels( true, true );
		$formatted_levels = [];

		foreach ( $levels as $level ) {
			// Filter by active status if requested
			if ( $active_only && ! $level->allow_signups ) {
				continue;
			}

			$formatted_levels[] = [
				'id' => $level->id,
				'name' => $level->name,
				'description' => $level->description,
				'confirmation' => $level->confirmation,
				'initial_payment' => $level->initial_payment,
				'billing_amount' => $level->billing_amount,
				'billing_limit' => $level->billing_limit,
				'cycle_number' => $level->cycle_number,
				'cycle_period' => $level->cycle_period,
				'trial_amount' => $level->trial_amount,
				'trial_limit' => $level->trial_limit,
				'allow_signups' => (bool) $level->allow_signups,
				'expiration_number' => $level->expiration_number,
				'expiration_period' => $level->expiration_period,
				'categories' => $this->get_level_categories( $level->id ),
				'features' => $this->get_level_features( $level->id ),
			];
		}

		// Apply pagination
		$total_levels = count( $formatted_levels );
		$offset = ( $page - 1 ) * $per_page;
		$paginated_levels = array_slice( $formatted_levels, $offset, $per_page );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $paginated_levels,
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total' => $total_levels,
				'total_pages' => ceil( $total_levels / $per_page ),
			],
		], 200 );
	}

	/**
	 * Get signup form fields for a specific membership level
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_signup_form( $request ) {
//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		$level_id = absint( $request->get_param( 'level_id' ) );
		if ( ! $level_id ) {
			return new WP_Error(
				'invalid_level_id',
				__( 'Valid level ID is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get the membership level
		$level = pmpro_getLevel( $level_id );
		if ( ! $level ) {
			return new WP_Error(
				'level_not_found',
				__( 'Membership level not found.', 'oba-apis-integration' ),
				[ 'status' => 404 ]
			);
		}

		// Get available payment gateways
		$gateways = pmpro_getGateways();
		$available_gateways = [];
		foreach ( $gateways as $gateway => $gateway_name ) {
			$available_gateways[] = [
				'id' => $gateway,
				'name' => $gateway_name,
				'is_active' => pmpro_isGatewayActive( $gateway ),
			];
		}

		// Get custom fields if PMPro Custom Fields addon is active
		$custom_fields = [];
		if ( class_exists( 'PMPro_Custom_Fields' ) ) {
			$custom_fields = $this->get_custom_fields( $level_id );
		}

		$form_data = [
			'level' => [
				'id' => $level->id,
				'name' => $level->name,
				'description' => $level->description,
				'initial_payment' => $level->initial_payment,
				'billing_amount' => $level->billing_amount,
				'billing_limit' => $level->billing_limit,
				'cycle_number' => $level->cycle_number,
				'cycle_period' => $level->cycle_period,
				'trial_amount' => $level->trial_amount,
				'trial_limit' => $level->trial_limit,
				'expiration_number' => $level->expiration_number,
				'expiration_period' => $level->expiration_period,
			],
			'gateways' => $available_gateways,
			'custom_fields' => $custom_fields,
			'required_fields' => [
				'username',
				'email',
				'password',
				'confirm_password',
				'first_name',
				'last_name',
				'billing_address_1',
				'billing_city',
				'billing_state',
				'billing_postcode',
				'billing_country',
			],
		];

		return new WP_REST_Response( [
			'success' => true,
			'data' => $form_data,
		], 200 );
	}

	/**
	 * Process membership signup
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_signup( $request ) {
//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		$signup_data = $request->get_json_params();
		if ( empty( $signup_data ) ) {
			return new WP_Error(
				'invalid_signup_data',
				__( 'Signup data is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Validate required fields
		$required_fields = [ 'level_id', 'username', 'email', 'password', 'first_name', 'last_name' ];
		foreach ( $required_fields as $field ) {
			if ( empty( $signup_data[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf( __( 'Missing required field: %s', 'oba-apis-integration' ), $field ),
					[ 'status' => 400 ]
				);
			}
		}

		// Validate level exists
		$level = pmpro_getLevel( $signup_data['level_id'] );
		if ( ! $level ) {
			return new WP_Error(
				'invalid_level',
				__( 'Invalid membership level.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Check if user already exists
		$existing_user = get_user_by( 'email', $signup_data['email'] );
		if ( $existing_user ) {
			return new WP_Error(
				'user_exists',
				__( 'User with this email already exists.', 'oba-apis-integration' ),
				[ 'status' => 409 ]
			);
		}

		try {
			// Create user
			$user_data = [
				'user_login' => sanitize_user( $signup_data['username'] ),
				'user_email' => sanitize_email( $signup_data['email'] ),
				'user_pass' => $signup_data['password'],
				'first_name' => sanitize_text_field( $signup_data['first_name'] ),
				'last_name' => sanitize_text_field( $signup_data['last_name'] ),
				'role' => 'subscriber',
			];

			$user_id = wp_insert_user( $user_data );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			// Set billing address if provided
			if ( ! empty( $signup_data['billing_address'] ) ) {
				$this->set_user_billing_address( $user_id, $signup_data['billing_address'] );
			}

			// Set custom fields if provided
			if ( ! empty( $signup_data['custom_fields'] ) && class_exists( 'PMPro_Custom_Fields' ) ) {
				$this->set_custom_fields( $user_id, $signup_data['custom_fields'] );
			}

			// Process payment and create membership
			$membership_result = $this->create_membership( $user_id, $signup_data );

			if ( is_wp_error( $membership_result ) ) {
				// If membership creation fails, delete the user
				wp_delete_user( $user_id );
				return $membership_result;
			}

			// Get user data for response
			$user = get_user_by( 'ID', $user_id );
			$user_data = [
				'id' => $user->ID,
				'username' => $user->user_login,
				'email' => $user->user_email,
				'first_name' => $user->first_name,
				'last_name' => $user->last_name,
				'membership' => $membership_result,
			];

			return new WP_REST_Response( [
				'success' => true,
				'message' => __( 'Membership signup completed successfully.', 'oba-apis-integration' ),
				'data' => $user_data,
			], 201 );

		} catch ( \Exception $e ) {
			return new WP_Error(
				'signup_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Upgrade/downgrade existing membership
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_membership( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		$change_data = $request->get_json_params();
		if ( empty( $change_data ) || empty( $change_data['new_level_id'] ) ) {
			return new WP_Error(
				'invalid_change_data',
				__( 'New level ID is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$new_level_id = absint( $change_data['new_level_id'] );
		$new_level = pmpro_getLevel( $new_level_id );
		if ( ! $new_level ) {
			return new WP_Error(
				'invalid_level',
				__( 'Invalid membership level.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		try {
			// Change membership level
			$result = pmpro_changeMembershipLevel( $new_level_id, $user->ID );
			
			if ( $result ) {
				// Get updated membership data
				$member = new \PMPro_Member( $user->ID );
				$membership_data = [
					'level_id' => $member->membership_level->id,
					'level_name' => $member->membership_level->name,
					'status' => $member->status,
					'start_date' => $member->membership_level->startdate,
					'end_date' => $member->membership_level->enddate,
				];

				return new WP_REST_Response( [
					'success' => true,
					'message' => __( 'Membership level changed successfully.', 'oba-apis-integration' ),
					'data' => $membership_data,
				], 200 );
			} else {
				return new WP_Error(
					'change_failed',
					__( 'Failed to change membership level.', 'oba-apis-integration' ),
					[ 'status' => 500 ]
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'change_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Cancel membership
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_membership( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		$cancel_data = $request->get_json_params();
		$cancel_at_period_end = ! empty( $cancel_data['cancel_at_period_end'] );

		try {
			// Cancel membership
			$result = pmpro_cancelMembershipLevel( $user->ID, $cancel_at_period_end );
			
			if ( $result ) {
				return new WP_REST_Response( [
					'success' => true,
					'message' => __( 'Membership cancelled successfully.', 'oba-apis-integration' ),
					'data' => [
						'cancelled' => true,
						'cancel_at_period_end' => $cancel_at_period_end,
					],
				], 200 );
			} else {
				return new WP_Error(
					'cancellation_failed',
					__( 'Failed to cancel membership.', 'oba-apis-integration' ),
					[ 'status' => 500 ]
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'cancellation_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Get available payment gateways
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_payment_gateways( $request ) {
//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		$gateways = pmpro_getGateways();
		$gateway_details = [];

		foreach ( $gateways as $gateway => $gateway_name ) {
			$gateway_details[] = [
				'id' => $gateway,
				'name' => $gateway_name,
				'is_active' => pmpro_isGatewayActive( $gateway ),
				'description' => $this->get_gateway_description( $gateway ),
				'supports_recurring' => $this->gateway_supports_recurring( $gateway ),
				'supports_trial' => $this->gateway_supports_trial( $gateway ),
			];
		}

		return new WP_REST_Response( [
			'success' => true,
			'data' => $gateway_details,
		], 200 );
	}

	/**
	 * Get membership analytics
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_analytics( $request ) {
		if ( ! class_exists( 'PMPro_Member' ) ) {
			return new WP_Error(
				'pmpro_required',
				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get date range parameters
		$start_date = $request->get_param( 'start_date' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date = $request->get_param( 'end_date' ) ?: date( 'Y-m-d' );

		// Get membership statistics
		$stats = [
			'total_members' => pmpro_getMemberCount(),
			'active_members' => pmpro_getMemberCount( 'active' ),
			'expired_members' => pmpro_getMemberCount( 'expired' ),
			'cancelled_members' => pmpro_getMemberCount( 'cancelled' ),
			'pending_members' => pmpro_getMemberCount( 'pending' ),
			'new_members_today' => $this->get_new_members_count( $start_date, $end_date ),
			'revenue_today' => $this->get_revenue_count( $start_date, $end_date ),
			'level_distribution' => $this->get_level_distribution(),
		];

		return new WP_REST_Response( [
			'success' => true,
			'data' => $stats,
		], 200 );
	}

	/**
	 * Check if membership is active
	 *
	 * @param \PMPro_Member $member Member object.
	 * @return bool
	 */
	private function is_membership_active( $member ) {
		if ( ! $member->membership_level ) {
			return false;
		}

		// Check if membership has expired
		if ( ! empty( $member->membership_level->enddate ) ) {
			$end_date = strtotime( $member->membership_level->enddate );
			if ( $end_date && $end_date < time() ) {
				return false;
			}
		}

		// Check status
		return in_array( $member->status, [ 'active', 'pending' ], true );
	}

	/**
	 * Get days remaining for membership
	 *
	 * @param \PMPro_Member $member Member object.
	 * @return int
	 */
	private function get_days_remaining( $member ) {
		if ( ! $member->membership_level || empty( $member->membership_level->enddate ) ) {
			return 0;
		}

		$end_date = strtotime( $member->membership_level->enddate );
		if ( ! $end_date ) {
			return 0;
		}

		$current_time = time();
		$days_remaining = ceil( ( $end_date - $current_time ) / DAY_IN_SECONDS );

		return max( 0, $days_remaining );
	}

	/**
	 * Get level categories
	 *
	 * @param int $level_id Level ID.
	 * @return array
	 */
	private function get_level_categories( $level_id ) {
		$categories = [];

		// Check if PMPro has category functionality
		if ( function_exists( 'pmpro_getLevelCategories' ) ) {
			$level_categories = pmpro_getLevelCategories( $level_id );
			if ( ! empty( $level_categories ) ) {
				foreach ( $level_categories as $category ) {
					$categories[] = [
						'id' => $category->term_id,
						'name' => $category->name,
						'slug' => $category->slug,
						'description' => $category->description,
						'count' => $category->count,
					];
				}
			}
		}

		// If no PMPro categories, try to get from custom taxonomy
		if ( empty( $categories ) ) {
			$custom_categories = get_option( 'pmpro_level_categories_' . $level_id, [] );
			if ( ! empty( $custom_categories ) ) {
				foreach ( $custom_categories as $category ) {
					$categories[] = [
						'id' => $category['id'] ?? 0,
						'name' => $category['name'] ?? '',
						'slug' => $category['slug'] ?? '',
						'description' => $category['description'] ?? '',
						'count' => $category['count'] ?? 0,
					];
				}
			}
		}

		// Add default categories based on level characteristics
		if ( empty( $categories ) ) {
			$level = pmpro_getLevel( $level_id );
			if ( $level ) {
				// Determine categories based on level properties
				if ( $level->initial_payment > 0 ) {
					$categories[] = [
						'id' => 'paid',
						'name' => 'Paid Membership',
						'slug' => 'paid',
						'description' => 'Requires payment to access',
						'count' => 1,
					];
				}

				if ( $level->billing_amount > 0 ) {
					$categories[] = [
						'id' => 'recurring',
						'name' => 'Recurring Billing',
						'slug' => 'recurring',
						'description' => 'Automatically renews',
						'count' => 1,
					];
				}

				if ( $level->trial_amount > 0 ) {
					$categories[] = [
						'id' => 'trial',
						'name' => 'Trial Available',
						'slug' => 'trial',
						'description' => 'Free trial period included',
						'count' => 1,
					];
				}

				if ( ! empty( $level->expiration_number ) ) {
					$categories[] = [
						'id' => 'expiring',
						'name' => 'Time-Limited',
						'slug' => 'expiring',
						'description' => 'Membership expires after a period',
						'count' => 1,
					];
				}
			}
		}

		return $categories;
	}

	/**
	 * Get level features
	 *
	 * @param int $level_id Level ID.
	 * @return array
	 */
	private function get_level_features( $level_id ) {
		$features = [];

		// Get features from PMPro level options
		$level = pmpro_getLevel( $level_id );
		if ( $level ) {
			// Basic features based on level properties
			if ( $level->initial_payment > 0 ) {
				$features[] = [
					'id' => 'initial_payment',
					'name' => 'Initial Payment',
					'description' => sprintf( 'One-time payment of %s', pmpro_formatPrice( $level->initial_payment ) ),
					'value' => $level->initial_payment,
					'type' => 'payment',
				];
			}

			if ( $level->billing_amount > 0 ) {
				$features[] = [
					'id' => 'recurring_billing',
					'name' => 'Recurring Billing',
					'description' => sprintf( '%s every %d %s', pmpro_formatPrice( $level->billing_amount ), $level->cycle_number, $level->cycle_period ),
					'value' => $level->billing_amount,
					'type' => 'billing',
				];

				if ( $level->billing_limit > 0 ) {
					$features[] = [
						'id' => 'billing_limit',
						'name' => 'Billing Limit',
						'description' => sprintf( 'Bills %d times then stops', $level->billing_limit ),
						'value' => $level->billing_limit,
						'type' => 'limit',
					];
				}
			}

			if ( $level->trial_amount > 0 ) {
				$features[] = [
					'id' => 'trial',
					'name' => 'Free Trial',
					'description' => sprintf( '%s for %d %s', pmpro_formatPrice( $level->trial_amount ), $level->trial_limit, $level->cycle_period ),
					'value' => $level->trial_amount,
					'type' => 'trial',
				];
			}

			if ( ! empty( $level->expiration_number ) ) {
				$features[] = [
					'id' => 'expiration',
					'name' => 'Membership Duration',
					'description' => sprintf( '%d %s', $level->expiration_number, $level->expiration_period ),
					'value' => $level->expiration_number,
					'type' => 'duration',
				];
			}

			// Add confirmation message as a feature
			if ( ! empty( $level->confirmation ) ) {
				$features[] = [
					'id' => 'confirmation',
					'name' => 'Welcome Message',
					'description' => wp_strip_all_tags( $level->confirmation ),
					'value' => $level->confirmation,
					'type' => 'message',
				];
			}
		}

		// Get custom features from options
		$custom_features = get_option( 'pmpro_level_features_' . $level_id, [] );
		if ( ! empty( $custom_features ) ) {
			foreach ( $custom_features as $feature ) {
				$features[] = [
					'id' => $feature['id'] ?? '',
					'name' => $feature['name'] ?? '',
					'description' => $feature['description'] ?? '',
					'value' => $feature['value'] ?? '',
					'type' => $feature['type'] ?? 'custom',
				];
			}
		}

		// Add default features based on level type
		if ( empty( $features ) ) {
			$features[] = [
				'id' => 'basic_access',
				'name' => 'Basic Access',
				'description' => 'Access to basic content and features',
				'value' => 'basic',
				'type' => 'access',
			];
		}

		return $features;
	}

	/**
	 * Get custom fields for a level
	 *
	 * @param int $level_id Level ID.
	 * @return array
	 */
	private function get_custom_fields( $level_id ) {
		$custom_fields = [];

		// Check if PMPro Custom Fields addon is active
		if ( ! class_exists( 'PMPro_Custom_Fields' ) ) {
			return $custom_fields;
		}

		// Get custom fields for the specific level
		$level_fields = get_option( 'pmpro_custom_fields_level_' . $level_id, [] );
		
		if ( ! empty( $level_fields ) ) {
			foreach ( $level_fields as $field ) {
				$custom_fields[] = [
					'id' => $field['id'] ?? '',
					'name' => $field['name'] ?? '',
					'label' => $field['label'] ?? '',
					'type' => $field['type'] ?? 'text',
					'required' => ! empty( $field['required'] ),
					'options' => $field['options'] ?? [],
					'default_value' => $field['default_value'] ?? '',
					'help_text' => $field['help_text'] ?? '',
					'order' => $field['order'] ?? 0,
					'field_type' => $field['field_type'] ?? 'user',
					'validation' => $field['validation'] ?? '',
					'admin_only' => ! empty( $field['admin_only'] ),
					'profile' => ! empty( $field['profile'] ),
					'checkout' => ! empty( $field['checkout'] ),
				];
			}
		}

		// Get global custom fields (not level-specific)
		$global_fields = get_option( 'pmpro_custom_fields', [] );
		
		if ( ! empty( $global_fields ) ) {
			foreach ( $global_fields as $field ) {
				// Only include fields that should appear on checkout
				if ( ! empty( $field['checkout'] ) ) {
					$custom_fields[] = [
						'id' => $field['id'] ?? '',
						'name' => $field['name'] ?? '',
						'label' => $field['label'] ?? '',
						'type' => $field['type'] ?? 'text',
						'required' => ! empty( $field['required'] ),
						'options' => $field['options'] ?? [],
						'default_value' => $field['default_value'] ?? '',
						'help_text' => $field['help_text'] ?? '',
						'order' => $field['order'] ?? 0,
						'field_type' => $field['field_type'] ?? 'user',
						'validation' => $field['validation'] ?? '',
						'admin_only' => ! empty( $field['admin_only'] ),
						'profile' => ! empty( $field['profile'] ),
						'checkout' => ! empty( $field['checkout'] ),
						'is_global' => true,
					];
				}
			}
		}

		// Sort fields by order
		usort( $custom_fields, function( $a, $b ) {
			return ( $a['order'] ?? 0 ) - ( $b['order'] ?? 0 );
		} );

		// Add common custom fields that are typically needed
		$common_fields = [
			[
				'id' => 'phone',
				'name' => 'phone',
				'label' => __( 'Phone Number', 'oba-apis-integration' ),
				'type' => 'tel',
				'required' => false,
				'options' => [],
				'default_value' => '',
				'help_text' => __( 'Enter your phone number', 'oba-apis-integration' ),
				'order' => 100,
				'field_type' => 'user',
				'validation' => 'phone',
				'admin_only' => false,
				'profile' => true,
				'checkout' => true,
				'is_common' => true,
			],
			[
				'id' => 'company',
				'name' => 'company',
				'label' => __( 'Company', 'oba-apis-integration' ),
				'type' => 'text',
				'required' => false,
				'options' => [],
				'default_value' => '',
				'help_text' => __( 'Enter your company name', 'oba-apis-integration' ),
				'order' => 101,
				'field_type' => 'user',
				'validation' => '',
				'admin_only' => false,
				'profile' => true,
				'checkout' => true,
				'is_common' => true,
			],
			[
				'id' => 'website',
				'name' => 'website',
				'label' => __( 'Website', 'oba-apis-integration' ),
				'type' => 'url',
				'required' => false,
				'options' => [],
				'default_value' => '',
				'help_text' => __( 'Enter your website URL', 'oba-apis-integration' ),
				'order' => 102,
				'field_type' => 'user',
				'validation' => 'url',
				'admin_only' => false,
				'profile' => true,
				'checkout' => false,
				'is_common' => true,
			],
		];

		// Merge common fields with custom fields
		$custom_fields = array_merge( $custom_fields, $common_fields );

		// Remove duplicates based on field name
		$unique_fields = [];
		$seen_names = [];
		
		foreach ( $custom_fields as $field ) {
			$field_name = $field['name'] ?? '';
			if ( ! in_array( $field_name, $seen_names, true ) ) {
				$unique_fields[] = $field;
				$seen_names[] = $field_name;
			}
		}

		// Sort again by order
		usort( $unique_fields, function( $a, $b ) {
			return ( $a['order'] ?? 0 ) - ( $b['order'] ?? 0 );
		} );

		return $unique_fields;
	}

	/**
	 * Set user billing address
	 *
	 * @param int   $user_id User ID.
	 * @param array $address Address data.
	 * @return void
	 */
	private function set_user_billing_address( $user_id, $address ) {
		if ( class_exists( 'WC_Customer' ) ) {
			$customer = new \WC_Customer( $user_id );
			
			$billing_fields = [
				'first_name', 'last_name', 'company', 'address_1', 'address_2',
				'city', 'state', 'postcode', 'country', 'phone'
			];

			foreach ( $billing_fields as $field ) {
				if ( ! empty( $address[ $field ] ) ) {
					$customer->{"set_billing_{$field}"}( sanitize_text_field( $address[ $field ] ) );
				}
			}

			$customer->save();
		}
	}

	/**
	 * Set custom fields for user
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields Custom fields data.
	 * @return void
	 */
	private function set_custom_fields( $user_id, $fields ) {
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as $field_name => $field_value ) {
			// Skip empty values unless they're explicitly set to empty
			if ( $field_value === '' || $field_value === null ) {
				continue;
			}

			// Sanitize field value based on field type
			$sanitized_value = $this->sanitize_custom_field_value( $field_name, $field_value );
			
			// Store in user meta
			update_user_meta( $user_id, $field_name, $sanitized_value );

			// If PMPro Custom Fields addon is active, also store in their system
			if ( class_exists( 'PMPro_Custom_Fields' ) ) {
				// Store in PMPro custom fields table if it exists
				$this->store_pmpro_custom_field( $user_id, $field_name, $sanitized_value );
			}

			// Handle special field types
			switch ( $field_name ) {
				case 'phone':
					// Also store in WooCommerce customer data if available
					if ( class_exists( 'WC_Customer' ) ) {
						$customer = new \WC_Customer( $user_id );
						$customer->set_billing_phone( $sanitized_value );
						$customer->save();
					}
					break;

				case 'company':
					// Also store in WooCommerce customer data if available
					if ( class_exists( 'WC_Customer' ) ) {
						$customer = new \WC_Customer( $user_id );
						$customer->set_billing_company( $sanitized_value );
						$customer->save();
					}
					break;

				case 'website':
					// Update WordPress user website field
					wp_update_user( [
						'ID' => $user_id,
						'user_url' => $sanitized_value,
					] );
					break;
			}
		}
	}

	/**
	 * Sanitize custom field value based on field type
	 *
	 * @param string $field_name Field name.
	 * @param mixed  $field_value Field value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_custom_field_value( $field_name, $field_value ) {
		// Get field configuration to determine type
		$field_config = $this->get_field_config( $field_name );
		$field_type = $field_config['type'] ?? 'text';

		switch ( $field_type ) {
			case 'email':
				return sanitize_email( $field_value );

			case 'url':
				return esc_url_raw( $field_value );

			case 'tel':
			case 'phone':
				// Remove all non-numeric characters except +, -, (, ), and space
				return preg_replace( '/[^0-9+\-\(\)\s]/', '', $field_value );

			case 'number':
				return is_numeric( $field_value ) ? floatval( $field_value ) : 0;

			case 'textarea':
				return sanitize_textarea_field( $field_value );

			case 'select':
			case 'radio':
				// For select/radio, validate against allowed options
				$options = $field_config['options'] ?? [];
				if ( ! empty( $options ) && ! in_array( $field_value, $options, true ) ) {
					return ''; // Invalid option
				}
				return sanitize_text_field( $field_value );

			case 'checkbox':
				return ! empty( $field_value ) ? '1' : '0';

			case 'date':
				// Validate date format
				$timestamp = strtotime( $field_value );
				return $timestamp ? date( 'Y-m-d', $timestamp ) : '';

			default:
				return sanitize_text_field( $field_value );
		}
	}

	/**
	 * Get field configuration for a specific field
	 *
	 * @param string $field_name Field name.
	 * @return array Field configuration.
	 */
	private function get_field_config( $field_name ) {
		// Check level-specific custom fields
		$level_fields = get_option( 'pmpro_custom_fields_level_1', [] ); // Default to level 1
		foreach ( $level_fields as $field ) {
			if ( ( $field['name'] ?? '' ) === $field_name ) {
				return $field;
			}
		}

		// Check global custom fields
		$global_fields = get_option( 'pmpro_custom_fields', [] );
		foreach ( $global_fields as $field ) {
			if ( ( $field['name'] ?? '' ) === $field_name ) {
				return $field;
			}
		}

		// Return default configuration for common fields
		$common_fields = [
			'phone' => [ 'type' => 'tel', 'validation' => 'phone' ],
			'company' => [ 'type' => 'text', 'validation' => '' ],
			'website' => [ 'type' => 'url', 'validation' => 'url' ],
		];

		return $common_fields[ $field_name ] ?? [ 'type' => 'text', 'validation' => '' ];
	}

	/**
	 * Store custom field in PMPro custom fields system
	 *
	 * @param int    $user_id User ID.
	 * @param string $field_name Field name.
	 * @param mixed  $field_value Field value.
	 * @return void
	 */
	private function store_pmpro_custom_field( $user_id, $field_name, $field_value ) {
		global $wpdb;

		// Check if PMPro custom fields table exists
		$table_name = $wpdb->prefix . 'pmpro_custom_fields_values';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

		if ( $table_exists ) {
			// Check if field value already exists
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_name WHERE user_id = %d AND field_name = %s",
				$user_id,
				$field_name
			) );

			if ( $existing ) {
				// Update existing field value
				$wpdb->update(
					$table_name,
					[
						'field_value' => $field_value,
						'updated_at' => current_time( 'mysql' ),
					],
					[
						'user_id' => $user_id,
						'field_name' => $field_name,
					],
					[ '%s', '%s' ],
					[ '%d', '%s' ]
				);
			} else {
				// Insert new field value
				$wpdb->insert(
					$table_name,
					[
						'user_id' => $user_id,
						'field_name' => $field_name,
						'field_value' => $field_value,
						'created_at' => current_time( 'mysql' ),
						'updated_at' => current_time( 'mysql' ),
					],
					[ '%d', '%s', '%s', '%s', '%s' ]
				);
			}
		}
	}

	/**
	 * Create membership for user
	 *
	 * @param int   $user_id User ID.
	 * @param array $signup_data Signup data.
	 * @return array|WP_Error
	 */
	private function create_membership( $user_id, $signup_data ) {
		// This is a simplified implementation
		// In a real scenario, you would integrate with PMPro's payment processing
		
		$level_id = $signup_data['level_id'];
		$level = pmpro_getLevel( $level_id );
		
		// Create membership record
		$membership_data = [
			'user_id' => $user_id,
			'level_id' => $level_id,
			'status' => 'active',
			'start_date' => current_time( 'mysql' ),
			'end_date' => $this->calculate_end_date( $level ),
		];

		// In a real implementation, you would:
		// 1. Process payment through the selected gateway
		// 2. Create the membership record in PMPro
		// 3. Handle recurring billing setup
		// 4. Send confirmation emails

		return $membership_data;
	}

	/**
	 * Calculate membership end date
	 *
	 * @param object $level Membership level.
	 * @return string|null
	 */
	private function calculate_end_date( $level ) {
		if ( empty( $level->expiration_number ) || empty( $level->expiration_period ) ) {
			return null; // No expiration
		}

		$end_date = strtotime( "+{$level->expiration_number} {$level->expiration_period}" );
		return $end_date ? date( 'Y-m-d H:i:s', $end_date ) : null;
	}

	/**
	 * Get gateway description
	 *
	 * @param string $gateway Gateway ID.
	 * @return string
	 */
	private function get_gateway_description( $gateway ) {
		$descriptions = [
			'stripe' => 'Credit card payments via Stripe',
			'paypal' => 'PayPal payments',
			'authorizenet' => 'Authorize.net payments',
			'check' => 'Check payments',
			'paypalexpress' => 'PayPal Express Checkout',
		];

		return $descriptions[ $gateway ] ?? 'Payment gateway';
	}

	/**
	 * Check if gateway supports recurring payments
	 *
	 * @param string $gateway Gateway ID.
	 * @return bool
	 */
	private function gateway_supports_recurring( $gateway ) {
		$recurring_gateways = [ 'stripe', 'paypal', 'authorizenet' ];
		return in_array( $gateway, $recurring_gateways, true );
	}

	/**
	 * Check if gateway supports trial periods
	 *
	 * @param string $gateway Gateway ID.
	 * @return bool
	 */
	private function gateway_supports_trial( $gateway ) {
		$trial_gateways = [ 'stripe', 'paypal' ];
		return in_array( $gateway, $trial_gateways, true );
	}

	/**
	 * Get new members count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return int
	 */
	private function get_new_members_count( $start_date, $end_date ) {
		global $wpdb;

		// Query PMPro members table for new members in date range
		$table_name = $wpdb->prefix . 'pmpro_memberships_users';
		
		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT user_id) 
			FROM $table_name 
			WHERE startdate >= %s 
			AND startdate <= %s 
			AND status = 'active'",
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$count = $wpdb->get_var( $query );
		
		// If PMPro table doesn't exist, try alternative approach
		if ( $count === null ) {
			// Query WordPress users table for new registrations
			$query = $wpdb->prepare(
				"SELECT COUNT(*) 
				FROM {$wpdb->users} 
				WHERE user_registered >= %s 
				AND user_registered <= %s",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			);
			
			$count = $wpdb->get_var( $query );
		}

		return (int) ( $count ?? 0 );
	}

	/**
	 * Get revenue count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return float
	 */
	private function get_revenue_count( $start_date, $end_date ) {
		global $wpdb;

		$total_revenue = 0.0;

		// Query PMPro orders table for revenue in date range
		$orders_table = $wpdb->prefix . 'pmpro_membership_orders';
		
		$query = $wpdb->prepare(
			"SELECT SUM(total) 
			FROM $orders_table 
			WHERE timestamp >= %s 
			AND timestamp <= %s 
			AND status = 'success'",
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$revenue = $wpdb->get_var( $query );
		
		if ( $revenue !== null ) {
			$total_revenue += (float) $revenue;
		}

		// If PMPro orders table doesn't exist, try WooCommerce orders
		if ( class_exists( 'WC_Order' ) ) {
			$wc_revenue = $this->get_woocommerce_revenue( $start_date, $end_date );
			$total_revenue += $wc_revenue;
		}

		// Try to get revenue from custom options
		$custom_revenue = get_option( 'pmpro_custom_revenue_' . $start_date . '_' . $end_date, 0.0 );
		$total_revenue += (float) $custom_revenue;

		return round( $total_revenue, 2 );
	}

	/**
	 * Get WooCommerce revenue for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return float
	 */
	private function get_woocommerce_revenue( $start_date, $end_date ) {
		global $wpdb;

		$revenue = 0.0;

		// Query WooCommerce orders for membership products
		$orders_table = $wpdb->prefix . 'wc_order_stats';
		$order_items_table = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$products_table = $wpdb->prefix . 'posts';

		$query = $wpdb->prepare(
			"SELECT SUM(woi.meta_value) 
			FROM $orders_table wos
			JOIN $order_items_table woi ON wos.order_id = woi.order_id
			JOIN $order_itemmeta_table woim ON woi.order_item_id = woim.order_item_id
			JOIN $products_table p ON woim.meta_value = p.ID
			WHERE wos.date_created >= %s 
			AND wos.date_created <= %s 
			AND wos.status = 'wc-completed'
			AND woi.order_item_type = 'line_item'
			AND woim.meta_key = '_product_id'
			AND p.post_type = 'product'
			AND p.post_status = 'publish'",
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$wc_revenue = $wpdb->get_var( $query );
		
		if ( $wc_revenue !== null ) {
			$revenue += (float) $wc_revenue;
		}

		return $revenue;
	}

	/**
	 * Get level distribution
	 *
	 * @return array
	 */
	private function get_level_distribution() {
		global $wpdb;

		$distribution = [];

		// Query PMPro memberships table for level distribution
		$table_name = $wpdb->prefix . 'pmpro_memberships_users';
		
		$query = "
			SELECT 
				ml.id as level_id,
				ml.name as level_name,
				COUNT(mu.user_id) as member_count,
				ml.initial_payment,
				ml.billing_amount
			FROM {$wpdb->prefix}pmpro_membership_levels ml
			LEFT JOIN $table_name mu ON ml.id = mu.membership_id
			WHERE mu.status = 'active' OR mu.status IS NULL
			GROUP BY ml.id, ml.name, ml.initial_payment, ml.billing_amount
			ORDER BY ml.initial_payment ASC
		";

		$results = $wpdb->get_results( $query );

		if ( ! empty( $results ) ) {
			foreach ( $results as $result ) {
				$distribution[] = [
					'level_id' => (int) $result->level_id,
					'level_name' => $result->level_name,
					'member_count' => (int) $result->member_count,
					'initial_payment' => (float) $result->initial_payment,
					'billing_amount' => (float) $result->billing_amount,
					'percentage' => 0, // Will be calculated below
				];
			}

			// Calculate total members and percentages
			$total_members = array_sum( array_column( $distribution, 'member_count' ) );
			
			if ( $total_members > 0 ) {
				foreach ( $distribution as &$level ) {
					$level['percentage'] = round( ( $level['member_count'] / $total_members ) * 100, 2 );
				}
			}
		}

		// If no PMPro data, try to get from custom options
		if ( empty( $distribution ) ) {
			$custom_distribution = get_option( 'pmpro_custom_level_distribution', [] );
			if ( ! empty( $custom_distribution ) ) {
				$distribution = $custom_distribution;
			}
		}

		// Add summary statistics
		if ( ! empty( $distribution ) ) {
			$total_members = array_sum( array_column( $distribution, 'member_count' ) );
			$total_revenue = array_sum( array_column( $distribution, 'initial_payment' ) );
			$avg_initial_payment = $total_members > 0 ? $total_revenue / $total_members : 0;

			$distribution['summary'] = [
				'total_members' => $total_members,
				'total_levels' => count( $distribution ),
				'total_revenue' => $total_revenue,
				'average_initial_payment' => round( $avg_initial_payment, 2 ),
			];
		}

		return $distribution;
	}

	/**
	 * Get user's membership history
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_membership_history( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		// Get membership history from PMPro
		$history = pmpro_get_membership_levels_for_user( $user->ID, true );
		$formatted_history = [];

		foreach ( $history as $level ) {
			$formatted_history[] = [
				'level_id' => $level->id,
				'level_name' => $level->name,
				'start_date' => $level->startdate,
				'end_date' => $level->enddate,
				'status' => $level->status,
				'is_current' => $level->id == pmpro_getMembershipLevelForUser( $user->ID )->id,
			];
		}

		return new WP_REST_Response( [
			'success' => true,
			'data' => $formatted_history,
		], 200 );
	}

	/**
	 * Get membership invoices
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_invoices( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		// Get query parameters
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );

		// Get invoices from PMPro
		$invoices = pmpro_getInvoices( $user->ID );
		$formatted_invoices = [];

		foreach ( $invoices as $invoice ) {
			$formatted_invoices[] = [
				'id' => $invoice->id,
				'code' => $invoice->code,
				'user_id' => $invoice->userid,
				'membership_id' => $invoice->membershipid,
				'gateway' => $invoice->gateway,
				'gateway_environment' => $invoice->gateway_environment,
				'amount' => $invoice->total,
				'subtotal' => $invoice->subtotal,
				'tax' => $invoice->tax,
				'status' => $invoice->status,
				'date' => $invoice->timestamp,
				'notes' => $invoice->notes,
			];
		}

		// Apply pagination
		$total_invoices = count( $formatted_invoices );
		$offset = ( $page - 1 ) * $per_page;
		$paginated_invoices = array_slice( $formatted_invoices, $offset, $per_page );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $paginated_invoices,
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total' => $total_invoices,
				'total_pages' => ceil( $total_invoices / $per_page ),
			],
		], 200 );
	}

	/**
	 * Get user profile with custom fields
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_user_profile( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

//		if ( ! class_exists( 'PMPro_Member' ) ) {
//			return new WP_Error(
//				'pmpro_required',
//				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
//				[ 'status' => 400 ]
//			);
//		}

		// Get user data
		$user_data = [
			'id' => $user->ID,
			'username' => $user->user_login,
			'email' => $user->user_email,
			'first_name' => $user->first_name,
			'last_name' => $user->last_name,
			'display_name' => $user->display_name,
			'website' => $user->user_url,
			'date_registered' => $user->user_registered,
			'last_login' => get_user_meta( $user->ID, 'last_login', true ),
		];

		// Get membership data
		$member = new \PMPro_Member( $user->ID );
		if ( $member->membership_level ) {
			$user_data['membership'] = [
				'level_id' => $member->membership_level->id,
				'level_name' => $member->membership_level->name,
				'status' => $member->status,
				'start_date' => $member->membership_level->startdate,
				'end_date' => $member->membership_level->enddate,
				'is_active' => $this->is_membership_active( $member ),
			];
		}

		// Get billing address if WooCommerce is active
		if ( class_exists( 'WC_Customer' ) ) {
			$customer = new \WC_Customer( $user->ID );
			$user_data['billing_address'] = [
				'first_name' => $customer->get_billing_first_name(),
				'last_name' => $customer->get_billing_last_name(),
				'company' => $customer->get_billing_company(),
				'address_1' => $customer->get_billing_address_1(),
				'address_2' => $customer->get_billing_address_2(),
				'city' => $customer->get_billing_city(),
				'state' => $customer->get_billing_state(),
				'postcode' => $customer->get_billing_postcode(),
				'country' => $customer->get_billing_country(),
				'phone' => $customer->get_billing_phone(),
				'email' => $customer->get_billing_email(),
			];

			$user_data['shipping_address'] = [
				'first_name' => $customer->get_shipping_first_name(),
				'last_name' => $customer->get_shipping_last_name(),
				'company' => $customer->get_shipping_company(),
				'address_1' => $customer->get_shipping_address_1(),
				'address_2' => $customer->get_shipping_address_2(),
				'city' => $customer->get_shipping_city(),
				'state' => $customer->get_shipping_state(),
				'postcode' => $customer->get_shipping_postcode(),
				'country' => $customer->get_shipping_country(),
			];
		}

		// Get custom fields
		$user_data['custom_fields'] = $this->get_user_custom_fields( $user->ID );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $user_data,
		], 200 );
	}

	/**
	 * Get custom field values for a user
	 *
	 * @param int $user_id User ID.
	 * @return array Custom field values.
	 */
	private function get_user_custom_fields( $user_id ) {
		$custom_fields = [];
		
		// Get custom fields from user meta
		$user_meta = get_user_meta( $user_id );
		
		// Filter out WordPress default meta fields
		$excluded_fields = [
			'first_name', 'last_name', 'nickname', 'description', 'rich_editing',
			'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front',
			'locale', 'wp_capabilities', 'wp_user_level', 'dismissed_wp_pointers',
			'show_welcome_panel', 'session_tokens', 'default_password_nag',
			'wp_dashboard_quick_press_last_post_id', 'wp_user-settings',
			'wp_user-settings-time', 'pmpro_approval_status', 'pmpro_approval_date',
		];

		foreach ( $user_meta as $meta_key => $meta_values ) {
			// Skip excluded fields and empty values
			if ( in_array( $meta_key, $excluded_fields, true ) || empty( $meta_values ) ) {
				continue;
			}

			// Get the first value (most user meta fields only have one value)
			$meta_value = is_array( $meta_values ) ? $meta_values[0] : $meta_values;
			
			// Skip if value is empty
			if ( empty( $meta_value ) && $meta_value !== '0' ) {
				continue;
			}

			$custom_fields[ $meta_key ] = [
				'value' => $meta_value,
				'type' => $this->get_field_config( $meta_key )['type'] ?? 'text',
			];
		}

		// If PMPro Custom Fields addon is active, also get from their system
		if ( class_exists( 'PMPro_Custom_Fields' ) ) {
			$pmpro_fields = $this->get_pmpro_custom_field_values( $user_id );
			$custom_fields = array_merge( $custom_fields, $pmpro_fields );
		}

		return $custom_fields;
	}

	/**
	 * Get custom field values from PMPro custom fields system
	 *
	 * @param int $user_id User ID.
	 * @return array Custom field values.
	 */
	private function get_pmpro_custom_field_values( $user_id ) {
		global $wpdb;
		$custom_fields = [];

		// Check if PMPro custom fields table exists
		$table_name = $wpdb->prefix . 'pmpro_custom_fields_values';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

		if ( $table_exists ) {
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT field_name, field_value FROM $table_name WHERE user_id = %d",
				$user_id
			) );

			foreach ( $results as $result ) {
				$custom_fields[ $result->field_name ] = [
					'value' => $result->field_value,
					'type' => $this->get_field_config( $result->field_name )['type'] ?? 'text',
					'source' => 'pmpro',
				];
			}
		}

		return $custom_fields;
	}
} 