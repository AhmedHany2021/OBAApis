<?php

namespace OBA\APIsIntegration\Traits;

trait AppointmentHelper
{
    private function save_appointment($appointment_data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mdclara_appointments';
        $created_at = current_time('mysql');
        $result = $wpdb->insert(
            $table_name,
            array(
                'appointment_id' => $appointment_data['appointment_id'] ?? null,
                'doctor_id' => $appointment_data['doctor_id'] ?? null,
                'clinic_id' => $appointment_data['clinic_id'],
                'patient_id' => $appointment_data['patient_id'],
                'user_id' => $appointment_data['user_id'] ?? null,
                'appointment_date' => $appointment_data['appointment_date'],
                'appointment_time' => $appointment_data['appointment_time'],
                'status' => $appointment_data['status'] ?? 'Requested',
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result !== false) {
            return $wpdb->insert_id;
        }

        return false;
    }

    private function handle_user_wallet($appointment_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mdclara_appointments';

        // Get doctor & patient IDs from appointment
        $ids = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT doctor_id, user_id, clinic_id
             FROM `$table_name` 
             WHERE `id` = %d 
             LIMIT 1",
                $appointment_id
            ),
            ARRAY_A
        );

        if (!$ids) {
            return new \WP_Error('no_appointment', 'No appointment found for this call_id');
        }

        // Get corresponding WP user IDs
        $patient_id = $ids['user_id'];

        $doctor_user_id = null;
        if (!empty($ids['doctor_id'])) {
            $doctor_user_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id 
                 FROM {$wpdb->usermeta} 
                 WHERE meta_key = 'mdclara_doctor_id' 
                   AND meta_value = %s 
                 LIMIT 1",
                    $ids['doctor_id']
                )
            );
        }

        if (!$patient_id) {
            return new \WP_Error('no_user', 'Patient user not found');
        }

        // Determine patient's membership level to fetch the matching doctor/clinic price
        $membership_level = function_exists('pmpro_getMembershipLevelForUser')
            ? pmpro_getMembershipLevelForUser($patient_id)
            : null;
        $membership_level_id = $membership_level->id ?? null;
        $price = null;

        if (empty($ids['doctor_id'])) {
            $price = $this->get_clinic_price($ids['clinic_id'] ?? null, $membership_level_id);
            if (is_wp_error($price)) {
                return $price;
            }
        } else {
            if (!$doctor_user_id) {
                return new \WP_Error('no_user', 'Doctor user not found');
            }

            $price_meta_key = $membership_level_id
                ? "{$membership_level_id}_appointment_price"
                : 'appointment_price';

            // Get price from doctor's profile using membership-specific key when available
            $price = (float) get_user_meta($doctor_user_id, $price_meta_key, true);
        }

        if ($price <= 0) {
            return new \WP_Error('no_price', 'Invalid appointment price');
        }

        // Get API keys
        $wps_keys = get_option('wps_wsfw_wallet_rest_api_keys');
        if (empty($wps_keys['consumer_key']) || empty($wps_keys['consumer_secret'])) {
            return new \WP_Error('no_keys', 'Wallet API keys not set');
        }

        // Call wallet API
        $response = wp_remote_request(
            home_url('/wp-json/wsfw-route/v1/wallet/' . $patient_id),
            [
                'method'  => 'PUT',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode([
                    'amount'            => $price,
                    'action'            => 'debit',
                    'consumer_key'      => $wps_keys['consumer_key'],
                    'consumer_secret'   => $wps_keys['consumer_secret'],
                    'transaction_detail'=> 'Appointment finished',
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return $response; // return the error object
        }

        return wp_remote_retrieve_body($response);
    }

    private function get_clinic_price($clinic_id, $membership_level_id)
    {
        if (!$clinic_id) {
            return new \WP_Error('no_clinic', 'No clinic assigned to appointment');
        }

        $clinics = get_option('mdclara_emergency_clinics');
        if (empty($clinics) || !is_array($clinics)) {
            return new \WP_Error('no_clinics', 'No clinic pricing configured');
        }

        foreach ($clinics as $clinic) {
            if (($clinic['id'] ?? null) !== $clinic_id) {
                continue;
            }

            $price_key = $membership_level_id
                ? "{$membership_level_id}_price"
                : '1_price';

            $price = isset($clinic[$price_key]) ? (float) $clinic[$price_key] : 0.0;
            if ($price <= 0 && $price_key !== '1_price' && isset($clinic['1_price'])) {
                $price = (float) $clinic['1_price'];
            }

            if ($price > 0) {
                return $price;
            }

            return new \WP_Error('no_price', 'No clinic price set for this membership level');
        }

        return new \WP_Error('clinic_not_found', 'Clinic price configuration not found');
    }

    private function send_email_to_patient($patient_id, $selected_date, $selected_time, $appointment_id , $user_id)
    {
        $patient = get_userdata($user_id);
        if (!$patient || !$patient->user_email) {
            return false;
        }
        // Get patient first name
        $patient_first_name = $patient->Patient_first_name;
        // Email subject with placeholders replaced
        $subject = "🩺 Your Telemedicine Appointment – {$selected_date} at {$selected_time}";

        // HTML Email template
        $html_message = $this->get_html_email_template($patient_first_name, $selected_date, $selected_time);

        // Set email headers for HTML
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // Send the email
        return wp_mail($patient->user_email, $subject, $html_message, $headers);
    }

    private function get_html_email_template($patient_first_name, $formatted_date, $formatted_time)
    {
        return '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Appointment Request Received</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
            <div style="background: #071938; border: 1px solid #C7A42DFF; max-width: 600px; margin: 0 auto;">
                <!-- Header -->
                <div style="padding: 40px 30px 30px; text-align: center;">
                    <div style="width: 100px; height: 100px; margin: 0 auto; display: block;">
                        <img src="' . esc_url(home_url('/wp-content/uploads/thegem-logos/logo_7456a2129a94bb4bba126242b052660b_2x.png')) . '" 
                             alt="OBA Health Club Logo" 
                             style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
                    </div>
                    <h1 style="color: #ffffff; font-size: 24px; font-weight: 600; margin: 20px 0 0; line-height: 1.3;">
                        Appointment Request Received
                    </h1>
                </div>
                
                <!-- Content -->
                <div style="padding: 30px; color: #ffffff; line-height: 1.6;">
                    <div style="font-size: 16px; margin-bottom: 20px;">
                        Hello ' . $patient_first_name . ',
                    </div>
                    
                    <div style="font-size: 16px; margin-bottom: 30px;">
                        We have received your appointment on ' . $formatted_date . ' ' . $formatted_time . ' for Regular Visit.
                    </div>
                    
                    <!-- Appointment Details -->
                    <div style="background: rgba(255, 255, 255, 0.1); border-radius: 8px; padding: 20px; margin: 25px 0; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <h3 style="margin: 0 0 15px; color: #C7A42DFF; font-size: 18px;">
                            Appointment Details:
                        </h3>
                        <div style="margin: 8px 0; font-size: 14px;">
                            <span style="font-weight: 600; display: inline-block; width: 80px;">📅 Date:</span> ' . $formatted_date . '
                        </div>
                        <div style="margin: 8px 0; font-size: 14px;">
                            <span style="font-weight: 600; display: inline-block; width: 80px;">🕒 Time:</span> ' . $formatted_time . '
                        </div>
                    </div>
                    
                    <div style="margin: 25px 0; font-size: 16px;">
                        We will let you know as soon as it is confirmed.
                    </div>
                </div>
                
                <!-- Footer -->
                <div style="text-align: center; padding: 20px 30px; border-top: 1px solid rgba(255, 255, 255, 0.3);">
                    <div style="margin: 10px 0; font-size: 14px; color: #ffffff;">
                        Thanks, the OBA team
                    </div>
                    <div style="margin: 10px 0; font-size: 14px; color: #ffffff;">
                        <a href="mailto:support@obahealthclub.com" style="color: #C7A42DFF; text-decoration: none;">
                            support@obahealthclub.com
                        </a><br>
                        <a href="' . esc_url(home_url()) . '" style="color: #C7A42DFF; text-decoration: none;">
                            ' . esc_url(home_url()) . '
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>';
    }

}