# Apple App Store Server Notifications (ASSN) v2 Integration

This document explains how to set up and configure the Apple App Store Server Notifications (ASSN) v2 integration for the OBA APIs plugin.

## Prerequisites

1. **Apple Root CA Certificate**
   - Download the Apple Root CA certificate (G3) from: https://www.apple.com/certificateauthority/AppleRootCA-G3.cer
   - Convert it to PEM format:
     ```bash
     openssl x509 -in AppleRootCA-G3.cer -out apple_root.pem
     ```
   - Place the `apple_root.pem` file in the `/certificates/` directory of the plugin.

2. **Configure Webhook URL in App Store Connect**
   - Log in to [App Store Connect](https://appstoreconnect.apple.com/)
   - Go to "App Information" for your app
   - Under "App Store Server Notifications (Deprecated)", click "Configure"
   - Enter the following URL: `https://your-wordpress-site.com/wp-json/oba/v1/apple/notifications`
   - Select the version (v2)
   - Save the configuration

## Implementation Details

### Endpoints
- **POST** `/wp-json/oba/v1/apple/notifications` - Handles incoming Apple notifications

### Supported Notification Types
- `DID_RENEW` - Handles subscription renewals
- `DID_FAIL_TO_RENEW` - Handles failed renewals (cancels membership)
- `EXPIRED` - Handles expired subscriptions (cancels membership)
- `REFUND` - Handles refunds (cancels membership)

> Note: Only RENEW and CANCEL actions are processed. Other notification types are logged but not processed.

### Logging
All incoming notifications and processing results are logged to:
- File: `/wp-content/uploads/oba-logs/apple-notifications.log`
- WordPress debug log (if `WP_DEBUG` is enabled)

## Testing

### Using ngrok for Local Testing
1. Install ngrok: https://ngrok.com/download
2. Start ngrok: `ngrok http 80`
3. Use the ngrok URL in App Store Connect: `https://your-ngrok-url.ngrok.io/wp-json/oba/v1/apple/notifications`

### Test Notifications
You can send test notifications from App Store Connect:
1. Go to "App Information" for your app
2. Under "App Store Server Notifications", click "Send Test Notification"
3. Select the notification type and click "Send"

## Troubleshooting

### Common Issues
1. **Certificate Verification Failed**
   - Ensure the Apple Root CA certificate is correctly placed in the `/certificates/` directory
   - Verify file permissions (should be readable by the web server)

2. **404 Not Found**
   - Verify the REST API endpoint is registered
   - Check for plugin conflicts
   - Ensure pretty permalinks are enabled

3. **Invalid Signature**
   - Verify the JWT signature verification process
   - Check that the certificate chain is valid

### Debugging
Enable WordPress debug mode by adding these lines to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

## Security Considerations

1. **IP Whitelisting**
   Consider implementing IP whitelisting to only allow requests from Apple's IP ranges:
   - 17.0.0.0/8
   - 17.32.0.0/11
   - 17.64.0.0/10
   - 17.128.0.0/9

2. **Shared Secret**
   For additional security, implement a shared secret in the request headers and verify it in the `verify_webhook` method.

3. **Rate Limiting**
   Consider implementing rate limiting to prevent abuse of the endpoint.

## Dependencies

- PHP 7.4+
- OpenSSL extension
- WooCommerce (for membership functionality)
- Paid Memberships Pro (for membership levels)

## Support

For support, please contact the development team or open an issue in the repository.
