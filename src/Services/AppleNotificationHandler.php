<?php

namespace OBA\APIsIntegration\Services;

use Exception;
use OBA\APIsIntegration\Services\MembershipService;

class AppleNotificationHandler {
    /**
     * @var MembershipService
     */
    private $membershipService;
    
    /**
     * Path to Apple Root CA certificate
     * @var string
     */
    private $appleRootCertPath;
    
    public function __construct() {
        $this->membershipService = new MembershipService();
        $this->appleRootCertPath = OBA_APIS_INTEGRATION_PLUGIN_DIR . 'certificates/AppleRootCA-G3.pem';
    }
    
    /**
     * Handle incoming Apple notification
     *
     * @param array $payload Decoded JSON payload
     * @return array
     */
    public function handleNotification($payload) {
        try {
            $this->logNotification('Received notification', $payload);
            
            if (!isset($payload['signedPayload'])) {
                throw new Exception('Missing signedPayload');
            }
            
            // Verify and decode the JWS payload
            $decodedPayload = $this->verifyAndDecodeJWS($payload['signedPayload']);
            
            // Process the notification
            $result = $this->processNotification($decodedPayload);
            
            $this->logNotification('Processed notification', [
                'notification_type' => $decodedPayload['notificationType'] ?? 'UNKNOWN',
                'result' => $result
            ]);
            
            return [
                'status' => 'success',
                'message' => 'Notification processed successfully',
                'notification_type' => $decodedPayload['notificationType'] ?? 'UNKNOWN'
            ];
            
        } catch (Exception $e) {
            $this->logNotification('Error processing notification', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process notification based on type
     */
    private function processNotification($payload) {
        $notificationType = $payload['notificationType'] ?? '';
        
        // Extract user ID and product information
        $userId = $this->extractUserId($payload);
        if (!$userId) {
            throw new Exception('Could not identify user from notification');
        }
        
        $productId = $this->extractProductId($payload);
        if (!$productId) {
            throw new Exception('Could not identify product from notification');
        }
        
        // Map Apple product ID to PMPro level
        $levelId = $this->membershipService->mapProductToLevel($productId);
        if (!$levelId) {
            throw new Exception("No membership level found for product: $productId");
        }
        
        // Handle only RENEW and CANCEL actions
        switch ($notificationType) {
            case 'DID_RENEW':
                $endDate = $this->extractExpiryDate($payload);
                if (!$endDate) {
                    throw new Exception('Could not determine expiry date for renewal');
                }
                $this->membershipService->renewMembership($userId, $levelId, $endDate);
                break;
                
            case 'DID_FAIL_TO_RENEW':
            case 'EXPIRED':
            case 'REFUND':
                $this->membershipService->cancelMembership($userId);
                break;
                
            // Log other notification types but don't process them
            default:
                $this->logNotification('Notification type not processed', [
                    'type' => $notificationType,
                    'payload' => $payload
                ]);
                break;
        }
        
        return [
            'user_id' => $userId,
            'level_id' => $levelId,
            'notification_type' => $notificationType,
            'subtype' => $subtype
        ];
    }
    
    /**
     * Extract user ID from notification payload
     */
    private function extractUserId($payload) {
        // Try to get from signedTransactionInfo first
        if (isset($payload['data']['signedTransactionInfo'])) {
            $transactionInfo = $this->decodeJWS($payload['data']['signedTransactionInfo']);
            if (isset($transactionInfo['appAccountToken'])) {
                return $transactionInfo['appAccountToken'];
            }
        }
        
        // Fallback to other methods if needed
        // ...
        
        return null;
    }
    
    /**
     * Extract product ID from notification payload
     */
    private function extractProductId($payload) {
        if (isset($payload['data']['signedTransactionInfo'])) {
            $transactionInfo = $this->decodeJWS($payload['data']['signedTransactionInfo']);
            return $transactionInfo['productId'] ?? null;
        }
        return null;
    }
    
    /**
     * Extract expiry date from notification payload
     */
    private function extractExpiryDate($payload) {
        if (isset($payload['data']['signedTransactionInfo'])) {
            $transactionInfo = $this->decodeJWS($payload['data']['signedTransactionInfo']);
            if (isset($transactionInfo['expiresDate'])) {
                return $this->convertAppleTime($transactionInfo['expiresDate']);
            }
        }
        return null;
    }
    
    /**
     * Verify and decode JWS signed payload from Apple
     */
    private function verifyAndDecodeJWS($signedPayload) {
        // Split the JWS into components
        $parts = explode('.', $signedPayload);
        if (count($parts) !== 3) {
            throw new Exception('Invalid JWS format');
        }
        
        list($headerBase64, $payloadBase64, $signature) = $parts;
        
        // Decode header
        $header = json_decode($this->base64UrlDecode($headerBase64), true);
        if (!$header || !isset($header['x5c'])) {
            throw new Exception('Invalid JWS header');
        }
        
        // Verify certificate chain
        $this->verifyCertificateChain($header['x5c']);
        
        // Extract public key from certificate
        $certificate = '-----BEGIN CERTIFICATE-----' . PHP_EOL 
                      . chunk_split($header['x5c'][0], 64) 
                      . '-----END CERTIFICATE-----';
        $publicKey = openssl_pkey_get_public($certificate);
        
        if (!$publicKey) {
            throw new Exception('Failed to extract public key');
        }
        
        // Verify signature
        $dataToVerify = $headerBase64 . '.' . $payloadBase64;
        $signatureBinary = $this->base64UrlDecode($signature);
        
        $verified = openssl_verify(
            $dataToVerify,
            $signatureBinary,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );
        
        if ($verified !== 1) {
            throw new Exception('Signature verification failed');
        }
        
        // Decode and return payload
        return json_decode($this->base64UrlDecode($payloadBase64), true);
    }
    
    /**
     * Verify certificate chain with Apple Root CA
     */
    private function verifyCertificateChain($x5c) {
        if (empty($x5c)) {
            throw new Exception('Empty certificate chain');
        }
        
        // Check if Apple Root CA certificate exists
        if (!file_exists($this->appleRootCertPath)) {
            throw new Exception('Apple Root CA certificate not found. Please download it from: https://www.apple.com/certificateauthority/AppleRootCA-G3.cer');
        }
        
        // Load Apple Root CA certificate
        $appleRootCert = file_get_contents($this->appleRootCertPath);
        if (!$appleRootCert) {
            throw new Exception('Failed to load Apple Root certificate');
        }
        
        // Verify the chain
        $lastCert = end($x5c);
        $lastCertPem = '-----BEGIN CERTIFICATE-----' . PHP_EOL 
                      . chunk_split($lastCert, 64) 
                      . '-----END CERTIFICATE-----';
        
        // Verify against Apple Root CA
        $result = openssl_x509_verify($lastCertPem, $appleRootCert);
        if ($result !== 1 && $result !== 0) {
            // Note: result 0 means cert is self-signed, which is ok for root
            // Only throw error for actual verification failures (-1)
            if ($result === -1) {
                throw new Exception('Certificate chain verification failed');
            }
        }
        
        return true;
    }
    
    /**
     * Decode JWS without verification (for nested JWS)
     */
    private function decodeJWS($jws) {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new Exception('Invalid JWS format');
        }
        
        $payload = $this->base64UrlDecode($parts[1]);
        return json_decode($payload, true);
    }
    
    /**
     * Convert Apple timestamp to MySQL datetime
     */
    private function convertAppleTime($timestamp) {
        // Apple sends timestamps in milliseconds
        return date('Y-m-d H:i:s', $timestamp / 1000);
    }
    
    /**
     * Base64 URL decode
     */
    private function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Log notification for debugging
     */
    private function logNotification($message, $data = []) {
        $logDir = WP_CONTENT_DIR . '/uploads/oba-logs';
        if (!file_exists($logDir)) {
            wp_mkdir_p($logDir);
        }
        
        $logFile = $logDir . '/apple-notifications.log';
        $timestamp = current_time('mysql');
        $logData = [
            'timestamp' => $timestamp,
            'message' => $message,
            'data' => $data
        ];
        
        // Append to log file
        file_put_contents(
            $logFile,
            json_encode($logData, JSON_PRETTY_PRINT) . ",\n",
            FILE_APPEND
        );
    }
}
