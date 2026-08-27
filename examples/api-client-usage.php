<?php
/**
 * Example Usage: Woo PowerTranz API Client
 *
 * This file demonstrates how to use the Woo_PowerTranz_API_Client class.
 * DO NOT use this file in production - it's for reference only.
 *
 * SECURITY WARNING: This file is for documentation and reference purposes ONLY.
 * - Never execute this file directly
 * - Never use real credentials in example code
 * - Never commit real API credentials to version control
 *
 * @package Woo_PowerTranz
 */

// Prevent direct file access for security.
defined( 'ABSPATH' ) || exit;

// This file is for documentation purposes only.
// phpcs:disable

/*
|--------------------------------------------------------------------------
| Basic Instantiation
|--------------------------------------------------------------------------
*/

// Test/Sandbox environment.
$client = new Woo_PowerTranz_API_Client(
    'https://staging.ptranz.com/api',  // API URL (sandbox)
    'YOUR_MERCHANT_ID',                 // Merchant ID (PowerTranzID)
    'YOUR_API_PASSWORD',                // API Password (Processor Password)
    30                                  // Timeout in seconds
);

// Production environment.
$client_production = new Woo_PowerTranz_API_Client(
    'https://gateway.ptranz.com/api',
    'YOUR_MERCHANT_ID',
    'YOUR_API_PASSWORD'
);

/*
|--------------------------------------------------------------------------
| Configure Additional Headers (Optional)
|--------------------------------------------------------------------------
*/

$client->set_additional_headers([
    'X-Custom-Header' => 'custom-value',
    'X-Request-Id'    => uniqid('ept_', true),
]);

/*
|--------------------------------------------------------------------------
| Authorize a Payment
|--------------------------------------------------------------------------
*/

$payload = [
    'TransactionIdentifier' => uniqid('TXN_', true),
    'TotalAmount'           => 100.00,
    'CurrencyCode'          => '840',
    'ThreeDSecure'          => true,
    'Source'                => [
        'CardPan'           => '4111111111111111',
        'CardCvv'           => '123',
        'CardExpiration'    => '2512',
        'CardholderName'    => 'John Doe',
    ],
    'OrderIdentifier'       => 'ORDER-12345',
    'BillingAddress'        => [
        'FirstName'   => 'John',
        'LastName'    => 'Doe',
        'Line1'       => '123 Main Street',
        'City'        => 'Miami',
        'State'       => 'FL',
        'PostalCode'  => '33101',
        'CountryCode' => 'US',
        'EmailAddress'=> 'john.doe@example.com',
        'PhoneNumber' => '+1-305-555-0100',
    ],
];

$response = $client->authorize_payment($payload);

/*
|--------------------------------------------------------------------------
| Handle the Response
|--------------------------------------------------------------------------
*/

if ($response['success']) {
    echo "Payment approved!\n";
    echo "Transaction ID: " . $response['transaction_id'] . "\n";
    echo "Authorization Code: " . $response['authorization_code'] . "\n";
} elseif ($response['status'] === 'declined') {
    echo "Payment declined: " . $response['message'] . "\n";
} else {
    echo "Payment error: " . $response['message'] . "\n";
}

/*
|--------------------------------------------------------------------------
| Response Examples
|--------------------------------------------------------------------------
*/

// Approved response:
$approved_response = [
    'success'            => true,
    'status'             => 'approved',
    'code'               => '00',
    'message'            => 'Transaction approved.',
    'transaction_id'     => 'TXN_6789abcd',
    'authorization_code' => 'AUTH123',
    'raw_response'       => [],
];

// Declined response:
$declined_response = [
    'success'            => false,
    'status'             => 'declined',
    'code'               => '51',
    'message'            => 'Insufficient funds.',
    'transaction_id'     => 'TXN_6789abcd',
    'authorization_code' => null,
    'raw_response'       => [],
];

// Error response:
$error_response = [
    'success'            => false,
    'status'             => 'error',
    'code'               => 'http_request_failed',
    'message'            => 'Unable to connect to payment gateway.',
    'transaction_id'     => null,
    'authorization_code' => null,
    'raw_response'       => null,
];

// phpcs:enable
