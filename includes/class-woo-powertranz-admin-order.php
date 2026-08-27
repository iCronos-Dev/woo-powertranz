<?php
/**
 * Woo PowerTranz Admin Order Handler
 *
 * Adds Woo PowerTranz transaction information to the WooCommerce admin order screen.
 * Displays transaction details without exposing sensitive card data.
 *
 * @package Woo_PowerTranz
 * @since 1.0.0
 */

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Woo PowerTranz Admin Order Class
 *
 * Handles the display of Woo PowerTranz payment information in the
 * WooCommerce admin order details screen.
 *
 * @since 1.0.0
 */
class Woo_PowerTranz_Admin_Order {

	/**
	 * Initialize the admin order hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 10, 2 );

		// Orders list column (legacy).
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_list_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_list_column' ), 10, 2 );

		// HPOS compatibility.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_list_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_list_column_hpos' ), 10, 2 );
	}

	/**
	 * Add the Woo PowerTranz meta box to the order edit screen.
	 *
	 * Security: This method verifies user capability before accessing order data.
	 *
	 * @param string           $post_type     The post type.
	 * @param \WP_Post|WC_Order $post_or_order The post or order object.
	 *
	 * @return void
	 */
	public static function add_order_meta_box( string $post_type, $post_or_order ): void {
		// Security: Verify user has capability to edit orders before proceeding.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = null;

		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof \WP_Post && 'shop_order' === $post_type ) {
			$order = wc_get_order( $post_or_order->ID );
		} elseif ( 'woocommerce_page_wc-orders' === $post_type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				// Security: Verify user can edit this specific order.
				if ( $order && ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
					return;
				}
			}
		}

		if ( ! $order || 'woo_powertranz' !== $order->get_payment_method() ) {
			return;
		}

		$screen = $post_type;
		if ( 'woocommerce_page_wc-orders' === $post_type ) {
			$screen = wc_get_page_screen_id( 'shop-order' );
		}

		add_meta_box(
			'woo-powertranz-payment-info',
			__( 'Woo PowerTranz Payment', 'woo-powertranz' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render the Woo PowerTranz meta box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order The post or order object.
	 *
	 * @return void
	 */
	public static function render_meta_box( $post_or_order ): void {
		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} else {
			$order = wc_get_order( $post_or_order->ID );
		}

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'woo-powertranz' ) . '</p>';
			return;
		}

		$transaction_id     = $order->get_meta( '_woo_powertranz_transaction_id' );
		$authorization_code = $order->get_meta( '_woo_powertranz_authorization_code' );
		$response_code      = $order->get_meta( '_woo_powertranz_response_code' );
		$test_mode          = $order->get_meta( '_woo_powertranz_test_mode' );
		$decline_code       = $order->get_meta( '_woo_powertranz_decline_code' );
		$decline_message    = $order->get_meta( '_woo_powertranz_decline_message' );
		$error_code         = $order->get_meta( '_woo_powertranz_error_code' );
		$error_message      = $order->get_meta( '_woo_powertranz_error_message' );
		$masked_pan         = $order->get_meta( '_woo_powertranz_masked_pan' );
		$card_brand         = $order->get_meta( '_woo_powertranz_card_brand' );
		$rrn                = $order->get_meta( '_woo_powertranz_rrn' );

		$status_info = self::get_authorization_status( $order, $response_code, $decline_code, $error_code );

		?>
		<div class="woo-powertranz-payment-info">
			<?php if ( 'yes' === $test_mode ) : ?>
				<p class="woo-powertranz-test-mode-badge" style="background: #ffba00; color: #000; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; text-align: center; margin-bottom: 12px;">
					<?php esc_html_e( 'TEST MODE TRANSACTION', 'woo-powertranz' ); ?>
				</p>
			<?php endif; ?>

			<p class="woo-powertranz-status">
				<strong><?php esc_html_e( 'Status:', 'woo-powertranz' ); ?></strong><br>
				<span class="status-badge" style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; background: <?php echo esc_attr( $status_info['color'] ); ?>; color: #fff;">
					<?php echo esc_html( $status_info['label'] ); ?>
				</span>
			</p>

			<?php if ( $transaction_id ) : ?>
				<p class="woo-powertranz-transaction-id">
					<strong><?php esc_html_e( 'Transaction ID:', 'woo-powertranz' ); ?></strong><br>
					<code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( $transaction_id ); ?></code>
				</p>
			<?php endif; ?>

			<?php if ( $authorization_code ) : ?>
				<p class="woo-powertranz-auth-code">
					<strong><?php esc_html_e( 'Authorization Code:', 'woo-powertranz' ); ?></strong><br>
					<code><?php echo esc_html( $authorization_code ); ?></code>
				</p>
			<?php endif; ?>

			<?php if ( $response_code ) : ?>
				<p class="woo-powertranz-response-code">
					<strong><?php esc_html_e( 'Response Code:', 'woo-powertranz' ); ?></strong><br>
					<?php echo esc_html( $response_code ); ?>
					<?php if ( '00' === $response_code ) : ?>
						<span style="color: #46b450;">✓</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $masked_pan || $card_brand ) : ?>
				<p class="woo-powertranz-card-info">
					<strong><?php esc_html_e( 'Card:', 'woo-powertranz' ); ?></strong><br>
					<?php if ( $card_brand ) : ?>
						<?php echo esc_html( $card_brand ); ?>
					<?php endif; ?>
					<?php if ( $masked_pan ) : ?>
						<code style="font-size: 11px;"><?php echo esc_html( $masked_pan ); ?></code>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $rrn ) : ?>
				<p class="woo-powertranz-rrn">
					<strong><?php esc_html_e( 'RRN:', 'woo-powertranz' ); ?></strong><br>
					<code><?php echo esc_html( $rrn ); ?></code>
				</p>
			<?php endif; ?>

			<?php if ( $decline_code || $decline_message ) : ?>
				<hr style="margin: 12px 0; border: none; border-top: 1px solid #ddd;">
				<p class="woo-powertranz-decline-info" style="color: #d63638;">
					<strong><?php esc_html_e( 'Decline Details:', 'woo-powertranz' ); ?></strong><br>
					<?php if ( $decline_code ) : ?>
						<?php
						printf(
							/* translators: %s: decline code */
							esc_html__( 'Code: %s', 'woo-powertranz' ),
							esc_html( $decline_code )
						);
						?>
						<br>
					<?php endif; ?>
					<?php if ( $decline_message ) : ?>
						<em><?php echo esc_html( $decline_message ); ?></em>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $error_code || $error_message ) : ?>
				<hr style="margin: 12px 0; border: none; border-top: 1px solid #ddd;">
				<p class="woo-powertranz-error-info" style="color: #d63638;">
					<strong><?php esc_html_e( 'Error Details:', 'woo-powertranz' ); ?></strong><br>
					<?php if ( $error_code ) : ?>
						<?php
						printf(
							/* translators: %s: error code */
							esc_html__( 'Code: %s', 'woo-powertranz' ),
							esc_html( $error_code )
						);
						?>
						<br>
					<?php endif; ?>
					<?php if ( $error_message ) : ?>
						<em><?php echo esc_html( $error_message ); ?></em>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! $transaction_id && ! $decline_code && ! $error_code ) : ?>
				<p style="color: #666; font-style: italic;">
					<?php esc_html_e( 'No transaction data available.', 'woo-powertranz' ); ?>
				</p>
			<?php endif; ?>

			<?php do_action( 'woo_powertranz_admin_order_meta_box', $order ); ?>
		</div>
		<?php
	}

	/**
	 * Get authorization status information.
	 *
	 * @param \WC_Order   $order         The order object.
	 * @param string|null $response_code The response code.
	 * @param string|null $decline_code  The decline code.
	 * @param string|null $error_code    The error code.
	 *
	 * @return array Status info array.
	 */
	protected static function get_authorization_status(
		\WC_Order $order,
		?string $response_code,
		?string $decline_code,
		?string $error_code
	): array {
		if ( '00' === $response_code ) {
			$order_status = $order->get_status();

			if ( in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
				return array(
					'label' => __( 'Captured', 'woo-powertranz' ),
					'color' => '#46b450',
				);
			}

			return array(
				'label' => __( 'Authorized', 'woo-powertranz' ),
				'color' => '#00a0d2',
			);
		}

		if ( $decline_code ) {
			return array(
				'label' => __( 'Declined', 'woo-powertranz' ),
				'color' => '#d63638',
			);
		}

		if ( $error_code ) {
			return array(
				'label' => __( 'Error', 'woo-powertranz' ),
				'color' => '#dba617',
			);
		}

		$order_status = $order->get_status();
		if ( 'cancelled' === $order_status ) {
			return array(
				'label' => __( 'Voided', 'woo-powertranz' ),
				'color' => '#787c82',
			);
		}

		if ( 'refunded' === $order_status ) {
			return array(
				'label' => __( 'Refunded', 'woo-powertranz' ),
				'color' => '#787c82',
			);
		}

		return array(
			'label' => __( 'Pending', 'woo-powertranz' ),
			'color' => '#787c82',
		);
	}

	/**
	 * Add custom column to orders list table.
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array Modified columns array.
	 */
	public static function add_order_list_column( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'order_status' === $key ) {
				$new_columns['woo_powertranz_status'] = __( 'Payment', 'woo-powertranz' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render the custom column content (legacy).
	 *
	 * @param string $column  The column identifier.
	 * @param int    $post_id The post/order ID.
	 *
	 * @return void
	 */
	public static function render_order_list_column( string $column, int $post_id ): void {
		if ( 'woo_powertranz_status' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		self::output_payment_status_badge( $order );
	}

	/**
	 * Render the custom column content (HPOS).
	 *
	 * @param string    $column The column identifier.
	 * @param \WC_Order $order  The order object.
	 *
	 * @return void
	 */
	public static function render_order_list_column_hpos( string $column, \WC_Order $order ): void {
		if ( 'woo_powertranz_status' !== $column ) {
			return;
		}

		self::output_payment_status_badge( $order );
	}

	/**
	 * Output the payment status badge for order list.
	 *
	 * @param \WC_Order|false $order The order object or false.
	 *
	 * @return void
	 */
	protected static function output_payment_status_badge( $order ): void {
		if ( ! $order ) {
			echo '—';
			return;
		}

		if ( 'woo_powertranz' !== $order->get_payment_method() ) {
			echo '—';
			return;
		}

		$response_code = $order->get_meta( '_woo_powertranz_response_code' );
		$decline_code  = $order->get_meta( '_woo_powertranz_decline_code' );
		$error_code    = $order->get_meta( '_woo_powertranz_error_code' );
		$test_mode     = $order->get_meta( '_woo_powertranz_test_mode' );

		$status_info = self::get_authorization_status( $order, $response_code, $decline_code, $error_code );

		printf(
			'<span class="woo-powertranz-list-badge" style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; background: %s; color: #fff;">%s</span>',
			esc_attr( $status_info['color'] ),
			esc_html( $status_info['label'] )
		);

		if ( 'yes' === $test_mode ) {
			echo ' <span style="font-size: 10px; color: #dba617;" title="' . esc_attr__( 'Test Mode', 'woo-powertranz' ) . '">⚠</span>';
		}
	}
}
