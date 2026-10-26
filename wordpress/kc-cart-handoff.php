<?php
/**
 * Plugin Name: Kitchen Corner - cart handoff
 * Description: Receives the cart from the mykitchencorner.in storefront, fills the WooCommerce cart and opens checkout. Sends the store's own shop/product pages back to the storefront.
 * Version:     1.6.0
 *
 * Install: upload to wp-content/mu-plugins/ on the WordPress site
 * (create the folder if it does not exist). Must-use plugins load
 * automatically - nothing to activate.
 *
 * The storefront sends shoppers to
 *   https://<wp-site>/wp-admin/admin-post.php?action=kc_cart&items=101:2,104:1
 * (product id : quantity, comma separated). admin-post.php is never served
 * from LiteSpeed / CDN page cache, so every visit reaches this code. Prices,
 * stock and totals are always recalculated by WooCommerce, never taken
 * from the URL.
 */

defined( 'ABSPATH' ) || exit;

// Hostinger's CDN ends HTTPS before WordPress, so PHP sees plain HTTP and
// WooCommerce ignores REST API keys (it only accepts them over HTTPS) - the
// "Authentication error" Shiprocket reports. Trust the proxy's HTTPS header.
if ( empty( $_SERVER['HTTPS'] ) || 'off' === strtolower( (string) $_SERVER['HTTPS'] ) ) {
	$kc_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( trim( strtok( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'], ',' ) ) ) : '';
	$kc_ssl   = isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ? strtolower( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) : '';
	if ( 'https' === $kc_proto || 'on' === $kc_ssl ) {
		$_SERVER['HTTPS'] = 'on';
	}
	unset( $kc_proto, $kc_ssl );
}

// The customer-facing site.
const KC_STOREFRONT_URL = 'https://mykitchencorner.in';

function kc_cart_handoff() {
	if ( ! function_exists( 'wc_load_cart' ) ) {
		wp_safe_redirect( KC_STOREFRONT_URL . '/products/' );
		exit;
	}

	// admin-post.php runs in admin context, where WooCommerce doesn't load the cart itself.
	wc_load_cart();

	$raw   = isset( $_GET['items'] ) ? sanitize_text_field( wp_unslash( $_GET['items'] ) ) : '';
	$pairs = array_slice( array_filter( explode( ',', $raw ) ), 0, 50 );

	WC()->cart->empty_cart();

	foreach ( $pairs as $pair ) {
		$parts = explode( ':', $pair );
		$id    = absint( $parts[0] ?? 0 );
		$qty   = min( 99, max( 1, absint( $parts[1] ?? 1 ) ) );
		if ( $id ) {
			WC()->cart->add_to_cart( $id, $qty );
		}
	}

	nocache_headers();

	// Stock or availability problems are shown on the checkout page as notices.
	wp_safe_redirect( WC()->cart->is_empty() ? KC_STOREFRONT_URL . '/products/' : wc_get_checkout_url() );
	exit;
}
add_action( 'admin_post_nopriv_kc_cart', 'kc_cart_handoff' );
add_action( 'admin_post_kc_cart', 'kc_cart_handoff' );

// The Store API only answers browser requests (CORS) from allowed origins.
add_filter( 'allowed_http_origins', function ( $origins ) {
	$origins[] = KC_STOREFRONT_URL;
	$origins[] = 'https://www.mykitchencorner.in';
	return $origins;
} );

// Local development servers may read the Store API too, nothing else.
add_filter( 'allowed_http_origin', function ( $origin, $origin_arg ) {
	$dev = (bool) preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', (string) $origin_arg );
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( ! $origin && $dev && false !== strpos( $uri, '/wc/store/' ) ) {
		return $origin_arg;
	}
	return $origin;
}, 10, 2 );

// Redirects to the storefront must be allowed by wp_safe_redirect().
add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
	$hosts[] = wp_parse_url( KC_STOREFRONT_URL, PHP_URL_HOST );
	return $hosts;
} );

// Shoppers browse on the storefront. The WordPress site only keeps checkout,
// cart, account and wp-admin, so Google never sees two copies of a product.
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
} );

// "Return to shop" links (empty cart, etc.) go back to the storefront.
add_filter( 'woocommerce_return_to_shop_redirect', function () {
	return KC_STOREFRONT_URL . '/products/';
} );

/*
 * Shopify-style checkout.
 * WooCommerce has to create the order before the shopper pays - the payment
 * gateway charges against it. Until it is paid it is treated as a checkout,
 * not an order:
 * - unpaid attempts are kept out of Orders > All, under "Abandoned checkout";
 * - a shopper who comes back without paying (cancelled, failed, closed the
 *   payment page) lands on checkout again with the cart refilled;
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

// Remember which order this browser placed, so only its shopper gets the cart back.
function kc_remember_checkout_order( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( $order ) {
		wc_setcookie( KC_CHECKOUT_COOKIE, $order->get_id() . ':' . $order->get_order_key(), time() + DAY_IN_SECONDS, true, true );
	}
}
add_action( 'woocommerce_store_api_checkout_order_processed', 'kc_remember_checkout_order' );
add_action( 'woocommerce_checkout_order_processed', 'kc_remember_checkout_order' );

function kc_is_shoppers_order( WC_Order $order ) {
	$raw = isset( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ) : '';
	list( $id, $key ) = array_pad( explode( ':', $raw, 2 ), 2, '' );
	return absint( $id ) === $order->get_id() && '' !== $key && hash_equals( $order->get_order_key(), $key );
}

function kc_refill_cart( WC_Order $order ) {
	if ( ! WC()->cart ) {
		return;
	}
	WC()->cart->empty_cart();
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( $product && $product->is_purchasable() ) {
			WC()->cart->add_to_cart( $item->get_product_id(), $item->get_quantity(), $item->get_variation_id() );
		}
	}
}

/*
 * Where the shopper lands after the payment page.
 * - Paid: the storefront's thank-you page (order number, total, Shop more).
 * - Not paid (cancelled, failed, or the gateway would not start the payment):
 *   back to checkout with the cart refilled and one clear message.
 * PhonePe's cancel and failure links point at checkout with ?phonepe_response,
 * so those are matched to the shopper's order through the checkout cookie.
 * Runs late, after the gateway's own return handler has recorded the payment.
 */
function kc_cookie_order_id() {
	$raw = isset( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ) : '';
	return absint( strtok( $raw, ':' ) );
}

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

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	$order = kc_order_from_request();
	if ( ! $order ) {
		return;
	}

	if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
		// Only the confirmation link itself (it carries the order key) moves on.
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! is_wc_endpoint_url( 'order-received' ) || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( array(
			'order' => 'placed',
			'n'     => rawurlencode( $order->get_order_number() ),
			't'     => wc_format_decimal( $order->get_total(), 2 ),
		), KC_STOREFRONT_URL . '/order-confirmed/' ) );
		exit;
	}

	if ( ! kc_is_shoppers_order( $order ) ) {
		return;
	}
	kc_abandon( $order, 'Shopper returned without completing payment - moved to abandoned checkouts.' );
	kc_refill_cart( $order );
	wp_safe_redirect( add_query_arg( 'kc_payment', 'incomplete', wc_get_checkout_url() ) );
	exit;
}, 100 );

// Message at the top of checkout after an unfinished payment.
add_filter( 'render_block', function ( $html, $block ) {
	if ( 'woocommerce/checkout' !== $block['blockName'] || empty( $_GET['kc_payment'] ) ) {
		return $html;
	}
	$message = __( 'Your payment was not completed, so no order was placed. Your cart is saved - please try again.', 'kitchen-corner' );
	return '<div class="kc-payment-notice" role="alert" style="margin:0 0 24px;padding:14px 18px;border:1px solid #e0b4b4;border-radius:8px;background:#fdf3f3;color:#8a1f1f;font-size:15px;">'
		. esc_html( $message ) . '</div>' . $html;
}, 10, 2 );

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
