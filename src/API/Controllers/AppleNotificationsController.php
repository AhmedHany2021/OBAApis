<?php

namespace OBA\APIsIntegration\API\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use OBA\APIsIntegration\Services\AppleNotificationHandler;

class AppleNotificationsController {
    /**
     * @var AppleNotificationHandler
     */
    private $notificationHandler;
    
    public function __construct() {
        $this->notificationHandler = new AppleNotificationHandler();
        
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('oba/v1', '/apple/notifications', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_notification'],
                'permission_callback' => [$this, 'verify_webhook'],
                'args'                => [
                    'signedPayload' => [
                        'required'          => true,
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
        ]);
    }
    
    /**
     * Handle incoming Apple notification
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_notification(WP_REST_Request $request) {
        try {
            $payload = $request->get_json_params();
            
            if (empty($payload)) {
                $payload = json_decode($request->get_body(), true);
            }
            
            if (empty($payload)) {
                return new WP_REST_Response([
                    'status' => 'error',
                    'message' => 'No payload received'
                ], 400);
            }
            
            // Process the notification
            $result = $this->notificationHandler->handleNotification($payload);
            
            if ($result['status'] === 'error') {
                return new WP_REST_Response($result, 400);
            }
            
            return new WP_REST_Response($result, 200);
            
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify the webhook request
     * 
     * This is a basic implementation. You might want to add more security
     * measures like IP whitelisting or shared secret verification.
     */
    public function verify_webhook($request) {
        // Allow any request for now, but you should implement proper authentication
        // For example, you could check for a shared secret in the headers
        return true;
        
        // Example of IP whitelisting:
        // $allowed_ips = ['17.0.0.0/8']; // Apple's IP range
        // $client_ip = $_SERVER['REMOTE_ADDR'];
        // return $this->ip_in_range($client_ip, $allowed_ips);
    }
    
    /**
     * Check if an IP is within a range of IPs
     * 
     * @param string $ip IP to check
     * @param array $ranges Array of IP ranges (e.g., ['192.168.1.0/24', '10.0.0.0/8'])
     * @return bool
     */
    private function ip_in_range($ip, $ranges) {
        foreach ($ranges as $range) {
            if ($this->cidr_match($ip, $range)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if an IP is within a CIDR range
     */
    private function cidr_match($ip, $range) {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask; // Ensure subnet is in network byte order
        return ($ip & $mask) == $subnet;
    }
}
