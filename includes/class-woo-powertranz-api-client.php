<?php
/**
 * Woo PowerTranz API Client
 *
 * Handles all HTTP communication with the PowerTranz payment gateway API.
 * This class is intentionally decoupled from WooCommerce to maintain
 * separation of concerns and allow for easier testing.
 *
 * @package Woo_PowerTranz
 * @since 1.0.0
 */

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Woo PowerTranz API Client Class
 *
 * Responsible for making HTTP requests to the PowerTranz API and
 * normalizing responses into a consistent format.
 *
 * Security considerations:
 * - Request payloads are NOT logged to prevent sensitive data exposure
 * - Card data is NOT stored at any point
 * - All credentials are passed at instantiation, not stored in files
 *
 * @since 1.0.0
 */
class Woo_PowerTranz_API_Client {

	/**
	 * PowerTranz API base URL.
	 *
	 * @var string
	 */
	private string $api_url;

	/**
	 * Merchant ID for API authentication.
	 *
	 * @var string
	 */
	private string $merchant_id;

	/**
	 * API Password for authentication.
	 *
	 * @var string
	 */
	private string $api_password;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Additional headers to include in requests.
	 *
	 * @var array<string, string>
	 */
	private array $additional_headers;

	/**
	 * Response code indicating successful authorization.
	 */
	private const RESPONSE_CODE_APPROVED = '00';

	/**
	 * Response code indicating 3DS preprocessing is complete.
	 * The merchant must render the RedirectData iframe and then complete via /spi/Payment.
	 */
	private const RESPONSE_CODE_3DS_REQUIRED = 'SP4';

	/**
	 * Constructor.
	 *
	 * @param string $api_url      The PowerTranz API base URL.
	 * @param string $merchant_id  The merchant identifier.
	 * @param string $api_password The API password.
	 * @param int    $timeout      Request timeout in seconds (default: 30).
	 */
	public function __construct(
		string $api_url,
		string $merchant_id,
		string $api_password,
		int $timeout = 30
	) {
		$this->api_url            = rtrim( $api_url, '/' );
		$this->merchant_id        = $merchant_id;
		$this->api_password       = $api_password;
		$this->timeout            = $timeout;
		$this->additional_headers = array();
	}

	/**
	 * Set additional headers for API requests.
	 *
	 * Allows configuration of custom headers that will be merged
	 * with the default headers on each request.
	 *
	 * @param array<string, string> $headers Associative array of header name => value.
	 * @return self Returns $this for method chaining.
	 */
	public function set_additional_headers( array $headers ): self {
		$this->additional_headers = $headers;
		return $this;
	}

	/**
	 * Set the request timeout.
	 *
	 * @param int $timeout Timeout in seconds.
	 * @return self Returns $this for method chaining.
	 */
	public function set_timeout( int $timeout ): self {
		$this->timeout = $timeout;
		return $this;
	}

	/*
	|--------------------------------------------------------------------------
	| API DEPENDENCY NOTES
	|--------------------------------------------------------------------------
	|
	| The following methods depend on PowerTranz API endpoint availability:
	|
	| METHOD                 | ENDPOINT                | DEPENDS ON
	| -----------------------|-------------------------|---------------------------
	| authorize_payment()    | /spi/Auth               | SPI Auth API (3DS)
	| sale_payment()         | /spi/Sale               | SPI Sale API (auth+capture)
	| complete_payment()     | /spi/Payment            | SPI Payment (no auth headers)
	| capture_payment()      | /Capture                | Capture API support
	| refund_payment()       | /Refund                 | Refund API support
	| void_payment()         | /Void                   | Void API support
	|
	| IMPORTANT: Verify these endpoints with PowerTranz API documentation.
	| The actual endpoint paths may differ based on your integration type:
	| - SPI (Server Payment Interface)
	| - HPP (Hosted Payment Page)
	| - Direct API
	|
	| Some PowerTranz integrations may not support all operations.
	| Check your merchant agreement for supported transaction types.
	|
	*/

	/**
	 * Authorize a payment transaction.
	 *
	 * Sends an authorization request to the PowerTranz API.
	 * This method validates the payload structure but does NOT
	 * log the payload contents for security reasons.
	 *
	 * @param array $payload The payment authorization payload.
	 *                       Expected structure varies by integration type.
	 *                       Typically includes: TransactionIdentifier, TotalAmount,
	 *                       CurrencyCode, ThreeDSecure (if applicable), Source, etc.
	 *
	 * @return array{
	 *     success: bool,
	 *     status: string,
	 *     code: string|null,
	 *     message: string,
	 *     transaction_id: string|null,
	 *     authorization_code: string|null,
	 *     raw_response: array|null
	 * } Normalized response array with consistent structure.
	 *
	 * Status values:
	 * - 'approved': Transaction was approved
	 * - 'declined': Transaction was declined by issuer/processor
	 * - 'error': Technical error occurred (timeout, connection, etc.)
	 */
	public function authorize_payment( array $payload ): array {
		// Validate that payload is not empty.
		if ( empty( $payload ) ) {
			return $this->build_error_response(
				'INVALID_PAYLOAD',
				'Payment payload cannot be empty.'
			);
		}

		// Build the full endpoint URL for authorization.
		$endpoint = $this->api_url . '/spi/Auth';

		// Make the API request.
		$response = $this->make_request( $endpoint, $payload );

		// Handle WP_Error (connection failures, timeouts, etc.).
		if ( is_wp_error( $response ) ) {
			return $this->handle_wp_error( $response );
		}

		// Parse and normalize the API response.
		return $this->parse_authorization_response( $response );
	}

	/**
	 * Process a Sale transaction (authorization + capture in one step).
	 *
	 * Unlike authorize_payment() which only authorizes, this method sends
	 * a Sale request that automatically captures the funds upon approval.
	 * This is the preferred method for immediate-delivery transactions
	 * like ticket sales.
	 *
	 * @param array $payload The payment payload (same structure as authorize_payment).
	 *
	 * @return array Normalized response array (same structure as authorize_payment).
	 */
	public function sale_payment( array $payload ): array {
		if ( empty( $payload ) ) {
			return $this->build_error_response(
				'INVALID_PAYLOAD',
				'Payment payload cannot be empty.'
			);
		}

		$endpoint = $this->api_url . '/spi/Sale';

		$response = $this->make_request( $endpoint, $payload );

		if ( is_wp_error( $response ) ) {
			return $this->handle_wp_error( $response );
		}

		return $this->parse_authorization_response( $response );
	}

	/**
	 * Complete a 3DS-authenticated payment using the SPI token.
	 *
	 * After the 3DS iframe challenge completes, this method sends the SPI token
	 * to finalize the payment. The /spi/Payment endpoint does NOT require
	 * authentication headers - only the SPI token in the request body.
	 *
	 * @param string $spi_token The SPI token received from the authorize step.
	 *
	 * @return array Normalized response array (same structure as authorize_payment).
	 */
	public function complete_payment( string $spi_token ): array {
		if ( empty( $spi_token ) ) {
			return $this->build_error_response(
				'INVALID_SPI_TOKEN',
				'SPI token is required to complete payment.'
			);
		}

		$endpoint = $this->api_url . '/spi/Payment';

		$response = $this->make_unauthenticated_request( $endpoint, $spi_token );

		if ( is_wp_error( $response ) ) {
			return $this->handle_wp_error( $response );
		}

		return $this->parse_authorization_response( $response );
	}

	/**
	 * Capture a previously authorized payment.
	 *
	 * Captures (settles) an authorization, moving funds from the cardholder
	 * to the merchant. This completes the two-step payment process.
	 *
	 * API DEPENDENCY: Requires PowerTranz Capture API support.
	 * Endpoint: /Capture
	 *
	 * @param string   $transaction_id The original authorization transaction ID.
	 * @param float    $amount         Amount to capture as decimal (e.g. 20.50).
	 *                                 Must be <= original authorization amount.
	 * @param string   $currency_code  ISO 4217 numeric currency code.
	 * @param string[] $options        Optional additional parameters.
	 *
	 * @return array Normalized response array (same structure as authorize_payment).
	 */
	public function capture_payment(
		string $transaction_id,
		float $amount,
		string $currency_code,
		array $options = array()
	): array {
		// Validate required parameters.
		if ( empty( $transaction_id ) ) {
			return $this->build_error_response(
				'INVALID_TRANSACTION_ID',
				'Transaction ID is required for capture.'
			);
		}

		if ( $amount <= 0 ) {
			return $this->build_error_response(
				'INVALID_AMOUNT',
				'Capture amount must be greater than zero.'
			);
		}

		/*
		 * Build the capture payload.
		 *
		 * NOTE: The exact payload structure depends on PowerTranz API version.
		 * Verify field names with official documentation:
		 * - TransactionIdentifier: Original auth transaction ID
		 * - TotalAmount: Amount to capture (may support partial capture)
		 * - CurrencyCode: Must match original authorization
		 */
		$payload = array_merge(
			array(
				'TransactionIdentifier' => $transaction_id,
				'TotalAmount'           => $amount,
				'CurrencyCode'          => $currency_code,
			),
			$options
		);

		$endpoint = $this->api_url . '/Capture';

		// Make the API request.
		$response = $this->make_request( $endpoint, $payload );

		// Handle WP_Error.
		if ( is_wp_error( $response ) ) {
			return $this->handle_wp_error( $response );
		}

		// Parse response (uses same format as authorization).
		return $this->parse_capture_response( $response );
	}

	/**
	 * Refund a captured payment.
	 *
	 * Issues a refund for a previously captured (settled) transaction.
	 * Supports both full and partial refunds.
	 *
	 * API DEPENDENCY: Requires PowerTranz Refund API support.
	 * Endpoint: /Refund
	 *
	 * @param string $transaction_id The original transaction ID to refund.
	 * @param float  $amount         Amount to refund as decimal (e.g. 20.50).
	 *                               For partial refund, must be <= captured amount.
	 * @param string $currency_code  ISO 4217 numeric currency code.
	 * @param string $reason         Optional reason for the refund.
	 *
	 * @return array Normalized response array.
	 */
	public function refund_payment(
		string $transaction_id,
		float $amount,
		string $currency_code,
		string $reason = ''
	): array {
		// Validate required parameters.
		if ( empty( $transaction_id ) ) {
			return $this->build_error_response(
				'INVALID_TRANSACTION_ID',
				'Transaction ID is required for refund.'
			);
		}

		if ( $amount <= 0 ) {
			return $this->build_error_response(
				'INVALID_AMOUNT',
				'Refund amount must be greater than zero.'
			);
		}

		/*
		 * Build the refund payload.
		 *
		 * NOTE: The exact payload structure depends on PowerTranz API version.
		 * Some APIs may require additional fields like:
		 * - OriginalTransactionIdentifier vs TransactionIdentifier
		 * - RefundReason or Description
		 * - MerchantReference
		 */
		$payload = array(
			'TransactionIdentifier' => $transaction_id,
			'TotalAmount'           => $amount,
			'CurrencyCode'          => $currency_code,
			'Refund'                => true,
		);

		// Add reason if provided.
		if ( ! empty( $reason ) ) {
			$payload['RefundReason'] = sanitize_text_field( $reason );
		}

		$endpoint = $this->api_url . '/Refund';

		// Make the API request.
		$response = $this->make_request( $endpoint, $payload );

		// Handle WP_Error.
		if ( is_wp_error( $response ) ) {
			return $this->handle_wp_error( $response );
		}

		// Parse response.
		return $this->parse_refund_response( $response );
	}

	/**
	 * Void an authorized (uncaptured) payment.
	 *
	 * Cancels an authorization before it has been captured.
	 * Use this instead of refund for uncaptured transactions.
	 *
	 * API DEPENDENCY: Requires PowerTranz Void API support.
	 * Endpoint: /Void
	 *
	 * @param string $transaction_id The authorization transaction ID to void.
	 *
	 * @return array Normalized response array.
	 */
	public function void_payment( string $transaction_id ): array {
		// Validate required parameters.
		if ( empty( $transaction_id ) ) {
			return $this->build_error_response(
				'INVALID_TRANSACTION_ID',
				'Transaction ID is required for void.'
			);
		}

		/*
		 * Build the void payload.
		 *
		 * NOTE: Void requests typically only need the transaction ID.
		 * Some APIs may require additional fields.
		 */
		$payload = array(
			'TransactionIdentifier' => $transaction_id,
		);

		$endpoint = $this->api_url . '/Void';

		// Make the API request.
		$response = $this->make_request( $endpoint, $payload );

		// Handle WP_Error.
		if ( is_wp_error( $response ) ) {
			return $this->handle_wp_error( $response );
		}

		// Parse response.
		return $this->parse_void_response( $response );
	}

	/**
	 * Parse capture response from PowerTranz.
	 *
	 * @param array $response The raw response from wp_remote_post.
	 *
	 * @return array Normalized response array.
	 */
	private function parse_capture_response( array $response ): array {
		return $this->parse_transaction_response( $response, 'capture' );
	}

	/**
	 * Parse refund response from PowerTranz.
	 *
	 * @param array $response The raw response from wp_remote_post.
	 *
	 * @return array Normalized response array.
	 */
	private function parse_refund_response( array $response ): array {
		return $this->parse_transaction_response( $response, 'refund' );
	}

	/**
	 * Parse void response from PowerTranz.
	 *
	 * @param array $response The raw response from wp_remote_post.
	 *
	 * @return array Normalized response array.
	 */
	private function parse_void_response( array $response ): array {
		return $this->parse_transaction_response( $response, 'void' );
	}

	/**
	 * Parse a generic transaction response.
	 *
	 * Shared parsing logic for capture, refund, and void responses.
	 *
	 * @param array  $response         The raw response from wp_remote_post.
	 * @param string $transaction_type The type of transaction (capture, refund, void).
	 *
	 * @return array Normalized response array.
	 */
	private function parse_transaction_response( array $response, string $transaction_type ): array {
		// Get HTTP status code.
		$http_code = wp_remote_retrieve_response_code( $response );

		// Get response body.
		$body = wp_remote_retrieve_body( $response );

		// Attempt to decode JSON response.
		$data = json_decode( $body, true );

		// Handle non-2xx HTTP responses.
		if ( $http_code < 200 || $http_code >= 300 ) {
			return $this->build_error_response(
				'HTTP_' . $http_code,
				sprintf(
					/* translators: 1: transaction type, 2: error message */
					'%1$s failed: %2$s',
					ucfirst( $transaction_type ),
					$this->get_http_error_message( $http_code )
				),
				$data
			);
		}

		// Handle JSON decode failure.
		if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
			return $this->build_error_response(
				'INVALID_RESPONSE',
				sprintf( 'Invalid %s response received from payment gateway.', $transaction_type )
			);
		}

		// Extract response code.
		$response_code = $data['IsoResponseCode'] ?? $data['ResponseCode'] ?? null;

		// Check if transaction was successful.
		if ( self::RESPONSE_CODE_APPROVED === $response_code ) {
			return array(
				'success'            => true,
				'status'             => 'approved',
				'code'               => $response_code,
				'message'            => $data['ResponseMessage'] ?? ucfirst( $transaction_type ) . ' successful.',
				'transaction_id'     => $data['TransactionIdentifier'] ?? null,
				'authorization_code' => $data['AuthorizationCode'] ?? null,
				'raw_response'       => $data,
			);
		}

		// Transaction failed.
		return array(
			'success'            => false,
			'status'             => 'declined',
			'code'               => $response_code,
			'message'            => $data['ResponseMessage'] ?? ucfirst( $transaction_type ) . ' was declined.',
			'transaction_id'     => $data['TransactionIdentifier'] ?? null,
			'authorization_code' => null,
			'raw_response'       => $data,
		);
	}

	/**
	 * Make an HTTP POST request to the PowerTranz API.
	 *
	 * Handles the low-level HTTP communication using WordPress HTTP API.
	 * Request payloads are intentionally NOT logged to prevent
	 * sensitive data (card numbers, CVV) from being exposed.
	 *
	 * @param string $endpoint The full API endpoint URL.
	 * @param array  $payload  The request payload to send as JSON.
	 *
	 * @return array|\WP_Error The response array or WP_Error on failure.
	 */
	private function make_request( string $endpoint, array $payload ): array|\WP_Error {
		// Build request headers.
		$headers = $this->build_headers();

		// Prepare request arguments for wp_remote_post.
		$args = array(
			'method'      => 'POST',
			'timeout'     => $this->timeout,
			'redirection' => 0, // Do not follow redirects for payment APIs.
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => wp_json_encode( $payload ),
			'sslverify'   => true, // Always verify SSL for payment processing.
		);

		// Execute the request.
		// Note: We intentionally do NOT log the request body as it may contain card data.
		return wp_remote_post( $endpoint, $args );
	}

	/**
	 * Make an HTTP POST request without authentication headers.
	 *
	 * Used for the /spi/Payment endpoint which only requires the SPI token
	 * as a JSON string in the body - no PowerTranz auth headers.
	 *
	 * @param string $endpoint  The full API endpoint URL.
	 * @param string $spi_token The SPI token to send as the body.
	 *
	 * @return array|\WP_Error The response array or WP_Error on failure.
	 */
	private function make_unauthenticated_request( string $endpoint, string $spi_token ): array|\WP_Error {
		$args = array(
			'method'      => 'POST',
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( $spi_token ),
			'sslverify'   => true,
		);

		return wp_remote_post( $endpoint, $args );
	}

	/**
	 * Build the HTTP headers for API requests.
	 *
	 * Combines authentication headers with content type and any
	 * additional headers that have been configured.
	 *
	 * @return array<string, string> Associative array of headers.
	 */
	private function build_headers(): array {
		// Base headers required for all requests.
		$headers = array(
			'Content-Type'                  => 'application/json',
			'Accept'                        => 'application/json',
			'PowerTranz-PowerTranzId'       => $this->merchant_id,
			'PowerTranz-PowerTranzPassword' => $this->api_password,
		);

		// Merge with any additional configured headers.
		if ( ! empty( $this->additional_headers ) ) {
			$headers = array_merge( $headers, $this->additional_headers );
		}

		return $headers;
	}

	/**
	 * Handle WordPress HTTP API errors.
	 *
	 * Converts WP_Error objects into normalized error responses.
	 * Common errors include timeouts, connection failures, and SSL issues.
	 *
	 * Security: Uses WooCommerce logger instead of error_log() to prevent
	 * exposure of API information in publicly accessible error logs.
	 *
	 * @param \WP_Error $error The WordPress error object.
	 *
	 * @return array Normalized error response.
	 */
	private function handle_wp_error( \WP_Error $error ): array {
		$error_code    = $error->get_error_code();
		$error_message = $error->get_error_message();

		// Map common WP_Error codes to user-friendly Spanish messages.
		$message = match ( $error_code ) {
			'http_request_failed'       => 'No se pudo conectar con la pasarela de pago. Intente de nuevo.',
			'http_request_not_executed' => 'No se pudo procesar la solicitud de pago. Intente de nuevo.',
			default                     => 'Error de conexion. Intente de nuevo.',
		};

		// Security: Use WooCommerce logger instead of error_log() for better access control.
		// WooCommerce logs are only accessible to administrators via WooCommerce > Status > Logs.
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->error(
				sprintf( 'API Connection Error: [%s] %s', $error_code, $error_message ),
				array( 'source' => 'woo_powertranz' )
			);
		}

		return $this->build_error_response( $error_code, $message );
	}

	/**
	 * Parse the authorization response from PowerTranz.
	 *
	 * Normalizes the API response into a consistent format regardless
	 * of whether the transaction was approved, declined, or errored.
	 *
	 * @param array $response The raw response from wp_remote_post.
	 *
	 * @return array Normalized response array.
	 */
	private function parse_authorization_response( array $response ): array {
		// Get HTTP status code.
		$http_code = wp_remote_retrieve_response_code( $response );

		// Get response body.
		$body = wp_remote_retrieve_body( $response );

		// Attempt to decode JSON response.
		$data = json_decode( $body, true );

		// Handle non-2xx HTTP responses.
		if ( $http_code < 200 || $http_code >= 300 ) {
			return $this->build_error_response(
				'HTTP_' . $http_code,
				$this->get_http_error_message( $http_code ),
				$data
			);
		}

		// Handle JSON decode failure.
		if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
			return $this->build_error_response(
				'INVALID_RESPONSE',
				'Invalid response received from payment gateway.'
			);
		}

		// Extract response code from API response.
		// PowerTranz typically returns IsoResponseCode or ResponseCode.
		$response_code = $data['IsoResponseCode'] ?? $data['ResponseCode'] ?? null;

		// Check if 3DS preprocessing is complete (requires iframe + /spi/Payment).
		if ( self::RESPONSE_CODE_3DS_REQUIRED === $response_code ) {
			return array(
				'success'            => false,
				'status'             => '3ds_required',
				'code'               => $response_code,
				'message'            => $data['ResponseMessage'] ?? '3D Secure authentication required.',
				'transaction_id'     => $data['TransactionIdentifier'] ?? null,
				'authorization_code' => null,
				'spi_token'          => $data['SpiToken'] ?? null,
				'redirect_data'      => $data['RedirectData'] ?? null,
				'raw_response'       => $data,
			);
		}

		// Check if transaction was approved.
		if ( self::RESPONSE_CODE_APPROVED === $response_code ) {
			return $this->build_success_response( $data );
		}

		// Transaction was declined.
		return $this->build_declined_response( $data, $response_code );
	}

	/**
	 * Build a successful response array.
	 *
	 * @param array $data The parsed API response data.
	 *
	 * @return array Normalized success response.
	 */
	private function build_success_response( array $data ): array {
		return array(
			'success'            => true,
			'status'             => 'approved',
			'code'               => $data['IsoResponseCode'] ?? $data['ResponseCode'] ?? null,
			'message'            => $data['ResponseMessage'] ?? 'Transaction approved.',
			'transaction_id'     => $data['TransactionIdentifier'] ?? null,
			'authorization_code' => $data['AuthorizationCode'] ?? null,
			'raw_response'       => $data,
		);
	}

	/**
	 * Build a declined response array.
	 *
	 * @param array       $data          The parsed API response data.
	 * @param string|null $response_code The decline response code.
	 *
	 * @return array Normalized declined response.
	 */
	private function build_declined_response( array $data, ?string $response_code ): array {
		return array(
			'success'            => false,
			'status'             => 'declined',
			'code'               => $response_code,
			'message'            => $data['ResponseMessage'] ?? $this->get_decline_message( $response_code ),
			'transaction_id'     => $data['TransactionIdentifier'] ?? null,
			'authorization_code' => null,
			'raw_response'       => $data,
		);
	}

	/**
	 * Build an error response array.
	 *
	 * @param string     $code         The error code.
	 * @param string     $message      The error message.
	 * @param array|null $raw_response Optional raw response data.
	 *
	 * @return array Normalized error response.
	 */
	private function build_error_response( string $code, string $message, ?array $raw_response = null ): array {
		return array(
			'success'            => false,
			'status'             => 'error',
			'code'               => $code,
			'message'            => $message,
			'transaction_id'     => null,
			'authorization_code' => null,
			'raw_response'       => $raw_response,
		);
	}

	/**
	 * Get a user-friendly message for HTTP error codes.
	 *
	 * @param int $http_code The HTTP status code.
	 *
	 * @return string User-friendly error message.
	 */
	private function get_http_error_message( int $http_code ): string {
		return match ( $http_code ) {
			400             => 'Solicitud invalida enviada a la pasarela de pago.',
			401             => 'Error de autenticacion con la pasarela de pago.',
			403             => 'Acceso denegado a la pasarela de pago.',
			404             => 'Endpoint de la pasarela de pago no encontrado.',
			408             => 'La solicitud a la pasarela de pago expiro.',
			429             => 'Demasiadas solicitudes. Espere e intente de nuevo.',
			500, 502, 503, 504 => 'La pasarela de pago no esta disponible temporalmente. Intente de nuevo.',
			default         => 'Ocurrio un error inesperado con la pasarela de pago.',
		};
	}

	/**
	 * Get a user-friendly message for decline codes.
	 *
	 * Maps common ISO 8583 response codes to customer-friendly messages.
	 * This list can be expanded based on PowerTranz documentation.
	 *
	 * @param string|null $code The decline response code.
	 *
	 * @return string User-friendly decline message.
	 */
	private function get_decline_message( ?string $code ): string {
		if ( null === $code ) {
			return 'Su pago fue rechazado. Intente con otra tarjeta.';
		}

		// Common ISO 8583 response codes mapped to user-friendly Spanish messages.
		return match ( $code ) {
			'01', '02' => 'Su banco no autorizo la transaccion. Comuniquese con su banco.',
			'03'       => 'Hubo un problema con la configuracion. Contacte soporte.',
			'04'       => 'Su tarjeta tiene restricciones. Use otra tarjeta.',
			'05'       => 'Su banco rechazo la transaccion. Comuniquese con su banco.',
			'12'       => 'La transaccion no pudo ser procesada. Intente de nuevo.',
			'13'       => 'Monto invalido.',
			'14'       => 'Numero de tarjeta invalido. Verifique e intente de nuevo.',
			'41', '43' => 'Tarjeta reportada. Comuniquese con su banco.',
			'51'       => 'Fondos insuficientes. Use otra tarjeta.',
			'54'       => 'Su tarjeta esta vencida. Use otra tarjeta.',
			'55'       => 'PIN incorrecto. Intente de nuevo.',
			'57'       => 'Transaccion no permitida para esta tarjeta.',
			'61'       => 'El monto excede el limite de su tarjeta.',
			'62'       => 'Su tarjeta tiene restricciones. Use otra tarjeta.',
			'63'       => 'Violacion de seguridad. Comuniquese con su banco.',
			'65'       => 'Limite de actividad excedido. Intente mas tarde.',
			'75'       => 'Intentos de PIN excedidos. Comuniquese con su banco.',
			'78'       => 'Tarjeta no activada. Comuniquese con su banco.',
			'91'       => 'El banco emisor no esta disponible. Intente mas tarde.',
			'96'       => 'Error del sistema. Intente de nuevo.',
			default    => 'Su pago fue rechazado. Intente con otra tarjeta.',
		};
	}
}
