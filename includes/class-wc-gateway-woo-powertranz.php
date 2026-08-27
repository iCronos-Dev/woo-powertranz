<?php
/**
 * Woo PowerTranz Payment Gateway Class
 *
 * Extends WooCommerce's base payment gateway class to provide
 * integration with the PowerTranz payment processor.
 *
 * @package Woo_PowerTranz
 * @since 1.0.0
 */

// Prevent direct file access for security.
defined( 'ABSPATH' ) || exit;

/**
 * WC_Gateway_Woo_PowerTranz Class
 *
 * This class extends WC_Payment_Gateway to create a custom payment gateway.
 * WC_Payment_Gateway is an abstract class that provides the foundation for
 * all WooCommerce payment methods.
 *
 * @extends WC_Payment_Gateway
 * @see https://woocommerce.com/document/payment-gateway-api/
 */
class WC_Gateway_Woo_PowerTranz extends WC_Payment_Gateway {

	/**
	 * Merchant ID for PowerTranz API authentication.
	 *
	 * @var string
	 */
	protected string $merchant_id;

	/**
	 * API Password for PowerTranz API authentication.
	 *
	 * @var string
	 */
	protected string $api_password;

	/**
	 * Whether test mode is enabled.
	 *
	 * @var bool
	 */
	protected bool $test_mode;

	/**
	 * Rate limiter instance for preventing card testing attacks.
	 *
	 * @var Woo_PowerTranz_Rate_Limiter
	 */
	protected Woo_PowerTranz_Rate_Limiter $rate_limiter;

	/**
	 * Constructor for the gateway.
	 *
	 * Sets up the gateway with required properties and hooks.
	 */
	public function __construct() {
		// Unique ID for this gateway.
		$this->id = 'woo_powertranz';

		// URL of the icon to display on checkout page.
		$this->icon = '';

		// Set to true because we collect card details on the checkout page.
		$this->has_fields = true;

		// Title shown in WooCommerce → Settings → Payments list.
		$this->method_title = __( 'Woo PowerTranz', 'woo-powertranz' );

		// Description shown under the title in WooCommerce → Settings → Payments.
		$this->method_description = __(
			'Accept credit and debit card payments through PowerTranz payment gateway.',
			'woo-powertranz'
		);

		// Define features this gateway supports.
		$this->supports = array(
			'products',
			'refunds',
		);

		// Initialize the form fields for the admin settings page.
		$this->init_form_fields();

		// Load the settings from the database.
		$this->init_settings();

		// Assign settings to class properties for easy access.
		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );
		$this->merchant_id  = $this->get_option( 'merchant_id' );
		$this->api_password = $this->get_option( 'api_password' );
		$this->test_mode    = 'yes' === $this->get_option( 'test_mode' );

		// Initialize rate limiter with configurable limits via filters.
		$max_attempts = apply_filters( 'woo_powertranz_rate_limit_max_attempts', 5 );
		$time_window  = apply_filters( 'woo_powertranz_rate_limit_time_window', 900 ); // 15 minutes.
		$this->rate_limiter = new Woo_PowerTranz_Rate_Limiter( $max_attempts, $time_window );

		// Hook to save admin options when the settings form is submitted.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		// Register capture action in order actions dropdown.
		add_filter( 'woocommerce_order_actions', array( $this, 'add_capture_order_action' ) );
		add_action( 'woocommerce_order_action_woo_powertranz_capture', array( $this, 'process_capture_action' ) );

		// Enqueue admin styles for the settings page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

		// Enqueue frontend checkout scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

		// Register 3DS callback endpoint via WooCommerce API.
		add_action( 'woocommerce_api_woo_powertranz_3ds', array( $this, 'handle_3ds_callback' ) );

		// Register 3DS iframe page endpoint.
		add_action( 'woocommerce_api_woo_powertranz_3ds_form', array( $this, 'render_3ds_iframe_page' ) );

		// Display error notices from 3DS redirect on checkout page.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'display_3ds_error_notice' ) );

		// Make payment_complete() transition directly to 'completed' for this gateway.
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'complete_order_status' ), 10, 3 );
	}

	/**
	 * Set the order status to 'completed' after payment_complete() for this gateway.
	 *
	 * This avoids calling update_status('completed') separately, which would cause
	 * a double status transition (pending→processing→completed) and fire heavy
	 * plugin hooks twice — breaking the 3DS iframe redirect.
	 *
	 * @param string    $status   The default order status after payment.
	 * @param int       $order_id The order ID.
	 * @param \WC_Order $order    The order object.
	 *
	 * @return string The filtered order status.
	 */
	public function complete_order_status( string $status, int $order_id, \WC_Order $order ): string {
		if ( $order->get_payment_method() === $this->id ) {
			return 'completed';
		}

		return $status;
	}

	/**
	 * Display error notice from 3DS redirect on the checkout page.
	 *
	 * After a 3DS decline inside the iframe, WC session notices don't survive
	 * the redirect on Pantheon multi-container environments. Instead, the error
	 * type is passed via the ept_error query parameter.
	 *
	 * @return void
	 */
	public function display_3ds_error_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of error type param.
		if ( ! isset( $_GET['ept_error'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error_type = sanitize_text_field( wp_unslash( $_GET['ept_error'] ) );

		if ( 'declined' === $error_type ) {
			$message = __( 'Su pago fue rechazado. Por favor intente con otra tarjeta o comuniquese con su banco.', 'woo-powertranz' );
		} elseif ( 'error' === $error_type ) {
			$message = __( 'Ocurrio un error al procesar su pago. Por favor intente de nuevo.', 'woo-powertranz' );
		} else {
			return;
		}

		wc_print_notice( $message, 'error' );
	}

	/**
	 * Initialize gateway settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(

			'enabled'      => array(
				'title'       => __( 'Enable/Disable', 'woo-powertranz' ),
				'label'       => __( 'Enable Woo PowerTranz Payment Gateway', 'woo-powertranz' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable this to accept payments through PowerTranz.', 'woo-powertranz' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),

			'title'        => array(
				'title'       => __( 'Title', 'woo-powertranz' ),
				'type'        => 'text',
				'description' => __( 'Payment method title that customers see during checkout.', 'woo-powertranz' ),
				'default'     => __( 'Credit/Debit Card', 'woo-powertranz' ),
				'desc_tip'    => true,
			),

			'description'  => array(
				'title'       => __( 'Description', 'woo-powertranz' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that customers see during checkout.', 'woo-powertranz' ),
				'default'     => __( 'Pay securely using your credit or debit card.', 'woo-powertranz' ),
				'desc_tip'    => true,
			),

			'api_settings' => array(
				'title'       => __( 'API Credentials', 'woo-powertranz' ),
				'type'        => 'title',
				'description' => __(
					'Enter your PowerTranz API credentials. You can obtain these from your PowerTranz merchant account.',
					'woo-powertranz'
				),
			),

			'merchant_id'  => array(
				'title'             => __( 'Merchant ID', 'woo-powertranz' ),
				'type'              => 'text',
				'description'       => __( 'Your PowerTranz Merchant ID.', 'woo-powertranz' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'autocomplete' => 'off',
				),
			),

			'api_password' => array(
				'title'             => __( 'API Password', 'woo-powertranz' ),
				'type'              => 'password',
				'description'       => __( 'Your PowerTranz API Password.', 'woo-powertranz' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
			),

			'test_mode'    => array(
				'title'       => __( 'Test Mode', 'woo-powertranz' ),
				'label'       => __( 'Enable Test Mode', 'woo-powertranz' ),
				'type'        => 'checkbox',
				'description' => __(
					'Enable test mode to use the PowerTranz sandbox environment for testing.',
					'woo-powertranz'
				),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

		);
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool True if the gateway is available, false otherwise.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( empty( $this->merchant_id ) || empty( $this->api_password ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Render payment fields on the checkout page.
	 *
	 * @return void
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			if ( $this->test_mode ) {
				$this->description .= ' <strong>' . esc_html__( '(TEST MODE - No real charges will be made)', 'woo-powertranz' ) . '</strong>';
			}
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">

			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>

			<p class="form-row form-row-wide">
				<label for="<?php echo esc_attr( $this->id ); ?>-card-name">
					<?php esc_html_e( 'Cardholder Name', 'woo-powertranz' ); ?>
					<span class="required" aria-hidden="true">*</span>
				</label>
				<input
					id="<?php echo esc_attr( $this->id ); ?>-card-name"
					name="woo_powertranz_card_name"
					class="input-text woo-powertranz-card-name"
					type="text"
					autocomplete="cc-name"
					placeholder="<?php esc_attr_e( 'Name as it appears on card', 'woo-powertranz' ); ?>"
					aria-required="true"
				/>
			</p>

			<p class="form-row form-row-wide">
				<label for="<?php echo esc_attr( $this->id ); ?>-card-number">
					<?php esc_html_e( 'Card Number', 'woo-powertranz' ); ?>
					<span class="required" aria-hidden="true">*</span>
				</label>
				<input
					id="<?php echo esc_attr( $this->id ); ?>-card-number"
					name="woo_powertranz_card_number"
					class="input-text woo-powertranz-card-number"
					type="text"
					inputmode="numeric"
					autocomplete="cc-number"
					autocorrect="off"
					autocapitalize="off"
					spellcheck="false"
					maxlength="19"
					placeholder="•••• •••• •••• ••••"
					aria-required="true"
				/>
			</p>

			<p class="form-row form-row-first">
				<label for="<?php echo esc_attr( $this->id ); ?>-card-expiry">
					<?php esc_html_e( 'Expiration (MM/YY)', 'woo-powertranz' ); ?>
					<span class="required" aria-hidden="true">*</span>
				</label>
				<input
					id="<?php echo esc_attr( $this->id ); ?>-card-expiry"
					name="woo_powertranz_card_expiry"
					class="input-text woo-powertranz-card-expiry"
					type="text"
					inputmode="numeric"
					autocomplete="cc-exp"
					autocorrect="off"
					autocapitalize="off"
					spellcheck="false"
					maxlength="7"
					placeholder="MM / YY"
					aria-required="true"
				/>
			</p>

			<p class="form-row form-row-last">
				<label for="<?php echo esc_attr( $this->id ); ?>-card-cvc">
					<?php esc_html_e( 'Security Code (CVV)', 'woo-powertranz' ); ?>
					<span class="required" aria-hidden="true">*</span>
				</label>
				<input
					id="<?php echo esc_attr( $this->id ); ?>-card-cvc"
					name="woo_powertranz_card_cvc"
					class="input-text woo-powertranz-card-cvc"
					type="text"
					inputmode="numeric"
					autocomplete="cc-csc"
					autocorrect="off"
					autocapitalize="off"
					spellcheck="false"
					maxlength="4"
					placeholder="CVV"
					aria-required="true"
				/>
			</p>

			<div class="clear"></div>

			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>

		</fieldset>
		<?php
	}

	/**
	 * Enqueue checkout scripts for the payment form.
	 *
	 * WooCommerce calls this automatically on checkout and add-payment-method pages.
	 *
	 * @return void
	 */
	public function payment_scripts(): void {
		if ( ! is_checkout() && ! is_add_payment_method_page() ) {
			return;
		}

		wp_enqueue_script(
			'woo-powertranz-checkout',
			plugins_url( 'assets/js/checkout.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			WOO_POWERTRANZ_VERSION,
			true
		);
	}

	/**
	 * Validate payment fields on checkout submission.
	 *
	 * Security Note: Nonce verification is handled by WooCommerce's checkout process.
	 * This method is only called via WC_Checkout::process_checkout() which verifies
	 * the 'woocommerce-process-checkout-nonce' before calling payment gateway methods.
	 *
	 * @see WC_Checkout::process_checkout() - Verifies nonce before processing
	 * @see WC_Checkout::validate_checkout() - Calls gateway validate_fields()
	 *
	 * @return bool True if validation passes, false otherwise.
	 */
	public function validate_fields(): bool {
		// Solo validar si este gateway es el metodo de pago seleccionado.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verificado por WooCommerce en WC_Checkout::process_checkout().
		if ( ! isset( $_POST['payment_method'] ) || $this->id !== sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) ) {
			return true;
		}

		$is_valid = true;

		// Security: Nonce is verified by WooCommerce in WC_Checkout::process_checkout().
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
		$card_name   = isset( $_POST['woo_powertranz_card_name'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_name'] ) ) : '';
		$card_number = isset( $_POST['woo_powertranz_card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_number'] ) ) : '';
		$card_expiry = isset( $_POST['woo_powertranz_card_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_expiry'] ) ) : '';
		$card_cvc    = isset( $_POST['woo_powertranz_card_cvc'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_cvc'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Validate Cardholder Name.
		if ( empty( $card_name ) ) {
			wc_add_notice( __( 'Por favor ingrese el nombre del titular de la tarjeta.', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		} elseif ( strlen( $card_name ) < 2 ) {
			wc_add_notice( __( 'El nombre del titular es muy corto.', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		}

		// Validate Card Number.
		$card_number_clean = preg_replace( '/[\s\-]/', '', $card_number );

		if ( empty( $card_number_clean ) ) {
			wc_add_notice( __( 'Por favor ingrese el numero de su tarjeta.', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		} elseif ( ! preg_match( '/^\d{13,19}$/', $card_number_clean ) ) {
			wc_add_notice( __( 'Numero de tarjeta invalido. Verifique e intente de nuevo.', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		}

		// Validate Expiration Date.
		$expiry_clean = preg_replace( '/\s/', '', $card_expiry );

		if ( empty( $expiry_clean ) ) {
			wc_add_notice( __( 'Por favor ingrese la fecha de vencimiento de su tarjeta.', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		} elseif ( ! preg_match( '/^(0[1-9]|1[0-2])\/?(\d{2})$/', $expiry_clean, $matches ) ) {
			wc_add_notice( __( 'Fecha de vencimiento invalida. Use el formato MM/AA.', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		} else {
			$exp_month = (int) $matches[1];
			$exp_year  = (int) ( '20' . $matches[2] );
			$now       = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			$exp_date  = \DateTime::createFromFormat(
				'Y-m-d',
				sprintf( '%04d-%02d-01', $exp_year, $exp_month ),
				new \DateTimeZone( 'UTC' )
			);

			$exp_date->modify( 'last day of this month' );

			if ( $exp_date < $now ) {
				wc_add_notice( __( 'Su tarjeta esta vencida. Use otra tarjeta.', 'woo-powertranz' ), 'error' );
				$is_valid = false;
			}
		}

		// Validate CVV/CVC.
		if ( empty( $card_cvc ) ) {
			wc_add_notice( __( 'Por favor ingrese el codigo de seguridad (CVV).', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		} elseif ( ! preg_match( '/^\d{3,4}$/', $card_cvc ) ) {
			wc_add_notice( __( 'Codigo de seguridad invalido (3 o 4 digitos).', 'woo-powertranz' ), 'error' );
			$is_valid = false;
		}

		return $is_valid;
	}

	/**
	 * Get sanitized card data from POST for payment processing.
	 *
	 * Security Note: This method is only called from process_payment() which is
	 * invoked by WooCommerce after nonce verification in WC_Checkout::process_checkout().
	 *
	 * @see WC_Checkout::process_checkout() - Verifies nonce before payment processing
	 *
	 * @return array Sanitized card data array.
	 */
	protected function get_card_data_from_post(): array {
		// Security: Nonce is verified by WooCommerce in WC_Checkout::process_checkout().
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
		$card_name   = isset( $_POST['woo_powertranz_card_name'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_name'] ) ) : '';
		$card_number = isset( $_POST['woo_powertranz_card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_number'] ) ) : '';
		$card_expiry = isset( $_POST['woo_powertranz_card_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_expiry'] ) ) : '';
		$card_cvc    = isset( $_POST['woo_powertranz_card_cvc'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_powertranz_card_cvc'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$card_number_clean = preg_replace( '/[\s\-]/', '', $card_number );

		$expiry_clean = preg_replace( '/\s/', '', $card_expiry );
		preg_match( '/^(0[1-9]|1[0-2])\/?(\d{2})$/', $expiry_clean, $matches );

		$exp_month = $matches[1] ?? '';
		$exp_year  = $matches[2] ?? '';

		return array(
			'card_name'         => $card_name,
			'card_number'       => $card_number_clean,
			'card_expiry_month' => $exp_month,
			'card_expiry_year'  => $exp_year,
			'card_cvc'          => $card_cvc,
		);
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id WooCommerce order ID being processed.
	 * @return array Result array with 'result' and optionally 'redirect'.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log( "Order #{$order_id} not found during payment processing.", 'error' );
			wc_add_notice( __( 'Pedido no encontrado. Por favor intente de nuevo.', 'woo-powertranz' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Security: Check rate limiting to prevent card testing attacks.
		if ( $this->rate_limiter->is_rate_limited() ) {
			$time_remaining = $this->rate_limiter->get_time_until_reset();
			$minutes        = ceil( $time_remaining / 60 );

			$this->log(
				sprintf( 'Order #%d: Payment blocked due to rate limiting. Time remaining: %d seconds.', $order_id, $time_remaining ),
				'warning'
			);

			wc_add_notice(
				sprintf(
					/* translators: %d: minutes until rate limit resets */
					__( 'Demasiados intentos de pago. Por favor espere %d minutos antes de intentar de nuevo.', 'woo-powertranz' ),
					$minutes
				),
				'error'
			);

			return array( 'result' => 'failure' );
		}

		// Record this payment attempt for rate limiting.
		$this->rate_limiter->record_attempt();

		$card_data = $this->get_card_data_from_post();

		if ( empty( $card_data['card_number'] ) ) {
			$this->log( "Order #{$order_id}: Card data missing from POST.", 'error' );
			wc_add_notice( __( 'Informacion de tarjeta no encontrada. Por favor intente de nuevo.', 'woo-powertranz' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Store masked PAN before sending to API (PAN is not returned in API response).
		$order->update_meta_data( '_woo_powertranz_masked_pan', $this->mask_card_number( $card_data['card_number'] ) );
		$order->save();

		$payload = $this->build_payment_payload( $order, $card_data );

		$api_client = new Woo_PowerTranz_API_Client(
			$this->get_api_endpoint(),
			$this->get_merchant_id(),
			$this->get_api_password(),
			30
		);

		$api_client->set_additional_headers( array(
			'X-Order-Reference' => (string) $order->get_id(),
		) );

		$this->log( "Order #{$order_id}: Sending Sale request to PowerTranz.", 'info' );

		$response = $api_client->sale_payment( $payload );

		// Handle 3DS required (SP4): store token, redirect to iframe page.
		if ( '3ds_required' === $response['status'] ) {
			return $this->handle_3ds_required( $order, $response );
		}

		if ( $response['success'] ) {
			return $this->handle_successful_payment( $order, $response );
		}

		if ( 'declined' === $response['status'] ) {
			return $this->handle_declined_payment( $order, $response );
		}

		return $this->handle_payment_error( $order, $response );
	}

	/**
	 * Manejar respuesta 3DS requerido (SP4).
	 *
	 * Almacena el token SPI y RedirectData, luego redirige al cliente
	 * a una pagina intermedia que renderiza el iframe 3DS.
	 *
	 * @param \WC_Order $order    El pedido de WooCommerce.
	 * @param array     $response La respuesta normalizada del API con spi_token y redirect_data.
	 *
	 * @return array Array de resultado de pago de WooCommerce.
	 */
	protected function handle_3ds_required( \WC_Order $order, array $response ): array {
		$order_id  = $order->get_id();
		$spi_token = $response['spi_token'] ?? '';

		if ( empty( $spi_token ) || empty( $response['redirect_data'] ) ) {
			$this->log( "Pedido #{$order_id}: Respuesta 3DS sin SpiToken o RedirectData.", 'error' );
			wc_add_notice( __( 'Ocurrio un error durante la verificacion 3D Secure. Por favor intente de nuevo.', 'woo-powertranz' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Almacenar token SPI y RedirectData en meta del pedido.
		// Se usa order meta en lugar de transients para evitar problemas de
		// consistencia en entornos con multiples app containers (ej. Pantheon).
		$order->update_meta_data( '_woo_powertranz_spi_token', $spi_token );
		$order->update_meta_data( '_woo_powertranz_transaction_id', $this->sanitize_api_response_value( $response['transaction_id'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_redirect_data', $response['redirect_data'] );
		$order->save();

		$this->log( "Pedido #{$order_id}: Autenticacion 3DS requerida. Redirigiendo a pagina del iframe.", 'info' );

		// Redirigir a la pagina del iframe 3DS.
		$redirect_url = add_query_arg(
			array(
				'order_id'  => $order_id,
				'order_key' => $order->get_order_key(),
			),
			WC()->api_request_url( 'woo_powertranz_3ds_form' )
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Sanitize a billing field for PowerTranz API.
	 *
	 * Transliterates accented characters to ASCII, removes disallowed characters,
	 * collapses whitespace, and truncates to the maximum allowed length.
	 *
	 * @param string $value      The field value to sanitize.
	 * @param int    $max_length Maximum allowed characters.
	 *
	 * @return string Sanitized value.
	 */
	protected function sanitize_billing_field( string $value, int $max_length ): string {
		$value = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
		$value = preg_replace( '/[^a-zA-Z0-9\s.\-]/', '', $value );
		$value = preg_replace( '/\s+/', ' ', trim( $value ) );
		return substr( $value, 0, $max_length );
	}

	/**
	 * Sanitize a phone number for PowerTranz API.
	 *
	 * Strips all non-digit characters.
	 *
	 * @param string $phone The phone number to sanitize.
	 *
	 * @return string Digits-only phone number.
	 */
	protected function sanitize_phone_number( string $phone ): string {
		return preg_replace( '/[^0-9]/', '', $phone );
	}

	/**
	 * Build the payment payload for PowerTranz API.
	 *
	 * @param \WC_Order $order     The WooCommerce order object.
	 * @param array     $card_data The sanitized card data from POST.
	 *
	 * @return array The complete API payload.
	 */
	protected function build_payment_payload( \WC_Order $order, array $card_data ): array {
		$transaction_id = wp_generate_uuid4();
		$total_amount   = (float) $order->get_total();
		$currency_code  = $this->get_currency_numeric_code( $order->get_currency() );
		$card_expiry    = $card_data['card_expiry_year'] . $card_data['card_expiry_month'];

		// Build billing address with defaults for fields removed from checkout.
		$billing_line1   = $order->get_billing_address_1();
		$billing_city    = $order->get_billing_city();
		$billing_state   = $order->get_billing_state();
		$billing_postal  = $order->get_billing_postcode();
		$billing_country = $order->get_billing_country();

		$payload = array(
			'TransactionIdentifier' => $transaction_id,
			'OrderIdentifier'       => (string) $order->get_order_number(),
			'TotalAmount'           => $total_amount,
			'CurrencyCode'          => $currency_code,
			'ThreeDSecure'          => true,
			'Source'                => array(
				'CardPan'        => $card_data['card_number'],
				'CardCvv'        => $card_data['card_cvc'],
				'CardExpiration' => $card_expiry,
				'CardholderName' => $this->sanitize_billing_field( $card_data['card_name'], 30 ),
			),
			'BillingAddress'        => array(
				'FirstName'    => $this->sanitize_billing_field( $order->get_billing_first_name(), 30 ),
				'LastName'     => $this->sanitize_billing_field( $order->get_billing_last_name(), 30 ),
				'Line1'        => $this->sanitize_billing_field( ! empty( $billing_line1 ) ? $billing_line1 : 'N/A', 30 ),
				'Line2'        => $this->sanitize_billing_field( $order->get_billing_address_2(), 50 ),
				'City'         => $this->sanitize_billing_field( ! empty( $billing_city ) ? $billing_city : 'N/A', 30 ),
				'State'        => '',
				'PostalCode'   => '',
				'CountryCode'  => ! empty( $billing_country ) ? $billing_country : '188',
				'EmailAddress' => trim( $order->get_billing_email() ),
				'PhoneNumber'  => $this->sanitize_phone_number( $order->get_billing_phone() ),
			),
			'AddressMatch'          => false,
			'ExtendedData'          => array(
				'MerchantResponseUrl' => WC()->api_request_url( 'woo_powertranz_3ds' ),
				'ThreeDSecure'        => array(
					'ChallengeWindowSize' => 5,
					'ChallengeIndicator'  => '01',
				),
				'Custom'              => array(
					'wc_order_id' => $order->get_id(),
				),
			),
		);

		if ( $order->has_shipping_address() ) {
			$payload['ShippingAddress'] = array(
				'FirstName'   => $this->sanitize_billing_field( $order->get_shipping_first_name(), 30 ),
				'LastName'    => $this->sanitize_billing_field( $order->get_shipping_last_name(), 30 ),
				'Line1'       => $this->sanitize_billing_field( $order->get_shipping_address_1(), 30 ),
				'Line2'       => $this->sanitize_billing_field( $order->get_shipping_address_2(), 50 ),
				'City'        => $this->sanitize_billing_field( $order->get_shipping_city(), 30 ),
				'State'       => '',
				'PostalCode'  => '',
				'CountryCode' => $order->get_shipping_country(),
			);
		}

		// Store original critical values before filter.
		$original_values = array(
			'TotalAmount'           => $payload['TotalAmount'],
			'CurrencyCode'          => $payload['CurrencyCode'],
			'OrderIdentifier'       => $payload['OrderIdentifier'],
			'TransactionIdentifier' => $payload['TransactionIdentifier'],
			'Source'                => $payload['Source'],
			'MerchantResponseUrl'   => $payload['ExtendedData']['MerchantResponseUrl'] ?? null,
		);

		/**
		 * Filter the payment payload before sending to PowerTranz.
		 *
		 * Security Warning: The following fields are protected and will be restored
		 * if modified by the filter:
		 * - TotalAmount, CurrencyCode, OrderIdentifier, TransactionIdentifier
		 * - Source (card data), ExtendedData.MerchantResponseUrl
		 *
		 * @since 1.0.0
		 *
		 * @param array     $payload The payment payload.
		 * @param \WC_Order $order   The WooCommerce order.
		 */
		$payload = apply_filters( 'woo_powertranz_payment_payload', $payload, $order );

		// Security: Validate critical fields weren't modified by the filter.
		// This prevents malicious plugins from altering payment amounts, card data, or redirect URLs.
		$tampered_fields = array();

		if ( $payload['TotalAmount'] !== $original_values['TotalAmount'] ) {
			$tampered_fields[] = 'TotalAmount';
		}
		if ( $payload['CurrencyCode'] !== $original_values['CurrencyCode'] ) {
			$tampered_fields[] = 'CurrencyCode';
		}
		if ( $payload['OrderIdentifier'] !== $original_values['OrderIdentifier'] ) {
			$tampered_fields[] = 'OrderIdentifier';
		}
		if ( $payload['TransactionIdentifier'] !== $original_values['TransactionIdentifier'] ) {
			$tampered_fields[] = 'TransactionIdentifier';
		}
		if ( $payload['Source'] !== $original_values['Source'] ) {
			$tampered_fields[] = 'Source';
		}
		if ( isset( $payload['ExtendedData']['MerchantResponseUrl'] ) &&
			$payload['ExtendedData']['MerchantResponseUrl'] !== $original_values['MerchantResponseUrl'] ) {
			$tampered_fields[] = 'MerchantResponseUrl';
		}

		if ( ! empty( $tampered_fields ) ) {
			$this->log(
				sprintf(
					'Security Alert: Payment payload was tampered with via filter for Order #%d. Tampered fields: %s. Restoring original values.',
					$order->get_id(),
					implode( ', ', $tampered_fields )
				),
				'error'
			);

			// Restore all critical values.
			$payload['TotalAmount']           = $original_values['TotalAmount'];
			$payload['CurrencyCode']          = $original_values['CurrencyCode'];
			$payload['OrderIdentifier']       = $original_values['OrderIdentifier'];
			$payload['TransactionIdentifier'] = $original_values['TransactionIdentifier'];
			$payload['Source']                = $original_values['Source'];

			if ( isset( $payload['ExtendedData'] ) ) {
				$payload['ExtendedData']['MerchantResponseUrl'] = $original_values['MerchantResponseUrl'];
			}
		}

		return $payload;
	}

	/**
	 * Handle a successful payment authorization.
	 *
	 * @param \WC_Order $order    The WooCommerce order.
	 * @param array     $response The normalized API response.
	 *
	 * @return array WooCommerce payment result array.
	 */
	protected function handle_successful_payment( \WC_Order $order, array $response ): array {
		$order_id = $order->get_id();

		// Security: Sanitize all external API response data before storing.
		$raw = $response['raw_response'] ?? array();

		$order->update_meta_data( '_woo_powertranz_transaction_id', $this->sanitize_api_response_value( $response['transaction_id'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_authorization_code', $this->sanitize_api_response_value( $response['authorization_code'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_response_code', $this->sanitize_api_response_value( $response['code'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_test_mode', $this->test_mode ? 'yes' : 'no' );
		$order->update_meta_data( '_woo_powertranz_captured', 'yes' );
		$order->update_meta_data( '_woo_powertranz_card_brand', $this->sanitize_api_response_value( $raw['CardBrand'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_rrn', $this->sanitize_api_response_value( $raw['RRN'] ?? '' ) );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: authorization code, 2: transaction ID */
				__( 'Woo PowerTranz payment approved and captured (Sale). Auth Code: %1$s | Transaction ID: %2$s', 'woo-powertranz' ),
				$response['authorization_code'] ?? 'N/A',
				$response['transaction_id'] ?? 'N/A'
			)
		);

		$order->payment_complete( $response['transaction_id'] ?? '' );

		$this->log(
			sprintf( 'Order #%d: Payment approved and captured (Sale). Transaction ID: %s', $order_id, $response['transaction_id'] ?? 'N/A' ),
			'info'
		);

		// Reset rate limiter on successful payment.
		$this->rate_limiter->reset();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Handle a declined payment.
	 *
	 * @param \WC_Order $order    The WooCommerce order.
	 * @param array     $response The normalized API response.
	 *
	 * @return array WooCommerce payment result array.
	 */
	protected function handle_declined_payment( \WC_Order $order, array $response ): array {
		$order_id = $order->get_id();

		// Security: Sanitize all external API response data before storing.
		$order->update_meta_data( '_woo_powertranz_decline_code', $this->sanitize_api_response_value( $response['code'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_decline_message', $this->sanitize_api_response_value( $response['message'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_transaction_id', $this->sanitize_api_response_value( $response['transaction_id'] ?? '' ) );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: decline code, 2: decline reason */
				__( 'Woo PowerTranz payment declined. Code: %1$s | Reason: %2$s', 'woo-powertranz' ),
				$response['code'] ?? 'N/A',
				$response['message'] ?? 'Unknown'
			)
		);

		$order->update_status( 'failed', __( 'Payment declined by card issuer.', 'woo-powertranz' ) );

		$this->log(
			sprintf( 'Order #%d: Payment declined. Code: %s', $order_id, $response['code'] ?? 'N/A' ),
			'warning'
		);

		wc_add_notice(
			$response['message'] ?? __( 'Su pago fue rechazado. Intente con otra tarjeta.', 'woo-powertranz' ),
			'error'
		);

		return array( 'result' => 'failure' );
	}

	/**
	 * Handle a payment error.
	 *
	 * @param \WC_Order $order    The WooCommerce order.
	 * @param array     $response The normalized API response.
	 *
	 * @return array WooCommerce payment result array.
	 */
	protected function handle_payment_error( \WC_Order $order, array $response ): array {
		$order_id = $order->get_id();

		// Security: Sanitize all external API response data before storing.
		$order->update_meta_data( '_woo_powertranz_error_code', $this->sanitize_api_response_value( $response['code'] ?? '' ) );
		$order->update_meta_data( '_woo_powertranz_error_message', $this->sanitize_api_response_value( $response['message'] ?? '' ) );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: error code, 2: error message */
				__( 'Woo PowerTranz payment error. Code: %1$s | Message: %2$s', 'woo-powertranz' ),
				$response['code'] ?? 'N/A',
				$response['message'] ?? 'Unknown error'
			)
		);

		$order->update_status( 'failed', __( 'Payment processing error.', 'woo-powertranz' ) );

		$this->log(
			sprintf( 'Order #%d: Payment error. Code: %s | Message: %s', $order_id, $response['code'] ?? 'N/A', $response['message'] ?? 'Unknown' ),
			'error'
		);

		wc_add_notice(
			__( 'Ocurrio un error al procesar su pago. Por favor intente de nuevo.', 'woo-powertranz' ),
			'error'
		);

		return array( 'result' => 'failure' );
	}

	/**
	 * Get ISO 4217 numeric currency code.
	 *
	 * @param string $currency The currency code (e.g., 'USD', 'EUR').
	 *
	 * @return string The numeric currency code.
	 */
	protected function get_currency_numeric_code( string $currency ): string {
		$codes = array(
			'USD' => '840',
			'EUR' => '978',
			'GBP' => '826',
			'CAD' => '124',
			'AUD' => '036',
			'JPY' => '392',
			'CHF' => '756',
			'MXN' => '484',
			'BRL' => '986',
			'NZD' => '554',
			'SGD' => '702',
			'HKD' => '344',
			'INR' => '356',
			'KRW' => '410',
			'ZAR' => '710',
			'SEK' => '752',
			'NOK' => '578',
			'DKK' => '208',
			'PLN' => '985',
			'CZK' => '203',
			'HUF' => '348',
			'ILS' => '376',
			'THB' => '764',
			'MYR' => '458',
			'PHP' => '608',
			'TWD' => '901',
			'IDR' => '360',
			'AED' => '784',
			'SAR' => '682',
			'COP' => '170',
			'CLP' => '152',
			'ARS' => '032',
			'PEN' => '604',
			'BBD' => '052',
			'BSD' => '044',
			'BZD' => '084',
			'JMD' => '388',
			'TTD' => '780',
			'XCD' => '951',
			'AWG' => '533',
			'ANG' => '532',
			'KYD' => '136',
			'BMD' => '060',
			'CRC' => '188',
		);

		return $codes[ strtoupper( $currency ) ] ?? '840';
	}

	/**
	 * Normalize a WooCommerce state code for 3DS billAddrState.
	 *
	 * WooCommerce stores states as ISO 3166-2 codes (e.g. "CR-SJ"),
	 * but 3DS billAddrState accepts max 3 characters. This strips
	 * the country prefix and returns just the subdivision code.
	 *
	 * @param string $state The WooCommerce state code.
	 *
	 * @return string The normalized state code (max 3 chars).
	 */
	protected function normalize_state_code( string $state ): string {
		// Strip country prefix from ISO 3166-2 codes (e.g. "CR-SJ" → "SJ").
		if ( str_contains( $state, '-' ) ) {
			$state = substr( $state, strrpos( $state, '-' ) + 1 );
		}

		return substr( $state, 0, 3 );
	}

	/**
	 * Renderizar la pagina del iframe 3DS.
	 *
	 * Esta pagina se muestra al cliente despues de que la autorizacion inicial
	 * retorna SP4. Contiene el iframe del desafio 3DS proporcionado por PowerTranz.
	 *
	 * @return void
	 */
	public function render_3ds_iframe_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- La clave del pedido sirve como token de autenticacion.
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->key_is_valid( $order_key ) ) {
			wp_die( esc_html__( 'Pedido invalido. Por favor regrese al checkout e intente de nuevo.', 'woo-powertranz' ), '', array( 'response' => 403 ) );
		}

		$redirect_data = $order->get_meta( '_woo_powertranz_redirect_data' );

		if ( empty( $redirect_data ) ) {
			$this->log( "Pedido #{$order_id}: RedirectData 3DS expirado o no encontrado.", 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Eliminar RedirectData despues de obtenerlo (uso unico).
		$order->delete_meta_data( '_woo_powertranz_redirect_data' );
		$order->save();

		// Render the 3DS iframe page via a template that themes can override at:
		// {theme}/woocommerce/woo-powertranz/3ds-iframe-page.php
		wc_get_template(
			'woo-powertranz/3ds-iframe-page.php',
			array(
				'order'         => $order,
				'order_id'      => $order_id,
				'redirect_data' => $redirect_data,
				'site_name'     => get_bloginfo( 'name' ),
				'checkout_url'  => wc_get_checkout_url(),
			),
			'',
			WOO_POWERTRANZ_PLUGIN_DIR . 'templates/'
		);
		exit;
	}

	/**
	 * Procesar el callback 3DS de PowerTranz.
	 *
	 * Cuando el desafio 3DS se completa en el iframe, PowerTranz redirige
	 * a esta URL de callback (MerchantResponseUrl). Este metodo finaliza
	 * el pago enviando el token SPI a /spi/Payment.
	 *
	 * Importante: Este callback se ejecuta DENTRO del iframe 3DS, por lo que
	 * no se puede usar wp_safe_redirect() (redigiria solo dentro del iframe).
	 * En su lugar, se emite JavaScript para redirigir la ventana principal.
	 *
	 * @return void
	 */
	public function handle_3ds_callback(): void {
		$this->log( 'Callback 3DS recibido.', 'info' );

		// PowerTranz envia la respuesta como un POST de formulario.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callback externo de PowerTranz, no hay nonce de WP disponible.
		$raw_body = file_get_contents( 'php://input' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Intentar parsear como JSON primero (algunas implementaciones envian JSON).
		$callback_data = json_decode( $raw_body, true );

		if ( null === $callback_data ) {
			// Intentar como datos de formulario URL-encoded.
			parse_str( $raw_body, $callback_data );
		}

		$this->log( sprintf( 'Callback 3DS claves de datos: %s', implode( ', ', array_keys( $callback_data ?: array() ) ) ), 'info' );

		// Extraer el ID del pedido de la respuesta.
		$order_id = 0;

		if ( ! empty( $callback_data['OrderIdentifier'] ) ) {
			$order_id = absint( $callback_data['OrderIdentifier'] );
		} elseif ( ! empty( $callback_data['ExtendedData']['Custom']['wc_order_id'] ) ) {
			$order_id = absint( $callback_data['ExtendedData']['Custom']['wc_order_id'] );
		}

		// Intentar buscar el pedido por TransactionIdentifier en meta.
		if ( 0 === $order_id && ! empty( $callback_data['TransactionIdentifier'] ) ) {
			$orders = wc_get_orders( array(
				'meta_key'   => '_woo_powertranz_transaction_id',
				'meta_value' => sanitize_text_field( $callback_data['TransactionIdentifier'] ),
				'limit'      => 1,
			) );
			if ( ! empty( $orders ) ) {
				$order_id = $orders[0]->get_id();
			}
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log( 'Callback 3DS: No se pudo identificar el pedido desde los datos del callback.', 'error' );
			wp_die( esc_html__( 'Pedido no encontrado.', 'woo-powertranz' ), '', array( 'response' => 404 ) );
		}

		$this->log( sprintf( 'Pedido #%d: Procesando callback 3DS.', $order_id ), 'info' );

		// Usar el SpiToken del callback (post-3DS) en lugar del token inicial.
		// Despues de la autenticacion 3DS, el banco devuelve un SpiToken actualizado
		// que contiene los resultados de la verificacion. El endpoint /spi/Payment
		// requiere este token actualizado, no el original de la autorizacion.
		$spi_token = ! empty( $callback_data['SpiToken'] )
			? sanitize_text_field( $callback_data['SpiToken'] )
			: $order->get_meta( '_woo_powertranz_spi_token' );

		if ( empty( $spi_token ) ) {
			$this->log( "Pedido #{$order_id}: No se encontro token SPI en callback ni en meta del pedido.", 'error' );
			$order->add_order_note( __( 'Se recibio callback 3DS pero el token SPI no estaba presente.', 'woo-powertranz' ) );
			$this->redirect_from_iframe( wc_get_checkout_url() );
		}

		// Limpiar el token SPI del meta del pedido (uso unico).
		$order->delete_meta_data( '_woo_powertranz_spi_token' );
		$order->save();

		// Completar el pago mediante /spi/Payment.
		$api_client = new Woo_PowerTranz_API_Client(
			$this->get_api_endpoint(),
			$this->get_merchant_id(),
			$this->get_api_password(),
			30
		);

		$this->log( "Pedido #{$order_id}: Enviando solicitud SPI Payment con token.", 'info' );

		$response = $api_client->complete_payment( $spi_token );

		$this->log( sprintf( 'Pedido #%d: Respuesta SPI Payment - Codigo: %s, Estado: %s', $order_id, $response['code'] ?? 'N/A', $response['status'] ?? 'N/A' ), 'info' );

		if ( $response['success'] ) {
			// Almacenar meta de pago y actualizar estado del pedido.
			$raw = $response['raw_response'] ?? array();

			$order->update_meta_data( '_woo_powertranz_transaction_id', $this->sanitize_api_response_value( $response['transaction_id'] ?? '' ) );
			$order->update_meta_data( '_woo_powertranz_authorization_code', $this->sanitize_api_response_value( $response['authorization_code'] ?? '' ) );
			$order->update_meta_data( '_woo_powertranz_response_code', $this->sanitize_api_response_value( $response['code'] ?? '' ) );
			$order->update_meta_data( '_woo_powertranz_test_mode', $this->test_mode ? 'yes' : 'no' );
			$order->update_meta_data( '_woo_powertranz_captured', 'yes' );
			$order->update_meta_data( '_woo_powertranz_card_brand', $this->sanitize_api_response_value( $raw['CardBrand'] ?? '' ) );
			$order->update_meta_data( '_woo_powertranz_rrn', $this->sanitize_api_response_value( $raw['RRN'] ?? '' ) );
				$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: codigo de autorizacion, 2: ID de transaccion */
					__( 'Pago Woo PowerTranz aprobado y capturado (Sale + 3DS). Codigo Auth: %1$s | ID Transaccion: %2$s', 'woo-powertranz' ),
					$response['authorization_code'] ?? 'N/A',
					$response['transaction_id'] ?? 'N/A'
				)
			);

			$order->payment_complete( $response['transaction_id'] ?? '' );

			$this->log(
				sprintf( 'Pedido #%d: Pago 3DS aprobado y capturado (Sale). ID Transaccion: %s', $order_id, $response['transaction_id'] ?? 'N/A' ),
				'info'
			);

			$this->rate_limiter->reset();

			$this->redirect_from_iframe( $this->get_return_url( $order ) );
		}

		if ( 'declined' === $response['status'] ) {
			$this->handle_declined_payment( $order, $response );
			$redirect_url = add_query_arg( 'ept_error', 'declined', wc_get_checkout_url() );
		} else {
			$this->handle_payment_error( $order, $response );
			$redirect_url = add_query_arg( 'ept_error', 'error', wc_get_checkout_url() );
		}

		$this->redirect_from_iframe( $redirect_url );
	}

	/**
	 * Redirigir desde dentro del iframe 3DS a la ventana principal.
	 *
	 * Como el callback 3DS se ejecuta dentro de un iframe, no se puede usar
	 * wp_safe_redirect() (solo redigiria el iframe). En su lugar, se emite
	 * JavaScript que establece window.top.location.href para salir del iframe
	 * y redirigir la ventana principal del navegador.
	 *
	 * @param string $url La URL a la que redirigir.
	 * @return never
	 */
	private function redirect_from_iframe( string $url ): never {
		// Clean any output generated by WooCommerce/plugin hooks (e.g. FooEvents,
		// email notifications) to ensure the redirect JavaScript is delivered cleanly.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$safe_url = esc_url( $url );
		$js_url   = esc_js( $url );

		?>
		<!DOCTYPE html>
		<html>
		<head><title><?php esc_html_e( 'Redirigiendo...', 'woo-powertranz' ); ?></title></head>
		<body>
		<p><?php esc_html_e( 'Procesando pago, por favor espere...', 'woo-powertranz' ); ?></p>
		<script>
		if (window.top !== window.self) {
			window.top.location.href = "<?php echo $js_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado con esc_js arriba. ?>";
		} else {
			window.location.href = "<?php echo $js_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado con esc_js arriba. ?>";
		}
		</script>
		<noscript><meta http-equiv="refresh" content="0;url=<?php echo $safe_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado con esc_url arriba. ?>"></noscript>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Display admin options.
	 *
	 * @return void
	 */
	public function admin_options(): void {
		?>
		<div class="woo-powertranz-admin-wrapper">
			<div class="woo-powertranz-settings">
				<h2>
					<?php echo esc_html( $this->get_method_title() ); ?>
					<?php
					if ( $this->test_mode ) {
						echo '<span style="background: #ffba00; color: #000; padding: 2px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">';
						echo esc_html__( 'Test Mode', 'woo-powertranz' );
						echo '</span>';
					}
					?>
					<?php wc_back_link( __( 'Return to payments', 'woo-powertranz' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>
				</h2>
				<p><?php echo esc_html( $this->get_method_description() ); ?></p>
				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin styles for the settings page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_styles( string $hook ): void {
		// Only load on WooCommerce settings pages.
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checking URL parameter for styling.
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';

		// Only load on our gateway's settings section.
		if ( $this->id !== $section ) {
			return;
		}

		wp_enqueue_style(
			'woo-powertranz-admin',
			plugins_url( 'assets/css/admin.css', dirname( __FILE__ ) ),
			array(),
			WOO_POWERTRANZ_VERSION
		);
	}

	/**
	 * Get the API endpoint URL based on test mode setting.
	 *
	 * @return string The API endpoint URL.
	 */
	public function get_api_endpoint(): string {
		if ( $this->test_mode ) {
			return 'https://staging.ptranz.com/Api/';
		}

		return 'https://gateway.ptranz.com/Api/';
	}

	/**
	 * Get Merchant ID.
	 *
	 * @return string The configured Merchant ID.
	 */
	public function get_merchant_id(): string {
		return $this->merchant_id;
	}

	/**
	 * Get API Password.
	 *
	 * Security Note: This method is protected to prevent external code from
	 * accessing API credentials directly. Use get_api_client() to obtain
	 * a configured API client instance instead.
	 *
	 * @return string The configured API Password.
	 */
	protected function get_api_password(): string {
		return $this->api_password;
	}

	/**
	 * Check if test mode is enabled.
	 *
	 * @return bool True if test mode is enabled, false otherwise.
	 */
	public function is_test_mode(): bool {
		return $this->test_mode;
	}

	/**
	 * Log messages for debugging.
	 *
	 * @param string $message The message to log.
	 * @param string $level   Log level.
	 * @return void
	 */
	public function log( string $message, string $level = 'info' ): void {
		if ( empty( $this->id ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => $this->id ) );
	}

	/**
	 * Sanitize API response values before storing in database.
	 *
	 * Security: External API responses should never be trusted. This method
	 * sanitizes values from PowerTranz before they are stored in order meta.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return string The sanitized value.
	 */
	protected function sanitize_api_response_value( $value ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Mask a card number showing first 6 and last 4 digits.
	 *
	 * Example: 4012000000020006 → 401200******0006
	 *
	 * @param string $card_number The full card number.
	 *
	 * @return string The masked card number, or empty string if invalid.
	 */
	protected function mask_card_number( string $card_number ): string {
		$digits = preg_replace( '/\D/', '', $card_number );

		if ( strlen( $digits ) < 10 ) {
			return '';
		}

		$first6 = substr( $digits, 0, 6 );
		$last4  = substr( $digits, -4 );
		$masked = str_repeat( '*', strlen( $digits ) - 10 );

		return $first6 . $masked . $last4;
	}

	/*
	|--------------------------------------------------------------------------
	| CAPTURE METHODS
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add capture action to order actions dropdown.
	 *
	 * @param array $actions Existing order actions.
	 *
	 * @return array Modified actions array.
	 */
	public function add_capture_order_action( array $actions ): array {
		global $theorder;

		$order = $theorder;
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}

		if ( 'woo_powertranz' !== $order->get_payment_method() ) {
			return $actions;
		}

		if ( 'on-hold' !== $order->get_status() ) {
			return $actions;
		}

		$transaction_id = $order->get_meta( '_woo_powertranz_transaction_id' );
		if ( empty( $transaction_id ) ) {
			return $actions;
		}

		$captured = $order->get_meta( '_woo_powertranz_captured' );
		if ( 'yes' === $captured ) {
			return $actions;
		}

		$actions['woo_powertranz_capture'] = __( 'Capture Woo PowerTranz Payment', 'woo-powertranz' );

		return $actions;
	}

	/**
	 * Process capture action from order actions dropdown.
	 *
	 * Security: Verifies user capability before processing financial operations.
	 *
	 * @param \WC_Order $order The order to capture payment for.
	 *
	 * @return void
	 */
	public function process_capture_action( \WC_Order $order ): void {
		// Security: Verify user has capability to edit orders and manage WooCommerce.
		if ( ! current_user_can( 'edit_shop_orders' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			$this->log(
				sprintf( 'Order #%d: Unauthorized capture attempt detected.', $order->get_id() ),
				'warning'
			);
			$order->add_order_note(
				__( 'Payment capture attempted by unauthorized user.', 'woo-powertranz' )
			);
			return;
		}

		$result = $this->capture_payment( $order );

		if ( $result['success'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: capture transaction ID */
					__( 'Payment captured successfully. Capture ID: %s', 'woo-powertranz' ),
					$result['transaction_id'] ?? 'N/A'
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Payment capture failed: %s', 'woo-powertranz' ),
					$result['message'] ?? 'Unknown error'
				)
			);
		}
	}

	/**
	 * Capture an authorized payment.
	 *
	 * @param \WC_Order  $order  The order to capture payment for.
	 * @param float|null $amount Optional amount to capture.
	 *
	 * @return array Result array.
	 */
	public function capture_payment( \WC_Order $order, ?float $amount = null ): array {
		$order_id       = $order->get_id();
		$transaction_id = $order->get_meta( '_woo_powertranz_transaction_id' );

		if ( empty( $transaction_id ) ) {
			$this->log( "Order #{$order_id}: Cannot capture - no transaction ID found.", 'error' );
			return array(
				'success' => false,
				'message' => __( 'No transaction ID found. Cannot capture payment.', 'woo-powertranz' ),
			);
		}

		$captured = $order->get_meta( '_woo_powertranz_captured' );
		if ( 'yes' === $captured ) {
			return array(
				'success' => false,
				'message' => __( 'Payment has already been captured.', 'woo-powertranz' ),
			);
		}

		$capture_amount = $amount ?? (float) $order->get_total();
		$currency_code  = $this->get_currency_numeric_code( $order->get_currency() );

		$api_client = new Woo_PowerTranz_API_Client(
			$this->get_api_endpoint(),
			$this->get_merchant_id(),
			$this->get_api_password(),
			30
		);

		$this->log( "Order #{$order_id}: Sending capture request for transaction {$transaction_id}.", 'info' );

		$response = $api_client->capture_payment( $transaction_id, $capture_amount, $currency_code );

		if ( $response['success'] ) {
			$order->update_meta_data( '_woo_powertranz_captured', 'yes' );
			$order->update_meta_data( '_woo_powertranz_capture_id', $response['transaction_id'] ?? '' );
			$order->update_meta_data( '_woo_powertranz_captured_amount', $capture_amount );
			$order->save();

			$order->update_status( 'processing', __( 'Payment captured successfully.', 'woo-powertranz' ) );

			$this->log(
				sprintf( 'Order #%d: Payment captured successfully. Capture ID: %s', $order_id, $response['transaction_id'] ?? 'N/A' ),
				'info'
			);

			return array(
				'success'        => true,
				'message'        => __( 'Payment captured successfully.', 'woo-powertranz' ),
				'transaction_id' => $response['transaction_id'] ?? '',
			);
		}

		$this->log(
			sprintf( 'Order #%d: Capture failed. Code: %s | Message: %s', $order_id, $response['code'] ?? 'N/A', $response['message'] ?? 'Unknown' ),
			'error'
		);

		return array(
			'success' => false,
			'message' => $response['message'] ?? __( 'Capture failed. Please try again or contact support.', 'woo-powertranz' ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| REFUND METHODS
	|--------------------------------------------------------------------------
	*/

	/**
	 * Process a refund.
	 *
	 * @param int        $order_id The order ID.
	 * @param float|null $amount   The amount to refund.
	 * @param string     $reason   The reason for the refund.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new \WP_Error( 'woo_powertranz_refund_error', __( 'Order not found.', 'woo-powertranz' ) );
		}

		$transaction_id = $order->get_meta( '_woo_powertranz_transaction_id' );

		if ( empty( $transaction_id ) ) {
			return new \WP_Error( 'woo_powertranz_refund_error', __( 'No transaction ID found. Cannot process refund.', 'woo-powertranz' ) );
		}

		$refund_amount = $amount ?? (float) $order->get_total();

		if ( $refund_amount <= 0 ) {
			return new \WP_Error( 'woo_powertranz_refund_error', __( 'Refund amount must be greater than zero.', 'woo-powertranz' ) );
		}

		$currency_code = $this->get_currency_numeric_code( $order->get_currency() );

		$captured = $order->get_meta( '_woo_powertranz_captured' );

		if ( 'yes' !== $captured && 'on-hold' === $order->get_status() ) {
			return $this->process_void( $order );
		}

		$api_client = new Woo_PowerTranz_API_Client(
			$this->get_api_endpoint(),
			$this->get_merchant_id(),
			$this->get_api_password(),
			30
		);

		$this->log(
			sprintf( 'Order #%d: Sending refund request. Amount: %s, Transaction: %s', $order_id, $refund_amount, $transaction_id ),
			'info'
		);

		$response = $api_client->refund_payment( $transaction_id, $refund_amount, $currency_code, $reason );

		if ( $response['success'] ) {
			$refund_ids   = $order->get_meta( '_woo_powertranz_refund_ids' ) ?: array();
			$refund_ids[] = $response['transaction_id'] ?? '';
			$order->update_meta_data( '_woo_powertranz_refund_ids', $refund_ids );

			$total_refunded  = (float) $order->get_meta( '_woo_powertranz_total_refunded' ) ?: 0;
			$total_refunded += $refund_amount;
			$order->update_meta_data( '_woo_powertranz_total_refunded', $total_refunded );

			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: refund amount, 2: refund transaction ID, 3: reason */
					__( 'Refunded %1$s via Woo PowerTranz. Refund ID: %2$s. Reason: %3$s', 'woo-powertranz' ),
					wc_price( $refund_amount, array( 'currency' => $order->get_currency() ) ),
					$response['transaction_id'] ?? 'N/A',
					$reason ?: __( 'No reason provided', 'woo-powertranz' )
				)
			);

			$this->log(
				sprintf( 'Order #%d: Refund successful. Refund ID: %s', $order_id, $response['transaction_id'] ?? 'N/A' ),
				'info'
			);

			return true;
		}

		$error_message = $response['message'] ?? __( 'Refund failed. Please try again.', 'woo-powertranz' );

		$this->log(
			sprintf( 'Order #%d: Refund failed. Code: %s | Message: %s', $order_id, $response['code'] ?? 'N/A', $error_message ),
			'error'
		);

		return new \WP_Error( 'woo_powertranz_refund_error', $error_message );
	}

	/**
	 * Process a void for an uncaptured authorization.
	 *
	 * @param \WC_Order $order The order to void.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	protected function process_void( \WC_Order $order ): bool|\WP_Error {
		$order_id       = $order->get_id();
		$transaction_id = $order->get_meta( '_woo_powertranz_transaction_id' );

		if ( empty( $transaction_id ) ) {
			return new \WP_Error( 'woo_powertranz_void_error', __( 'No transaction ID found. Cannot void authorization.', 'woo-powertranz' ) );
		}

		$api_client = new Woo_PowerTranz_API_Client(
			$this->get_api_endpoint(),
			$this->get_merchant_id(),
			$this->get_api_password(),
			30
		);

		$this->log( "Order #{$order_id}: Sending void request for transaction {$transaction_id}.", 'info' );

		$response = $api_client->void_payment( $transaction_id );

		if ( $response['success'] ) {
			$order->update_meta_data( '_woo_powertranz_voided', 'yes' );
			$order->update_meta_data( '_woo_powertranz_void_id', $response['transaction_id'] ?? '' );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: void transaction ID */
					__( 'Authorization voided. Void ID: %s', 'woo-powertranz' ),
					$response['transaction_id'] ?? 'N/A'
				)
			);

			$this->log(
				sprintf( 'Order #%d: Authorization voided successfully. Void ID: %s', $order_id, $response['transaction_id'] ?? 'N/A' ),
				'info'
			);

			return true;
		}

		$error_message = $response['message'] ?? __( 'Void failed. Please try again.', 'woo-powertranz' );

		$this->log(
			sprintf( 'Order #%d: Void failed. Code: %s | Message: %s', $order_id, $response['code'] ?? 'N/A', $error_message ),
			'error'
		);

		return new \WP_Error( 'woo_powertranz_void_error', $error_message );
	}

	/**
	 * Create an API client instance.
	 *
	 * @return Woo_PowerTranz_API_Client The configured API client.
	 */
	public function get_api_client(): Woo_PowerTranz_API_Client {
		return new Woo_PowerTranz_API_Client(
			$this->get_api_endpoint(),
			$this->get_merchant_id(),
			$this->get_api_password(),
			30
		);
	}
}
