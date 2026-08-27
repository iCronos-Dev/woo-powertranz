<?php
/**
 * Rate Limiter for Woo PowerTranz Payment Gateway
 *
 * Prevents card testing attacks by limiting payment attempts per IP address.
 *
 * @package Woo_PowerTranz
 * @since 1.1.0
 */

// Prevent direct file access for security.
defined( 'ABSPATH' ) || exit;

/**
 * Woo_PowerTranz_Rate_Limiter Class
 *
 * Implements rate limiting using WordPress transients to prevent
 * brute force card testing attacks.
 *
 * @since 1.1.0
 */
class Woo_PowerTranz_Rate_Limiter {

	/**
	 * Maximum number of payment attempts allowed within the time window.
	 *
	 * @var int
	 */
	protected int $max_attempts;

	/**
	 * Time window in seconds for rate limiting.
	 *
	 * @var int
	 */
	protected int $time_window;

	/**
	 * Prefix for transient keys.
	 *
	 * @var string
	 */
	protected string $transient_prefix = 'ept_rate_limit_';

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Maximum attempts allowed (default: 5).
	 * @param int $time_window  Time window in seconds (default: 900 = 15 minutes).
	 */
	public function __construct( int $max_attempts = 5, int $time_window = 900 ) {
		$this->max_attempts = $max_attempts;
		$this->time_window  = $time_window;
	}

	/**
	 * Check if the current IP is rate limited.
	 *
	 * @return bool True if rate limited (should block), false if allowed.
	 */
	public function is_rate_limited(): bool {
		$ip_address = $this->get_client_ip();

		if ( empty( $ip_address ) ) {
			// If we can't determine IP, allow the request but log it.
			return false;
		}

		$transient_key = $this->get_transient_key( $ip_address );
		$attempts      = get_transient( $transient_key );

		if ( false === $attempts ) {
			return false;
		}

		return (int) $attempts >= $this->max_attempts;
	}

	/**
	 * Record a payment attempt for the current IP.
	 *
	 * @return int The current attempt count after recording.
	 */
	public function record_attempt(): int {
		$ip_address = $this->get_client_ip();

		if ( empty( $ip_address ) ) {
			return 0;
		}

		$transient_key = $this->get_transient_key( $ip_address );
		$attempts      = get_transient( $transient_key );

		if ( false === $attempts ) {
			$attempts = 0;
		}

		$attempts = (int) $attempts + 1;

		set_transient( $transient_key, $attempts, $this->time_window );

		return $attempts;
	}

	/**
	 * Get the remaining attempts for the current IP.
	 *
	 * @return int Number of remaining attempts.
	 */
	public function get_remaining_attempts(): int {
		$ip_address = $this->get_client_ip();

		if ( empty( $ip_address ) ) {
			return $this->max_attempts;
		}

		$transient_key = $this->get_transient_key( $ip_address );
		$attempts      = get_transient( $transient_key );

		if ( false === $attempts ) {
			return $this->max_attempts;
		}

		$remaining = $this->max_attempts - (int) $attempts;

		return max( 0, $remaining );
	}

	/**
	 * Reset the rate limit for the current IP.
	 *
	 * Useful after a successful payment to reset the counter.
	 *
	 * @return bool True if reset successful, false otherwise.
	 */
	public function reset(): bool {
		$ip_address = $this->get_client_ip();

		if ( empty( $ip_address ) ) {
			return false;
		}

		$transient_key = $this->get_transient_key( $ip_address );

		return delete_transient( $transient_key );
	}

	/**
	 * Get the time remaining until the rate limit resets.
	 *
	 * @return int Seconds until reset, or 0 if not rate limited.
	 */
	public function get_time_until_reset(): int {
		$ip_address = $this->get_client_ip();

		if ( empty( $ip_address ) ) {
			return 0;
		}

		$transient_key = $this->get_transient_key( $ip_address );
		$timeout_key   = '_transient_timeout_' . $transient_key;
		$timeout       = get_option( $timeout_key );

		if ( false === $timeout ) {
			return 0;
		}

		$remaining = (int) $timeout - time();

		return max( 0, $remaining );
	}

	/**
	 * Get the client's IP address.
	 *
	 * Handles reverse proxies and load balancers by checking common headers.
	 *
	 * @return string The client IP address, or empty string if unable to determine.
	 */
	protected function get_client_ip(): string {
		$ip_address = '';

		// Check for CloudFlare.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip_address = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}
		// Check for reverse proxy.
		elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// X-Forwarded-For can contain multiple IPs, get the first one (client IP).
			$forwarded_ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip_address    = trim( $forwarded_ips[0] );
		}
		// Check for real IP header.
		elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip_address = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}
		// Fall back to REMOTE_ADDR.
		elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// Validate IP address.
		if ( ! empty( $ip_address ) && filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
			return $ip_address;
		}

		return '';
	}

	/**
	 * Generate the transient key for a given IP address.
	 *
	 * Uses MD5 hash of IP to keep key length manageable.
	 *
	 * @param string $ip_address The IP address.
	 *
	 * @return string The transient key.
	 */
	protected function get_transient_key( string $ip_address ): string {
		// Use MD5 hash to keep key length under WordPress transient key limit.
		return $this->transient_prefix . md5( $ip_address );
	}

	/**
	 * Get the maximum allowed attempts.
	 *
	 * @return int Maximum attempts.
	 */
	public function get_max_attempts(): int {
		return $this->max_attempts;
	}

	/**
	 * Get the time window in seconds.
	 *
	 * @return int Time window in seconds.
	 */
	public function get_time_window(): int {
		return $this->time_window;
	}
}
