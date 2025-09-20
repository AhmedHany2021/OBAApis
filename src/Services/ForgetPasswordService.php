<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

/**
 * Forget Password Service class
 * 
 * Handles all password reset operations including requesting password reset,
 * verifying reset tokens, and updating user passwords.
 * 
 * @package OBA\APIsIntegration\Services
 */
class ForgetPasswordService
{
    /**
     * Request password reset
     * 
     * Sends a password reset email to the user with a secure reset token.
     * The token is stored in user meta and expires after 1 hour.
     * 
     * @param WP_REST_Request $request The REST request object containing email
     * @return WP_REST_Response Response object with success status and message
     */
    public function request_password_reset(WP_REST_Request $request)
    {
        $email = sanitize_email($request->get_param('email'));

        // Validate required fields
        if (empty($email)) {
            return new WP_Error(
                'missing_email',
                __('Email address is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Validate email format
        if (!is_email($email)) {
            return new WP_Error(
                'invalid_email',
                __('Invalid email format.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Get user by email
        $user = get_user_by('email', $email);
        if (!$user) {
            // For security, don't reveal if email exists or not
            return new WP_REST_Response([
                'success' => true,
                'message' => __('If the email address exists in our system, you will receive a password reset link.', 'oba-apis-integration'),
            ], 200);
        }

        // Check if user is active
        if (!$user->has_cap('read')) {
            return new WP_Error(
                'user_inactive',
                __('User account is inactive.', 'oba-apis-integration'),
                ['status' => 403]
            );
        }

        // Check if user is a patient
        if (!get_user_meta($user->ID, 'mdclara_patient_id', true)) {
            return new WP_Error(
                'user_is_not_patient',
                __('User is not a patient.', 'oba-apis-integration'),
                ['status' => 403]
            );
        }

        // Generate OTP
        $otp = $this->generate_otp();
        $reset_expiry = time() + (60 * 15); // 15 minutes from now

        // Store OTP in user meta
        update_user_meta($user->ID, 'oba_password_reset_otp', $otp);
        update_user_meta($user->ID, 'oba_password_reset_expiry', $reset_expiry);

        // Send OTP email
        $email_sent = $this->send_otp_email($user, $otp);

        if (!$email_sent) {
            return new WP_Error(
                'email_failed',
                __('Failed to send password reset email. Please try again.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Log the reset request
        $this->log_password_reset_request($user->ID, true);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Password reset email sent successfully.', 'oba-apis-integration'),
        ], 200);
    }

    /**
     * Verify reset OTP
     * 
     * Validates a password reset OTP and checks if it's still valid.
     * 
     * @param WP_REST_Request $request The REST request object containing OTP
     * @return WP_REST_Response Response object with validation result
     */
    public function verify_reset_token(WP_REST_Request $request)
    {
        $otp = sanitize_text_field($request->get_param('token'));

        if (empty($otp)) {
            return new WP_Error(
                'missing_otp',
                __('Reset OTP is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Find user with this OTP
        $users = get_users([
            'meta_key' => 'oba_password_reset_otp',
            'meta_value' => $otp,
            'number' => 1,
        ]);

        if (empty($users)) {
            return new WP_Error(
                'invalid_otp',
                __('Invalid or expired reset OTP.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $user = $users[0];
        $expiry = get_user_meta($user->ID, 'oba_password_reset_expiry', true);

        // Check if OTP has expired
        if (empty($expiry) || $expiry < time()) {
            // Clean up expired OTP
            delete_user_meta($user->ID, 'oba_password_reset_otp');
            delete_user_meta($user->ID, 'oba_password_reset_expiry');

            return new WP_Error(
                'expired_otp',
                __('Reset OTP has expired. Please request a new password reset.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Reset OTP is valid.', 'oba-apis-integration'),
            'data' => [
                'user_id' => $user->ID,
                'email' => $user->user_email,
            ],
        ], 200);
    }

    /**
     * Reset password
     * 
     * Updates the user's password using a valid reset token.
     * 
     * @param WP_REST_Request $request The REST request object containing token and new password
     * @return WP_REST_Response Response object with success status and message
     */
    public function reset_password(WP_REST_Request $request)
    {
        $token = sanitize_text_field($request->get_param('token'));
        $new_password = $request->get_param('new_password');
        $confirm_password = $request->get_param('confirm_password');

        // Validate required fields
        if (empty($token) || empty($new_password) || empty($confirm_password)) {
            return new WP_Error(
                'missing_fields',
                __('Token, new password, and confirm password are required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Validate password confirmation
        if ($new_password !== $confirm_password) {
            return new WP_Error(
                'password_mismatch',
                __('Passwords do not match.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Validate password strength
        $password_validation = $this->validate_password_strength($new_password);
        if (is_wp_error($password_validation)) {
            return $password_validation;
        }

        // Find user with this OTP
        $users = get_users([
            'meta_key' => 'oba_password_reset_otp',
            'meta_value' => $token,
            'number' => 1,
        ]);

        if (empty($users)) {
            return new WP_Error(
                'invalid_otp',
                __('Invalid or expired reset OTP.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $user = $users[0];
        $expiry = get_user_meta($user->ID, 'oba_password_reset_expiry', true);

        // Check if OTP has expired
        if (empty($expiry) || $expiry < time()) {
            // Clean up expired OTP
            delete_user_meta($user->ID, 'oba_password_reset_otp');
            delete_user_meta($user->ID, 'oba_password_reset_expiry');

            return new WP_Error(
                'expired_otp',
                __('Reset OTP has expired. Please request a new password reset.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Update user password
        $result = wp_set_password($new_password, $user->ID);

        if (is_wp_error($result)) {
            return new WP_Error(
                'password_update_failed',
                __('Failed to update password. Please try again.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Clean up reset OTP
        delete_user_meta($user->ID, 'oba_password_reset_otp');
        delete_user_meta($user->ID, 'oba_password_reset_expiry');

        // Log successful password reset
        $this->log_password_reset_request($user->ID, true, true);

        // Send confirmation email
        $this->send_password_reset_confirmation_email($user);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Password has been reset successfully.', 'oba-apis-integration'),
        ], 200);
    }

    /**
     * Generate OTP
     * 
     * @return string 6-digit OTP
     */
    private function generate_otp()
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Validate password strength
     * 
     * @param string $password Password to validate
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private function validate_password_strength($password)
    {
        // Minimum length check
        if (strlen($password) < 8) {
            return new WP_Error(
                'weak_password',
                __('Password must be at least 8 characters long.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Check for at least one uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            return new WP_Error(
                'weak_password',
                __('Password must contain at least one uppercase letter.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Check for at least one lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            return new WP_Error(
                'weak_password',
                __('Password must contain at least one lowercase letter.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Check for at least one number
        if (!preg_match('/[0-9]/', $password)) {
            return new WP_Error(
                'weak_password',
                __('Password must contain at least one number.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Check for at least one special character
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return new WP_Error(
                'weak_password',
                __('Password must contain at least one special character.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Send OTP email
     * 
     * @param WP_User $user User object
     * @param string $otp OTP code
     * @return bool True if email sent successfully
     */
    private function send_otp_email($user, $otp)
    {
        $site_name = get_bloginfo('name');

        $subject = sprintf(__('[%s] Password Reset OTP', 'oba-apis-integration'), $site_name);

        $message = sprintf(
            __("Hello %s,\n\nYou have requested to reset your password for your account on %s.\n\nYour password reset OTP is: %s\n\nThis OTP will expire in 15 minutes for security reasons.\n\nIf you did not request this password reset, please ignore this email.\n\nBest regards,\n%s Team", 'oba-apis-integration'),
            $user->display_name,
            $site_name,
            $otp,
            $site_name
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        return wp_mail($user->user_email, $subject, $message, $headers);
    }

    /**
     * Send password reset confirmation email
     * 
     * @param WP_User $user User object
     * @return bool True if email sent successfully
     */
    private function send_password_reset_confirmation_email($user)
    {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $subject = sprintf(__('[%s] Password Reset Successful', 'oba-apis-integration'), $site_name);

        $message = sprintf(
            __("Hello %s,\n\nYour password has been successfully reset for your account on %s.\n\nIf you did not make this change, please contact our support team immediately.\n\nBest regards,\n%s Team", 'oba-apis-integration'),
            $user->display_name,
            $site_name,
            $site_name
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        return wp_mail($user->user_email, $subject, $message, $headers);
    }

    /**
     * Log password reset request
     * 
     * @param int $user_id User ID
     * @param bool $success Whether the request was successful
     * @param bool $completed Whether the password was actually reset
     * @return void
     */
    private function log_password_reset_request($user_id, $success, $completed = false)
    {
        $log_data = [
            'user_id' => $user_id,
            'success' => $success,
            'completed' => $completed,
            'timestamp' => current_time('mysql'),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        // Store in user meta for recent attempts
        $recent_attempts = get_user_meta($user_id, 'oba_password_reset_logs', true) ?: [];
        $recent_attempts[] = $log_data;

        // Keep only last 10 attempts
        if (count($recent_attempts) > 10) {
            $recent_attempts = array_slice($recent_attempts, -10);
        }

        update_user_meta($user_id, 'oba_password_reset_logs', $recent_attempts);
    }

    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    private function get_client_ip()
    {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
