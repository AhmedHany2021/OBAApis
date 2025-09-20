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

        $results = $wpdb->get_results(
            "
        SELECT 
            um.meta_value AS mdclara_doctor_id, 
            AVG((f.overall_satisfaction + f.doctor_professionalism + f.platform_experience + f.likelihood_reuse) / 4) AS avg_rating
        FROM {$feedback_table} f
        INNER JOIN {$usermeta_table} um 
            ON um.meta_key = 'mdclara_doctor_id' 
            AND um.meta_value = f.doctor_id
        GROUP BY um.user_id, um.meta_value
        ",
            ARRAY_A
        );

        return new WP_REST_Response([
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