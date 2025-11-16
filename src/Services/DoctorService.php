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

        // STEP 1 — Get all usermeta for doctors (doctor_id + any *_appointment_price)
        $raw_rows = $wpdb->get_results("
        SELECT user_id, meta_key, meta_value 
        FROM {$usermeta_table}
        WHERE meta_key = 'mdclara_doctor_id'
           OR meta_key LIKE '%\\_appointment_price'
    ", ARRAY_A);

        if (empty($raw_rows)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No doctors found'
            ], 404);
        }

        // Build mapping doctor_id → data
        $doctor_map = [];

        foreach ($raw_rows as $row) {

            // Case 1 — Doctor ID entry
            if ($row['meta_key'] === 'mdclara_doctor_id') {

                $doctor_id = $row['meta_value'];

                if (!isset($doctor_map[$doctor_id])) {
                    $doctor_map[$doctor_id] = [
                        'user_id'            => $row['user_id'],
                        'appointment_prices' => []
                    ];
                }

                continue;
            }

            // Case 2 — Appointment prices: pattern "{plan_id}_appointment_price"
            if (preg_match('/^(\d+)_appointment_price$/', $row['meta_key'], $match)) {

                $plan_id = intval($match[1]);

                // Get doctor_id from meta
                $doctor_id = get_user_meta($row['user_id'], 'mdclara_doctor_id', true);
                if (!$doctor_id) continue;

                if (!isset($doctor_map[$doctor_id])) {
                    $doctor_map[$doctor_id] = [
                        'user_id'            => $row['user_id'],
                        'appointment_prices' => []
                    ];
                }

                // Assign price
                $doctor_map[$doctor_id]['appointment_prices'][$plan_id] = $row['meta_value'];
            }
        }

        // STEP 2 — Fetch average ratings
        $feedback_results = $wpdb->get_results("
        SELECT 
            f.doctor_id,
            AVG((f.overall_satisfaction + f.doctor_professionalism + f.platform_experience + f.likelihood_reuse) / 4) AS avg_rating
        FROM {$feedback_table} f
        GROUP BY f.doctor_id
    ", ARRAY_A);

        $feedback_map = [];
        foreach ($feedback_results as $fr) {
            $feedback_map[$fr['doctor_id']] = $fr['avg_rating'];
        }

        $rate = get_option('wps_wsfw_money_ratio', 1);

        // STEP 3 — Build final results
        $results = [];

        foreach ($doctor_map as $doctor_id => $doctor_data) {

            $converted_prices = array_map(function ($price) use ($rate) {
                return floatval($price) / $rate;
            }, $doctor_data['appointment_prices']);

            $results[] = [
                'doctor_id'               => $doctor_id,
                'user_id'                 => $doctor_data['user_id'],
                'appointment_prices'      => $converted_prices,
                'hide_clinic_information' => (bool) get_user_meta($doctor_data['user_id'], 'hide-information', true),
                'avg_rating'              => $feedback_map[$doctor_id] ?? null,
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