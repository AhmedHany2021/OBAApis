<?php

namespace OBA\APIsIntegration\Services;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class DoctorService
{
    public function get_doctor_rating(WP_REST_Request $request)
    {
        global $wpdb;

        $feedback_table = $wpdb->prefix . 'mdclara_feedback';
        $usermeta_table = $wpdb->usermeta;

        // Step 1: Get all doctors with their appointment_price
        $doctor_users = $wpdb->get_results(
            $wpdb->prepare("
            SELECT u1.user_id, u1.meta_value AS doctor_id, u2.meta_value AS appointment_price
            FROM {$usermeta_table} u1
            LEFT JOIN {$usermeta_table} u2 
                ON u1.user_id = u2.user_id AND u2.meta_key = %s
            WHERE u1.meta_key = %s
        ", 'appointment_price', 'mdclara_doctor_id'),
            ARRAY_A
        );

        if (empty($doctor_users)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No doctors found'
            ], 404);
        }

        // Build mapping doctor_id => [user_id, appointment_price]
        $doctor_map = [];
        foreach ($doctor_users as $du) {
            $doctor_map[$du['doctor_id']] = [
                'user_id'           => $du['user_id'],
                'appointment_price' => $du['appointment_price'],
            ];
        }

        // Step 2: Fetch average ratings (only for doctors that have feedback)
        $feedback_results = $wpdb->get_results(
            "
        SELECT 
            f.doctor_id,
            AVG((f.overall_satisfaction + f.doctor_professionalism + f.platform_experience + f.likelihood_reuse) / 4) AS avg_rating
        FROM {$feedback_table} f
        GROUP BY f.doctor_id
        ",
            ARRAY_A
        );

        $feedback_map = [];
        foreach ($feedback_results as $fr) {
            $feedback_map[$fr['doctor_id']] = $fr['avg_rating'];
        }

        $rate = get_option('wps_wsfw_money_ratio', 1);

        // Step 3: Build final result: include all doctors, feedback if exists
        $results = [];
        foreach ($doctor_map as $doctor_id => $doctor_data) {
            $results[] = [
                'doctor_id'         => $doctor_id,
                'user_id'           => $doctor_data['user_id'],
                'appointment_price' => $doctor_data['appointment_price'] / $rate,
                'avg_rating'        => $feedback_map[$doctor_id] ?? null, // null if no feedback yet
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'results' => $results
        ]);
    }


    /**
     * Get emergency clinics options
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_emergency_clinics(WP_REST_Request $request)
    {
        // Get the mdclara_emergency_clinics option from WordPress options
        $emergency_clinics = get_option('mdclara_emergency_clinics', []);

        // If the option doesn't exist or is empty, return empty array
        if (empty($emergency_clinics)) {
            $emergency_clinics = [];
        }

        // Ensure it's an array
        if (!is_array($emergency_clinics)) {
            $emergency_clinics = [];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $emergency_clinics
        ]);
    }
}