<?php
/**
 * 3DS iframe verification page template.
 *
 * This template renders the 3D Secure challenge page shown to the customer
 * during payment verification. It can be overridden by themes at:
 * {theme}/woocommerce/woo-powertranz/3ds-iframe-page.php
 *
 * @package Woo_PowerTranz
 * @version 1.1.0
 *
 * @var WC_Order $order         The WooCommerce order object.
 * @var int      $order_id      The order ID.
 * @var string   $redirect_data The PowerTranz 3DS HTML for iframe srcdoc.
 * @var string   $site_name     The site name from get_bloginfo( 'name' ).
 * @var string   $checkout_url  The WooCommerce checkout page URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $site_name ); ?> - <?php esc_html_e( 'Verificacion de Pago', 'woo-powertranz' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: #f7f7f7; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
		.ept-3ds-container { max-width: 600px; width: 100%; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
		.ept-3ds-header { padding: 20px; text-align: center; border-bottom: 1px solid #eee; }
		.ept-3ds-header h1 { margin: 0; font-size: 18px; color: #333; }
		.ept-3ds-header p { margin: 8px 0 0; font-size: 14px; color: #666; }
		.ept-3ds-timer { margin: 8px 0 0; font-size: 13px; color: #999; }
		.ept-3ds-timer.ept-3ds-timer--warning { color: #c00; font-weight: 600; }
		.ept-3ds-frame { width: 100%; min-height: 400px; border: none; }
	</style>
</head>
<body>
	<div class="ept-3ds-container">
		<div class="ept-3ds-header">
			<h1><?php esc_html_e( 'Verificacion de Pago', 'woo-powertranz' ); ?></h1>
			<p><?php esc_html_e( 'Por Favor Espere Estamos Procesando su pago.', 'woo-powertranz' ); ?></p>
			<p class="ept-3ds-timer" id="ept-3ds-timer">10:00</p>
		</div>
		<iframe class="ept-3ds-frame" srcdoc="<?php echo esc_attr( $redirect_data ); ?>"></iframe>
	</div>
	<script>
	(function() {
		var remaining = 600;
		var timerEl = document.getElementById( 'ept-3ds-timer' );
		var redirectUrl = <?php echo wp_json_encode( esc_url( $checkout_url ) ); ?>;

		var interval = setInterval( function() {
			remaining--;
			var mins = Math.floor( remaining / 60 );
			var secs = remaining % 60;
			timerEl.textContent = mins + ':' + ( secs < 10 ? '0' : '' ) + secs;

			if ( remaining < 60 ) {
				timerEl.classList.add( 'ept-3ds-timer--warning' );
			}

			if ( remaining <= 0 ) {
				clearInterval( interval );
				window.location.href = redirectUrl;
			}
		}, 1000 );
	})();
	</script>
</body>
</html>
