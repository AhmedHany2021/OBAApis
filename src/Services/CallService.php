<?php

namespace OBA\APIsIntegration\Services;
use OBA\APIsIntegration\Traits\CallHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
/**
 * Call Service class
 * 
 * Handles all call-related operations for appointments, including:
 * - Checking pending calls
 * - Updating call IDs
 * - Checking call end status
 * 
 * Integrates with the mdclara_appointments table where call information is stored.
 * 
 * @package OBA\APIsIntegration\Services
 */
class CallService
{
    use CallHelper;
    /**
     * Check call end status
     * 
     * Retrieves the call status for a specific appointment.
     * 
     * @param WP_REST_Request $request The REST request object containing appointment ID
     * @return WP_REST_Response Response object with call status
     * 
     * Note: The 'id' parameter in the request refers to the appointment ID
     */
    public function check_call_end_status(WP_REST_Request $request)
    {
        global $wpdb;

        // Get appointment ID from request
        $appointment_id = intval($request->get_param('id'));

        if (!$appointment_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment ID is required.'
            ], 400);
        }

        $table_name = $wpdb->prefix . 'mdclara_appointments';

        $appointment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT call_status 
             FROM `$table_name` 
             WHERE `id` = %d 
             LIMIT 1",
                $appointment_id
            ),
            ARRAY_A
        );

        if (!$appointment) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment not found for appointment ID: ' . $appointment_id
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'status'  => $appointment['call_status']
        ], 200);
    }

    /**
     * Check pending call status
     * 
     * Checks if there's a pending call for the current user.
     * Uses transient storage to track pending calls.
     * 
     * @param WP_REST_Request $request The REST request object containing user data
     * @return WP_REST_Response Response object with pending call status
     */
    public function check_call_status(WP_REST_Request $request)
    {
        $user_id = $request->get_param('current_user')->ID;
        // Get patient ID from user meta
        $patient_id = get_user_meta($user_id, 'mdclara_patient_id', true);
        if (get_transient('mdclara_pending_call_' . $patient_id)) {
            delete_transient('mdclara_pending_call_' . $patient_id);
            return new WP_REST_Response([
                'success'  => true,
                'has_call' => true,
            ], 200);
        }
        return new WP_REST_Response([
            'success'        => true,
            'has_call'       => false,
        ], 200);
    }

    /**
     * Update call ID for appointment
     * 
     * Updates the call ID for a specific appointment.
     * 
     * @param WP_REST_Request $request The REST request object containing call data
     * @return WP_REST_Response Response object with update status
     * 
     * Note: The 'id' parameter in the request refers to the appointment ID
     */
    public function update_appointment_call_id(WP_REST_Request $request)
    {
        global $wpdb;

        // Get current user ID from request
        $user_id = $request->get_param('current_user')->ID;
        // Get appointment ID from request
        $appointment_id = intval($request->get_param('id'));
        $call_id     = sanitize_text_field($request->get_param('call_id'));

        if (!$call_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => ' call_id is required.'
            ], 400);
        }

        $table_name = $wpdb->prefix . 'mdclara_appointments';

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table_name}` WHERE `user_id` = %d AND `id` = %d",
                $user_id,
                $appointment_id
            )
        );

        if (!$exists) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment not found for given user_id and id.'
            ], 404);
        }

        // Update call_id
        $updated = $wpdb->update(
            $table_name,
            ['call_id' => $call_id],
            ['user_id' => $user_id, 'id' => $appointment_id],
            ['%s'],
            ['%d', '%d']
        );

        if ($updated === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to update call_id.'
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'call_id updated successfully.',
            'call_id' => $call_id
        ], 200);
    }

    public function submit_feedback(WP_REST_Request $request)
    {
        $patient_id = $request->get_param('patient_id');
        if (is_wp_error($patient_id)) {
            return $patient_id;
        }

        $user_id = $request->get_param('current_user')->ID;

        // Required fields
        $required_fields = [
            'appointment_id',
            'overall_satisfaction',
            'doctor_professionalism',
            'platform_experience',
            'likelihood_reuse'
        ];

        foreach ($required_fields as $field) {
            $value = $request->get_param($field);
            if (!isset($value) || $value === '') {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => "Missing required field: $field"
                ], 400);
            }
        }

        // Validate ratings (1-5)
        $rating_fields = ['overall_satisfaction', 'doctor_professionalism', 'platform_experience', 'likelihood_reuse'];
        foreach ($rating_fields as $field) {
            $value = intval($request->get_param($field));
            if ($value < 1 || $value > 5) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => "Invalid rating for $field. Must be between 1 and 5."
                ], 400);
            }
        }

        $appointment_id = intval($request->get_param('appointment_id'));

        // Get appointment details
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment not found.'
            ], 404);
        }

        // Verify appointment belongs to this patient
        if ($appointment->patient_id !== $patient_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized access to this appointment.'
            ], 403);
        }

        // Check if feedback already exists
        if ($this->feedback_exists($appointment_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Feedback has already been submitted for this appointment.'
            ], 409);
        }

        // Prepare feedback data
        $feedback_data = [
            'appointment_id' => $appointment_id,
            'patient_id' => $patient_id,
            'doctor_id' => $appointment->doctor_id,
            'clinic_id' => $appointment->clinic_id,
            'user_id' => $user_id,
            'overall_satisfaction' => intval($request->get_param('overall_satisfaction')),
            'doctor_professionalism' => intval($request->get_param('doctor_professionalism')),
            'platform_experience' => intval($request->get_param('platform_experience')),
            'likelihood_reuse' => intval($request->get_param('likelihood_reuse')),
            'additional_comments' => $request->get_param('additional_comments') ? sanitize_textarea_field($request->get_param('additional_comments')) : null,
        ];

        // Save feedback
        $feedback_id = $this->save_feedback($feedback_data);

        if ($feedback_id) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Thank you for your feedback!',
                'feedback_id' => $feedback_id
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to save feedback. Please try again.'
        ], 500);
    }


}