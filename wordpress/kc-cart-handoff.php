<?php
/**
 * Plugin Name: Kitchen Corner - storefront bridge
 * Description: Connects the mykitchencorner.in storefront to WooCommerce: checkout runs on the storefront through the Store API, this site only takes payments and records orders.
 * Version:     2.0.1
 *
 * Install: upload to wp-content/mu-plugins/ on the WordPress site
 * (create the folder if it does not exist). Must-use plugins load
 * automatically - nothing to activate.
 *
 * Flow: the storefront's /checkout/ page builds the WooCommerce cart through
 * the Store API (authorised by a signed Cart-Token, no cookies), places the
 * order there, then sends the shopper to this site's "pay for order" page,
 * which hands over to PhonePe. Prices, stock and totals are always worked
 * out here by WooCommerce, never taken from the browser.
 */

defined( 'ABSPATH' ) || exit;

// The customer-facing site.
const KC_STOREFRONT_URL      = 'https://mykitchencorner.in';
const KC_STOREFRONT_CHECKOUT = KC_STOREFRONT_URL . '/checkout/';

// Hostinger's CDN ends HTTPS before WordPress, so PHP sees plain HTTP and
// WooCommerce ignores REST API keys (it only accepts them over HTTPS). The CDN
// redirects every http:// request to https:// before it reaches WordPress, so
// when the site address is https:// every request that gets here came in
// over HTTPS.
if ( empty( $_SERVER['HTTPS'] ) || 'off' === strtolower( (string) $_SERVER['HTTPS'] ) ) {
	$kc_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( trim( strtok( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'], ',' ) ) ) : '';
	if ( 'https' === $kc_proto || 0 === strpos( (string) get_option( 'siteurl' ), 'https://' ) ) {
		$_SERVER['HTTPS'] = 'on';
	}
	unset( $kc_proto );
}

/*
 * Cross-origin access.
 * Only the storefront may call the Store API from a browser. Local
 * development servers may read and use the Store API too, nothing else.
 */
add_filter( 'allowed_http_origins', function ( $origins ) {
	$origins[] = KC_STOREFRONT_URL;
	$origins[] = 'https://www.mykitchencorner.in';
	return $origins;
} );

add_filter( 'allowed_http_origin', function ( $origin, $origin_arg ) {
	$dev = (bool) preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', (string) $origin_arg );
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( ! $origin && $dev && ( false !== strpos( $uri, '/wc/store/' ) || false !== strpos( $uri, '/kc/v1/' ) ) ) {
		return $origin_arg;
	}
	return $origin;
}, 10, 2 );

// Redirects to the storefront must be allowed by wp_safe_redirect().
add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
	$hosts[] = wp_parse_url( KC_STOREFRONT_URL, PHP_URL_HOST );
	return $hosts;
} );

/*
 * Rate limit placing orders (bots, card testing): 10 attempts a minute per
 * visitor, on the checkout endpoint only, so browsing is never throttled.
 */
add_filter( 'woocommerce_store_api_rate_limit_options', function ( $options ) {
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
	if ( preg_match( '#/wc/store(?:/v\d+)?/checkout#', $route ) ) {
		$options                  = (array) $options;
		$options['enabled']       = true;
		$options['proxy_support'] = true;
		$options['limit']         = 10;
		$options['seconds']       = 60;
	}
	return $options;
} );

/*
 * Payment method names for the storefront checkout (the Store API lists only
 * ids). Titles and descriptions stay managed in WooCommerce > Payments.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'kc/v1', '/payment-methods', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$out = array();
			if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
				foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
					if ( 'yes' === $gateway->enabled ) {
						$out[] = array(
							'id'          => (string) $id,
							'title'       => wp_strip_all_tags( (string) $gateway->get_title() ),
							'description' => wp_strip_all_tags( (string) $gateway->get_description() ),
						);
					}
				}
			}
			$response = rest_ensure_response( $out );
			$response->header( 'Cache-Control', 'public, max-age=300' );
			return $response;
		},
	) );
} );

/*
 * Shoppers browse and check out on the storefront. This site keeps only the
 * payment steps (pay for order, order received), account pages and wp-admin,
 * so Google never sees two copies of a product and nobody lands on a second
 * checkout.
 */
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_woocommerce' ) || is_admin() || wp_doing_ajax() ) {
		return;
	}
	if ( is_product() ) {
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		wp_redirect( KC_STOREFRONT_URL . '/product/' . rawurlencode( $slug ) . '/', 301 );
		exit;
	}
	if ( is_shop() || is_product_taxonomy() || is_front_page() || is_home() ) {
		wp_redirect( KC_STOREFRONT_URL . '/products/', 301 );
		exit;
	}
	$payment_step = is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' );
	if ( ( is_cart() || is_checkout() ) && ! $payment_step && ! isset( $_GET['phonepe_response'] ) ) {
		wp_redirect( KC_STOREFRONT_CHECKOUT, 302 );
		exit;
	}
}, 5 ); // before WooCommerce's own "empty cart" redirect (priority 10)

// "Return to shop" links (empty cart, etc.) go back to the storefront.
add_filter( 'woocommerce_return_to_shop_redirect', function () {
	return KC_STOREFRONT_URL . '/products/';
} );

/*
 * Shopify-style orders.
 * WooCommerce has to create the order before the shopper pays - the payment
 * gateway charges against it. Until it is paid it is treated as a checkout,
 * not an order:
 * - unpaid attempts are kept out of Orders > All, under "Abandoned checkout";
 * - a payment confirmed late still completes the order and sends the emails.
 */
const KC_ABANDONED       = 'abandoned'; // stored as wc-abandoned
const KC_CHECKOUT_COOKIE = 'kc_checkout_order';
const KC_UNPAID_MINUTES  = 30;

add_filter( 'woocommerce_register_shop_order_post_statuses', function ( $statuses ) {
	foreach ( array( 'wc-pending', 'wc-failed' ) as $status ) {
		if ( isset( $statuses[ $status ] ) ) {
			$statuses[ $status ]['show_in_admin_all_list'] = false;
		}
	}
	$statuses[ 'wc-' . KC_ABANDONED ] = array(
		'label'                     => _x( 'Abandoned checkout', 'Order status', 'kitchen-corner' ),
		'public'                    => false,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => false,
		'show_in_admin_status_list' => true,
		/* translators: %s: number of orders */
		'label_count'               => _n_noop( 'Abandoned checkout <span class="count">(%s)</span>', 'Abandoned checkouts <span class="count">(%s)</span>', 'kitchen-corner' ),
	);
	return $statuses;
} );

add_filter( 'wc_order_statuses', function ( $statuses ) {
	$statuses[ 'wc-' . KC_ABANDONED ] = _x( 'Abandoned checkout', 'Order status', 'kitchen-corner' );
	return $statuses;
} );

// A payment confirmed after the checkout was abandoned still completes it.
add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', function ( $statuses ) {
	$statuses[] = KC_ABANDONED;
	return $statuses;
} );
add_filter( 'woocommerce_valid_order_statuses_for_payment', function ( $statuses ) {
	$statuses[] = KC_ABANDONED;
	return $statuses;
} );

// WooCommerce only emails on pending/failed -> paid; cover abandoned -> paid too.
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to ) {
	if ( KC_ABANDONED !== $from || ! in_array( $to, array( 'processing', 'on-hold' ), true ) ) {
		return;
	}
	$emails   = WC()->mailer()->get_emails();
	$customer = 'processing' === $to ? 'WC_Email_Customer_Processing_Order' : 'WC_Email_Customer_On_Hold_Order';
	foreach ( array( 'WC_Email_New_Order', $customer ) as $class ) {
		if ( isset( $emails[ $class ] ) ) {
			$emails[ $class ]->trigger( $order_id );
		}
	}
}, 10, 3 );

// Moves an unpaid checkout order out of the order list.
function kc_abandon( WC_Order $order, $note ) {
	if ( $order->is_paid() || $order->get_date_paid() ) {
		return;
	}
	if ( ! in_array( $order->get_created_via(), array( 'checkout', 'store-api' ), true ) ) {
		return; // orders created by hand in wp-admin are left alone
	}
	if ( $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
		$order->update_status( KC_ABANDONED, $note );
	}
}

// The gateway cancelling an unpaid order is an abandoned checkout, not an order.
add_action( 'woocommerce_order_status_cancelled', function ( $order_id, $order ) {
	kc_abandon( $order, 'Payment cancelled - moved to abandoned checkouts.' );
}, 20, 2 );

/*
 * Who placed the order.
 * The storefront reaches "pay for order" with the order key in the link. That
 * first visit (a normal page load on this site) leaves a cookie, so when the
 * shopper comes back from the payment page we know the order is theirs.
 */
function kc_is_shoppers_order( WC_Order $order ) {
	$raw = isset( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ) : '';
	list( $id, $key ) = array_pad( explode( ':', $raw, 2 ), 2, '' );
	return absint( $id ) === $order->get_id() && '' !== $key && hash_equals( $order->get_order_key(), $key );
}

function kc_cookie_order_id() {
	$raw = isset( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ) : '';
	return absint( strtok( $raw, ':' ) );
}

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
	$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
	if ( $order && '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
		wc_setcookie( KC_CHECKOUT_COOKIE, $order->get_id() . ':' . $order->get_order_key(), time() + DAY_IN_SECONDS, true, true );
	}
}, 5 );

/*
 * Where the shopper lands after the payment page.
 * - Paid: the storefront's thank-you page (order number, total, Shop more).
 * - Not paid (cancelled, failed, or the gateway would not start the payment):
 *   back to the storefront checkout with a clear message; the storefront still
 *   holds the cart. PhonePe's cancel and failure links point at this site's
 *   checkout or order pages with ?phonepe_response.
 * Runs late, after the gateway's own return handler has recorded the payment.
 */
function kc_order_from_request() {
	if ( is_wc_endpoint_url( 'order-received' ) ) {
		return wc_get_order( absint( get_query_var( 'order-received' ) ) );
	}
	if ( is_wc_endpoint_url( 'view-order' ) ) {
		return wc_get_order( absint( get_query_var( 'view-order' ) ) );
	}
	if ( is_checkout() && isset( $_GET['phonepe_response'] ) ) {
		$id = kc_cookie_order_id();
		return $id ? wc_get_order( $id ) : false;
	}
	return false;
}

// PhonePe's cancel / failure links land on this site's checkout. The cart
// here is empty (it lives on the storefront), so WooCommerce would bounce the
// shopper to the cart page first and the message would be lost: handle these
// before WooCommerce does (priority 1). Successful payments return through
// PhonePe's own API callback and the order-received page, never this link.
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! isset( $_GET['phonepe_response'] ) ) {
		return;
	}
	if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}
	$id    = kc_cookie_order_id();
	$order = $id ? wc_get_order( $id ) : false;
	if ( $order && ( $order->is_paid() || $order->has_status( 'on-hold' ) ) ) {
		return;
	}
	if ( $order && kc_is_shoppers_order( $order ) ) {
		kc_abandon( $order, 'Payment cancelled or failed - moved to abandoned checkouts.' );
	}
	wp_safe_redirect( add_query_arg( 'payment', 'failed', KC_STOREFRONT_CHECKOUT ) );
	exit;
}, 1 );

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	$from_gateway = isset( $_GET['phonepe_response'] );
	$order        = kc_order_from_request();

	if ( $order && ( $order->is_paid() || $order->has_status( 'on-hold' ) ) ) {
		// Only the confirmation link itself (it carries the order key) moves on.
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( is_wc_endpoint_url( 'order-received' ) && '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( add_query_arg( array(
				'order' => 'placed',
				'n'     => rawurlencode( $order->get_order_number() ),
				't'     => wc_format_decimal( $order->get_total(), 2 ),
			), KC_STOREFRONT_URL . '/order-confirmed/' ) );
			exit;
		}
		return;
	}

	$mine = $order && kc_is_shoppers_order( $order );
	if ( $mine ) {
		kc_abandon( $order, 'Shopper returned without completing payment - moved to abandoned checkouts.' );
	}
	// Nothing about the order is shown or changed for anyone else; they are
	// only sent back to the storefront checkout.
	if ( $mine || $from_gateway ) {
		wp_safe_redirect( add_query_arg( 'payment', 'failed', KC_STOREFRONT_CHECKOUT ) );
		exit;
	}
}, 100 );

// Every 15 minutes: unpaid checkouts older than 30 minutes, and unpaid ones
// the gateway cancelled, move to abandoned checkouts.
add_action( 'init', function () {
	if ( ! function_exists( 'as_has_scheduled_action' ) ) {
		return;
	}
	if ( as_has_scheduled_action( 'kc_cancel_unpaid_orders' ) ) {
		as_unschedule_all_actions( 'kc_cancel_unpaid_orders' ); // replaced by the job below (v1.3.0)
	}
	if ( ! as_has_scheduled_action( 'kc_abandon_unpaid_orders' ) ) {
		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, 'kc_abandon_unpaid_orders', array(), 'kitchen-corner' );
	}
} );

add_action( 'kc_abandon_unpaid_orders', function () {
	$stale     = wc_get_orders( array(
		'status'       => array( 'pending', 'failed' ),
		'date_created' => '<' . ( time() - KC_UNPAID_MINUTES * MINUTE_IN_SECONDS ),
		'limit'        => 100,
	) );
	$cancelled = wc_get_orders( array(
		'status'       => 'cancelled',
		'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
		'limit'        => 100,
	) );
	foreach ( array_merge( $stale, $cancelled ) as $order ) {
		kc_abandon( $order, 'Not paid - moved to abandoned checkouts.' );
	}
} );
