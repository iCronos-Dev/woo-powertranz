# Woo PowerTranz

WooCommerce payment gateway integration for PowerTranz payment processor.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Payment Flow](#payment-flow)
- [API Integration](#api-integration)
- [What's Implemented](#whats-implemented)
- [What's Left To Do](#whats-left-to-do)
- [Testing](#testing)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [File Structure](#file-structure)

---

## Features

- Credit/Debit Card Payments
- Authorization & Capture (two-step payments)
- Full and Partial Refunds
- Void uncaptured authorizations
- Test/Sandbox Mode
- Admin order integration with transaction details
- HPOS Compatible
- PHP 8.0+

---

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- SSL Certificate (HTTPS)
- PowerTranz Merchant Account

---

## Installation

1. Copy `woo-powertranz` folder to `wp-content/plugins/` (or `web/app/plugins/` for Bedrock)
2. Activate in WordPress Admin → Plugins
3. Configure at WooCommerce → Settings → Payments → Woo PowerTranz

---

## Configuration

### Settings Location

WooCommerce → Settings → Payments → Woo PowerTranz → Manage

### Fields

| Field | Description |
|-------|-------------|
| Enable/Disable | Toggle gateway on/off |
| Title | Payment method name at checkout |
| Description | Text displayed under payment method |
| Merchant ID | PowerTranz merchant identifier |
| API Password | API authentication password |
| Gateway Key | Gateway authentication key |
| Test Mode | Enable sandbox environment |

### API URLs

| Mode | URL |
|------|-----|
| Sandbox | `https://staging.ptranz.com/api/` |
| Production | `https://gateway.ptranz.com/api/` |

---

## Architecture

```
woo-powertranz.php (Main Plugin)
        │
        ├── Woo_PowerTranz_API_Client
        │   └── HTTP communication, response parsing
        │
        ├── WC_Gateway_Woo_PowerTranz
        │   └── WooCommerce integration, payment processing
        │
        └── Woo_PowerTranz_Admin_Order
            └── Admin UI, order meta display
```

---

## Payment Flow

### Authorization
```
Checkout → payment_fields() → validate_fields() → process_payment()
    → API::authorize_payment() → Order: on-hold (Authorized)
```

### Capture
```
Admin → Order Actions → "Capture Payment" → capture_payment()
    → API::capture_payment() → Order: processing (Captured)
```

### Refund
```
Admin → Order → Refund → process_refund()
    → Captured? → API::refund_payment()
    → Not captured? → API::void_payment()
```

---

## API Integration

### Endpoints

| Operation | Endpoint | Method |
|-----------|----------|--------|
| Authorize | `/spi/auth` | POST |
| Capture | `/spi/capture` | POST |
| Refund | `/spi/refund` | POST |
| Void | `/spi/void` | POST |

### Response Structure

```php
[
    'success'            => bool,
    'status'             => 'approved' | 'declined' | 'error',
    'code'               => ?string,
    'message'            => string,
    'transaction_id'     => ?string,
    'authorization_code' => ?string,
    'raw_response'       => ?array,
]
```

---

## What's Implemented

- [x] Gateway registration
- [x] Admin settings page
- [x] Credit card form (classic checkout)
- [x] Card field validation
- [x] Payment authorization
- [x] Order status management
- [x] Transaction meta storage
- [x] Capture via order actions
- [x] Refund support (full & partial)
- [x] Void for uncaptured auth
- [x] WooCommerce logging
- [x] HPOS compatibility
- [x] Admin meta box with transaction details
- [x] Payment column in orders list

---

## What's Left To Do

### Critical

1. **Verify API Endpoints** - Confirm `/spi/auth`, `/spi/capture`, `/spi/refund`, `/spi/void`
2. **Verify Payload Structure** - Field names may differ from assumptions
3. **Verify Response Parsing** - Response field names
4. **Verify Auth Headers** - Header names for authentication

### Important

5. **3D Secure (3DS)** - Not implemented
6. **Webhooks/IPN** - Async notifications
7. **Checkout Blocks** - Only classic checkout supported

### Nice to Have

8. **Tokenization** - Saved cards
9. **Auto-capture** - Setting to capture immediately
10. **Card icons** - Display card brand logos

---

## Testing

### Test Mode

Enable in gateway settings to use sandbox environment.

### Test Cards

Obtain from PowerTranz documentation. Common patterns:
```
Approved: 4111 1111 1111 1111
Declined: 4000 0000 0000 0002
```

### Logs

WooCommerce → Status → Logs → `woo_powertranz-*`

---

## Security

- Card data never logged or stored
- SSL verification enforced
- Input sanitization and validation
- Sensitive fields use password inputs

---

## Troubleshooting

### Gateway Not Appearing
- Check if enabled in settings
- Verify API credentials entered
- Review WooCommerce logs

### Connection Errors
- Verify API endpoint URLs
- Check SSL certificate
- Confirm outbound HTTPS allowed

### Capture/Refund Failing
- Verify transaction ID in order meta
- Check if already captured/refunded
- Review API response in logs

---

## File Structure

```
woo-powertranz/
├── woo-powertranz.php                         # Main plugin file
├── README.md                                       # This file
├── includes/
│   ├── class-woo-powertranz-api-client.php    # API client
│   ├── class-wc-gateway-woo-powertranz.php    # Gateway class
│   └── class-woo-powertranz-admin-order.php   # Admin UI
└── examples/
    └── api-client-usage.php                        # Usage examples
```

---

## Naming Convention Reference

| Type | Pattern | Example |
|------|---------|---------|
| Plugin slug | `woo-powertranz` | Folder name |
| Text domain | `woo-powertranz` | Translation strings |
| Gateway ID | `woo_powertranz` | WooCommerce gateway identifier |
| Class prefix | `Woo_PowerTranz_` | `Woo_PowerTranz_API_Client` |
| WC Gateway | `WC_Gateway_Woo_PowerTranz` | Gateway class name |
| Functions | `woo_powertranz_` | `woo_powertranz_init()` |
| Constants | `WOO_POWERTRANZ_` | `WOO_POWERTRANZ_VERSION` |
| Meta keys | `_woo_powertranz_` | `_woo_powertranz_transaction_id` |
| POST fields | `woo_powertranz_` | `woo_powertranz_card_number` |
| Hooks/Filters | `woo_powertranz_` | `woo_powertranz_payment_payload` |

---

## License

GPL-2.0+
