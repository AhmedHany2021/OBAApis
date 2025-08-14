<?php

namespace OBA\APIsIntegration\Traits;

trait CallHelper
{
    private $feedback_table_name = 'mdclara_feedback';
    /**
     * Get appointment by ID
     *
     * @param int $appointment_id The appointment ID
     * @return object|null Appointment object or null if not found
     */
    public function get_appointment($appointment_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->appointment_table_name;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE appointment_id = %d",
                $appointment_id
            )
        );
    }

    /**
     * Check if feedback exists for appointment
     *
     * @param int $appointment_id The appointment ID
     * @return bool True if feedback exists, false otherwise
     */
    public function feedback_exists($appointment_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->feedback_table_name;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE appointment_id = %d",
                $appointment_id
            )
        );

        return $count > 0;
    }

    /**
     * Save feedback to the database
     *
     * @param array $feedback_data The feedback data
     * @return int|false The feedback ID, or false on error
     */
    public function save_feedback($feedback_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->feedback_table_name;

        $created_at = current_time('mysql');

        $result = $wpdb->insert(
            $table_name,
            array(
                'appointment_id' => $feedback_data['appointment_id'],
                'patient_id' => $feedback_data['patient_id'],
                'doctor_id' => $feedback_data['doctor_id'],
                'clinic_id' => $feedback_data['clinic_id'],
                'user_id' => $feedback_data['user_id'],
                'overall_satisfaction' => $feedback_data['overall_satisfaction'],
                'doctor_professionalism' => $feedback_data['doctor_professionalism'],
                'platform_experience' => $feedback_data['platform_experience'],
                'likelihood_reuse' => $feedback_data['likelihood_reuse'],
                'additional_comments' => $feedback_data['additional_comments'] ?? null,
                'created_at' => $created_at,
            ),
            array('%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s')
        );

        // Add error checking
        if ($result === false) {
            error_log('MDClara DB Error: ' . $wpdb->last_error);
            error_log('MDClara Last Query: ' . $wpdb->last_query);
            wp_send_json_error(['message' => print_r($wpdb->last_error, true)]);
        }

        if ($result !== false) {
            return $wpdb->insert_id;
        }

        return false;
    }

}