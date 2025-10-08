<?php

namespace OBA\APIsIntegration\Services;

use OBA\APIsIntegration\Traits\AppointmentHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Appointment Service class
 * 
 * Handles all appointment-related operations including creation, retrieval, and management.
 * Integrates with the mdclara_appointments table for storing and managing medical appointments.
 * 
 * @package OBA\APIsIntegration\Services
 */
class AppointmentService
{
    use AppointmentHelper;
    /**
     * Create a new appointment
     * 
     * Creates a new medical appointment record in the system. This method requires:
     * - doctor_id: ID of the doctor
     * - clinic_id: ID of the clinic
     * - patient_id: ID of the patient
     * - appointment_date: Date of the appointment (YYYY-MM-DD)
     * - appointment_time: Time of the appointment (HH:MM)
     * 
     * @param WP_REST_Request $request The REST request object containing appointment data
     * @return WP_REST_Response Response object with success status and message
     */
    public function create(WP_REST_Request $request)
    {
        // Get current user ID from request
        $user_id = $request->get_param('current_user')->ID;
        // Get appointment details from request parameters
        $doctor_id = $request->get_param('doctor_id');
        $clinic_id = $request->get_param('clinic_id');
        $patient_id = $request->get_param('patient_id');
        $appointment_date = $request->get_param('appointment_date');
        $appointment_time = $request->get_param('appointment_time');
        $appointment_id = $request->get_param('appointment_id');

        if ( empty($clinic_id) || empty($patient_id) || empty($appointment_date) || empty($appointment_time)) {
            return new WP_Error(
                'missing_fields',
                __('Required appointment fields are missing.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $appointment_data = [
            'appointment_id'   => $appointment_id,
            'doctor_id'        => $doctor_id,
            'clinic_id'        => $clinic_id,
            'patient_id'       => $patient_id,
            'user_id'          => $user_id,
            'appointment_date' => $appointment_date,
            'appointment_time' => $appointment_time,
        ];
        $appointment = $this->save_appointment($appointment_data);
        $this->send_email_to_patient($appointment_data['patient_id'], $appointment_data['appointment_date'], $appointment_data['appointment_time'], $appointment , $user_id);
        if ($appointment && $appointment > 0) {
            $success = true;
            $message = "appointment created successfully";
            $this->handle_user_wallet($appointment);


        } else
        {
            $success = false;
            $message = "appointment not created due to error";
        }
        return new WP_REST_Response([
            'success'       => $success,
            'message'       => $message,
        ], 200);

    }

    /**
     * Get all appointments for a user
     * 
     * Retrieves all appointments associated with the current user from the database.
     * 
     * @param WP_REST_Request $request The REST request object containing user data
     * @return WP_REST_Response Response object with appointment list
     */
    public function get_appointments(WP_REST_Request $request)
    {
        global $wpdb;
        // Get current user ID from request
        $user_id = $request->get_param('current_user')->ID;
        $table_name = $wpdb->prefix . 'mdclara_appointments';

        $appointments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table_name}` WHERE `user_id` = %d",
                $user_id
            )
        );

        return new WP_REST_Response([
            'success'      => true,
            'appointments' => $appointments
        ], 200);
    }

    /**
     * Get a specific appointment
     * 
     * Retrieves a single appointment record by its ID for the current user.
     * 
     * @param WP_REST_Request $request The REST request object containing appointment ID
     * @return WP_REST_Response Response object with appointment details
     */
    public function get_appointment(WP_REST_Request $request)
    {
        global $wpdb;
        // Get current user ID from request
        $user_id = $request->get_param('current_user')->ID;
        // Get appointment ID from request parameters
        $appointment_id = $request->get_param('id');
        if (!$appointment_id) {
            return new WP_REST_Response(['success' => false, 'message' => 'appointment ID is required.'], 400);
        }

        $table_name = $wpdb->prefix . 'mdclara_appointments';

        $appointment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table_name}` WHERE `user_id` = %d AND `id` = %d",
                $user_id,
                $appointment_id
            ),
            ARRAY_A
        );

        // Not found
        if (!$appointment) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Appointment not found.'
            ], 404);
        }

        return new WP_REST_Response([
            'success'     => true,
            'appointment' => $appointment
        ], 200);

    }

    /**
     * Get a call requests
     *
     * Retrieves user's call requests by its ID for the current user.
     *
     * @param WP_REST_Request $request The REST request object containing appointment ID
     * @return WP_REST_Response Response object with appointment details
     */
    public function call_requests(WP_REST_Request $request) {
        global $wpdb;
        $user_id = $request->get_param('current_user')->ID;
        $call_table = $wpdb->prefix . 'survey_maker_patient_call_requests';
        $access_table = $wpdb->prefix . 'survey_maker_woo_product_access';
        $posts_table = $wpdb->posts;

        // Get call requests for this user (via patient_id = current user UUID)
        $call_requests = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $call_table WHERE patient_id = %s ORDER BY created_at DESC",
                get_user_meta($user_id, 'mdclara_patient_id', true)
            )
        );

        foreach ($call_requests as &$request) {
            $product_titles = [];

            $oba_request_ids = json_decode($request->oba_request_ids, true); // assuming it's stored as JSON

            if (is_array($oba_request_ids)) {
                foreach ($oba_request_ids as $access_id) {
                    // Get the related product ID
                    $access = $wpdb->get_row(
                        $wpdb->prepare("SELECT product_id, doctor_comment FROM $access_table WHERE id = %d AND user_id = %d", $access_id, $user_id)
                    );

                    if ($access) {
                        $product = get_post($access->product_id);

                        if ($product) {
                            $product_titles[] = $product->post_title;
                        }

                        // Add doctor_comment if you need it
                        $request->doctor_comment = $access->doctor_comment;
                    }
                }
            }

            $request->product_names = implode(', ', $product_titles);
        }

        return new WP_REST_Response([
            'success'     => true,
            'appointment' => $call_requests
        ], 200);

    }

}