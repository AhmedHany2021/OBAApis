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

		if ( ! class_exists( 'PMPro_Member' ) ) {
			return new WP_Error(
				'pmpro_required',
				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

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
		if ( ! class_exists( 'PMPro_Member' ) ) {
			return new WP_Error(
				'pmpro_required',
				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

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
		// This is a placeholder. In a real implementation,
		// you would query the database for level categories
		// based on your specific PMPro setup
		return [];
	}

	/**
	 * Get level features
	 *
	 * @param int $level_id Level ID.
	 * @return array
	 */
	private function get_level_features( $level_id ) {
		// This is a placeholder. In a real implementation,
		// you would query the database for level features
		// based on your specific PMPro setup
		return [];
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

		if ( ! class_exists( 'PMPro_Member' ) ) {
			return new WP_Error(
				'pmpro_required',
				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

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

		if ( ! class_exists( 'PMPro_Member' ) ) {
			return new WP_Error(
				'pmpro_required',
				__( 'Paid Memberships Pro is required for membership operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

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
} 