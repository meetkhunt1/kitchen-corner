<?php
/**
 * Plugin Name: Kitchen Corner - Shiprocket delivery rates
 * Description: Live delivery charges at checkout from Shiprocket's courier rates, worked out from the customer's PIN code and the cart weight. Works with the storefront checkout (Store API) and the store's own checkout.
 * Version:     1.0.0
 *
 * Install: upload to wp-content/mu-plugins/ (next to kc-cart-handoff.php).
 * Then:
 *   1. WooCommerce -> Settings -> Shipping -> "Shiprocket live rates":
 *      enter the Shiprocket API user's email and password, check the pickup
 *      PIN code, save. The "Status" line on that page shows whether
 *      Shiprocket answered.
 *   2. WooCommerce -> Settings -> Shipping -> your India zone: add the
 *      shipping method "Shiprocket live rates" and remove (or disable) the
 *      old "Shiprocket" rate method that always charges the Rs 40 fallback.
 *
 * Why: the Shiprocket plugin's rate method only ever returns its fallback
 * price for this site, so delivery was a flat Rs 40 for every PIN code and
 * weight. This method asks Shiprocket's courier serviceability API directly.
 * Orders still reach Shiprocket through the WooCommerce channel as before.
 */

defined( 'ABSPATH' ) || exit;

const KC_SR_API          = 'https://apiv2.shiprocket.in/v1/external/';
const KC_SR_TOKEN_KEY    = 'kc_sr_token';
const KC_SR_STATUS_KEY   = 'kc_sr_last_status';
const KC_SR_QUOTE_TTL    = 6 * HOUR_IN_SECONDS;
const KC_SR_LOG_SOURCE   = 'kc-shiprocket';

add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
	$methods['kc_shiprocket'] = 'KC_Shiprocket_Rates';
	return $methods;
} );

add_action( 'woocommerce_shipping_init', function () {
	if ( class_exists( 'KC_Shiprocket_Rates' ) ) {
		return;
	}

	class KC_Shiprocket_Rates extends WC_Shipping_Method {

		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'kc_shiprocket';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = 'Shiprocket live rates';
			$this->method_description = 'Delivery charge from Shiprocket courier rates for the customer\'s PIN code and the cart weight.';
			$this->supports           = array( 'shipping-zones', 'instance-settings', 'settings' );
			$this->init();
		}

		public function init() {
			$this->init_form_fields();
			$this->init_settings();
			$this->instance_form_fields = array(
				'title' => array(
					'title'   => 'Name shown at checkout',
					'type'    => 'text',
					'default' => 'Standard delivery',
				),
			);
			$this->title = $this->get_option( 'title', 'Standard delivery' );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		// Settings shared by every zone (WooCommerce -> Settings -> Shipping -> Shiprocket live rates).
		public function init_form_fields() {
			$status = get_option( KC_SR_STATUS_KEY );
			$this->form_fields = array(
				'status_info'     => array(
					'title'       => 'Status',
					'type'        => 'title',
					'description' => $status
						? esc_html( $status['time'] . ' - ' . $status['message'] )
						: 'No rate looked up yet. Save your API user below, then add a product to the cart and enter a PIN code.',
				),
				'api_email'       => array(
					'title'       => 'Shiprocket API user email',
					'type'        => 'email',
					'description' => 'Shiprocket -> Settings -> API -> Configure -> Create an API user. Use that user here, not your normal Shiprocket login.',
					'default'     => '',
				),
				'api_password'    => array(
					'title'   => 'Shiprocket API user password',
					'type'    => 'password',
					'default' => '',
				),
				'pickup_postcode' => array(
					'title'       => 'Pickup PIN code',
					'type'        => 'text',
					'description' => 'Where orders ship from (must match your pickup address in Shiprocket).',
					'default'     => '360001',
				),
				'pick'            => array(
					'title'   => 'Which courier price to charge',
					'type'    => 'select',
					'default' => 'recommended',
					'options' => array(
						'recommended' => 'Shiprocket\'s recommended courier',
						'cheapest'    => 'Cheapest courier',
					),
				),
				'markup_percent'  => array(
					'title'       => 'Add on top (%)',
					'type'        => 'number',
					'description' => 'Optional. E.g. 10 adds 10% to the courier price to cover packing. 0 = charge exactly the courier price.',
					'default'     => '0',
					'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				),
				'packing_grams'   => array(
					'title'       => 'Packing weight per order (grams)',
					'type'        => 'number',
					'description' => 'Box and packing material added to the product weights.',
					'default'     => '100',
					'custom_attributes' => array( 'min' => '0', 'step' => '10' ),
				),
				'min_charge'      => array(
					'title'   => 'Minimum delivery charge (Rs)',
					'type'    => 'number',
					'default' => '0',
					'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				),
				'round_to'        => array(
					'title'   => 'Round the charge up to the nearest',
					'type'    => 'select',
					'default' => '1',
					'options' => array( '1' => 'Rs 1', '5' => 'Rs 5', '10' => 'Rs 10' ),
				),
				'free_above'      => array(
					'title'       => 'Free delivery for orders of (Rs) or more',
					'type'        => 'number',
					'description' => '0 = never free.',
					'default'     => '0',
					'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				),
				'fallback_first'  => array(
					'title'       => 'If Shiprocket is not reachable: first kg (Rs)',
					'type'        => 'number',
					'description' => 'Used only when Shiprocket does not answer (wrong login, outage), so checkout keeps working.',
					'default'     => '70',
					'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				),
				'fallback_per_kg' => array(
					'title'   => 'If Shiprocket is not reachable: each extra kg (Rs)',
					'type'    => 'number',
					'default' => '35',
					'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				),
				'debug'           => array(
					'title'       => 'Log every lookup',
					'type'        => 'checkbox',
					'label'       => 'Write each rate lookup to WooCommerce -> Status -> Logs (source: kc-shiprocket). Errors are always logged.',
					'default'     => 'no',
				),
			);
		}

		// Keep the saved password when the field is left blank on save.
		public function validate_password_field( $key, $value ) {
			$value = is_null( $value ) ? '' : trim( wp_unslash( $value ) );
			if ( '' === $value && 'api_password' === $key ) {
				return (string) $this->get_option( 'api_password' );
			}
			return $value;
		}

		public function process_admin_options() {
			$saved = parent::process_admin_options();
			delete_transient( KC_SR_TOKEN_KEY ); // log in again with the new details
			return $saved;
		}

		public function calculate_shipping( $package = array() ) {
			$dest = preg_replace( '/\D/', '', (string) ( $package['destination']['postcode'] ?? '' ) );
			if ( ! preg_match( '/^[1-9]\d{5}$/', $dest ) ) {
				return; // no PIN code yet: no rate, checkout asks for one
			}

			// Cart weight in kg (variations inherit the product's weight).
			$weight = 0.0;
			foreach ( $package['contents'] as $item ) {
				$product = $item['data'] ?? null;
				if ( ! $product || ! $product->needs_shipping() ) {
					continue;
				}
				$w = (float) $product->get_weight();
				if ( $w <= 0 ) {
					$w = (float) wc_get_weight( 0.5, get_option( 'woocommerce_weight_unit' ), 'kg' );
					kc_sr_log( 'warning', 'No weight on product #' . $product->get_id() . ', counted as 0.5 kg.' );
				}
				$weight += (float) wc_get_weight( $w, 'kg' ) * (int) $item['quantity'];
			}
			$weight += max( 0, (float) $this->get_option( 'packing_grams', 100 ) ) / 1000;
			$weight  = max( 0.1, round( $weight, 2 ) );

			$value  = (float) ( $package['contents_cost'] ?? 0 );
			$quote  = kc_sr_quote(
				array(
					'email'    => (string) $this->get_option( 'api_email' ),
					'password' => (string) $this->get_option( 'api_password' ),
					'pick'     => (string) $this->get_option( 'pick', 'recommended' ),
					'debug'    => 'yes' === $this->get_option( 'debug' ),
				),
				preg_replace( '/\D/', '', (string) $this->get_option( 'pickup_postcode', '360001' ) ),
				$dest,
				$weight,
				$value
			);

			if ( 'unserviceable' === $quote['status'] ) {
				return; // no courier delivers there: checkout says so
			}

			$label = $this->title;
			$meta  = array( 'Weight (kg)' => $weight );

			if ( 'ok' === $quote['status'] ) {
				$cost = $quote['rate'] * ( 1 + max( 0, (float) $this->get_option( 'markup_percent', 0 ) ) / 100 );
				if ( $quote['days'] ) {
					$label .= ' (' . $quote['days'] . ' days)';
				}
				$meta['Courier (estimate)'] = $quote['courier'];
				$meta['Courier rate']       = $quote['rate'];
			} else {
				$cost = (float) $this->get_option( 'fallback_first', 70 )
					+ max( 0, ceil( $weight - 1 ) ) * (float) $this->get_option( 'fallback_per_kg', 35 );
				$meta['Rate source'] = 'fallback';
			}

			$cost  = max( $cost, (float) $this->get_option( 'min_charge', 0 ) );
			$step  = max( 1, (int) $this->get_option( 'round_to', 1 ) );
			$cost  = ceil( $cost / $step ) * $step;
			$free  = (float) $this->get_option( 'free_above', 0 );
			if ( $free > 0 && $value >= $free ) {
				$cost  = 0;
				$label = 'Free delivery' . ( 'ok' === $quote['status'] && $quote['days'] ? ' (' . $quote['days'] . ' days)' : '' );
			}

			$this->add_rate(
				array(
					'id'        => $this->get_rate_id(),
					'label'     => $label,
					'cost'      => $cost,
					'package'   => $package,
					'meta_data' => $meta,
				)
			);
		}
	}
} );

function kc_sr_log( $level, $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => KC_SR_LOG_SOURCE ) );
	}
}

function kc_sr_status( $message ) {
	update_option(
		KC_SR_STATUS_KEY,
		array( 'time' => wp_date( 'd M Y, H:i' ), 'message' => $message ),
		false
	);
}

// A Shiprocket API token (valid 10 days; kept 9). '' when login fails.
function kc_sr_token( $email, $password, $fresh = false ) {
	if ( ! $fresh ) {
		$token = get_transient( KC_SR_TOKEN_KEY );
		if ( $token ) {
			return $token;
		}
	}
	if ( '' === $email || '' === $password ) {
		kc_sr_status( 'Not set up: enter the Shiprocket API user email and password.' );
		return '';
	}
	if ( get_transient( 'kc_sr_login_failed' ) ) {
		return ''; // do not retry a failed login on every page view
	}
	$res  = wp_remote_post(
		KC_SR_API . 'auth/login',
		array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'email' => $email, 'password' => $password ) ),
		)
	);
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$body = is_wp_error( $res ) ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 === $code && ! empty( $body['token'] ) ) {
		set_transient( KC_SR_TOKEN_KEY, $body['token'], 9 * DAY_IN_SECONDS );
		delete_transient( 'kc_sr_login_failed' );
		return $body['token'];
	}
	$why = is_wp_error( $res ) ? $res->get_error_message() : ( $body['message'] ?? 'HTTP ' . $code );
	set_transient( 'kc_sr_login_failed', 1, 5 * MINUTE_IN_SECONDS );
	kc_sr_status( 'Shiprocket login failed: ' . $why . ' (check the API user email and password).' );
	kc_sr_log( 'error', 'Login failed: ' . $why );
	return '';
}

/**
 * Courier price for one parcel.
 * Returns ['status' => 'ok', 'rate' => float, 'days' => string, 'courier' => string],
 * ['status' => 'unserviceable'] or ['status' => 'error'].
 */
function kc_sr_quote( array $opt, $pickup, $dest, $weight, $value ) {
	$key    = 'kc_sr_q_' . md5( implode( '|', array( $pickup, $dest, $weight, $opt['pick'] ) ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$args = array(
		'pickup_postcode'   => $pickup,
		'delivery_postcode' => $dest,
		'weight'            => $weight,
		'cod'               => 0,
		'declared_value'    => (int) ceil( $value ),
	);
	$res = null;
	for ( $try = 0; $try < 2; $try++ ) {
		$token = kc_sr_token( $opt['email'], $opt['password'], $try > 0 );
		if ( '' === $token ) {
			return array( 'status' => 'error' );
		}
		$res  = wp_remote_get(
			add_query_arg( $args, KC_SR_API . 'courier/serviceability/' ),
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 401 !== $code ) {
			break; // 401: token expired, log in again once
		}
		delete_transient( KC_SR_TOKEN_KEY );
	}

	$body  = is_wp_error( $res ) ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
	$where = $pickup . ' -> ' . $dest . ', ' . $weight . ' kg';
	$list  = $body['data']['available_courier_companies'] ?? array();

	if ( 200 === $code && $list ) {
		$best = null;
		if ( 'recommended' === $opt['pick'] ) {
			$want = $body['data']['shiprocket_recommended_courier_id'] ?? ( $body['data']['recommended_courier_company_id'] ?? 0 );
			foreach ( $list as $c ) {
				if ( (int) ( $c['courier_company_id'] ?? -1 ) === (int) $want ) {
					$best = $c;
					break;
				}
			}
		}
		if ( ! $best ) {
			foreach ( $list as $c ) {
				if ( ! $best || kc_sr_price( $c ) < kc_sr_price( $best ) ) {
					$best = $c;
				}
			}
		}
		$out = array(
			'status'  => 'ok',
			'rate'    => kc_sr_price( $best ),
			'days'    => preg_replace( '/[^\d\-]/', '', (string) ( $best['estimated_delivery_days'] ?? '' ) ),
			'courier' => (string) ( $best['courier_name'] ?? '' ),
		);
		if ( $out['rate'] > 0 ) {
			set_transient( $key, $out, KC_SR_QUOTE_TTL );
			kc_sr_status( 'Working. Last lookup ' . $where . ': ' . $out['courier'] . ' Rs ' . $out['rate'] . '.' );
			if ( $opt['debug'] ) {
				kc_sr_log( 'info', $where . ': ' . $out['courier'] . ' Rs ' . $out['rate'] . ' (' . count( $list ) . ' couriers)' );
			}
			return $out;
		}
	}

	if ( in_array( $code, array( 200, 404, 422 ), true ) && ! $list ) {
		// Shiprocket answered but no courier delivers there.
		$out = array( 'status' => 'unserviceable' );
		set_transient( $key, $out, KC_SR_QUOTE_TTL );
		kc_sr_status( 'Connected. Last lookup ' . $where . ': no courier delivers there (' . ( ( $body['message'] ?? '' ) ?: 'no couriers' ) . ').' );
		kc_sr_log( 'notice', $where . ': not serviceable - ' . ( $body['message'] ?? 'no couriers' ) );
		return $out;
	}

	$why = is_wp_error( $res ) ? $res->get_error_message() : ( ( $body['message'] ?? '' ) ?: 'HTTP ' . $code );
	kc_sr_status( 'Shiprocket rate lookup failed (' . $where . '): ' . $why . '. Using the fallback charge.' );
	kc_sr_log( 'error', 'Rate lookup failed ' . $where . ': ' . $why );
	return array( 'status' => 'error' );
}

function kc_sr_price( $courier ) {
	foreach ( array( 'rate', 'freight_charge' ) as $f ) {
		if ( isset( $courier[ $f ] ) && is_numeric( $courier[ $f ] ) && (float) $courier[ $f ] > 0 ) {
			return round( (float) $courier[ $f ], 2 );
		}
	}
	return 0.0;
}
