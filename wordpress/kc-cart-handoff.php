<?php
/**
 * Plugin Name: Kitchen Corner - cart handoff
 * Description: Receives the cart from the mykitchencorner.in storefront, fills the WooCommerce cart and opens checkout. Sends the store's own shop/product pages back to the storefront.
 * Version:     1.2.0
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

// After a successful order, offer a way back that also clears the storefront cart.
add_action( 'woocommerce_thankyou', function () {
	printf(
		'<p><a class="button" href="%s">%s</a></p>',
		esc_url( KC_STOREFRONT_URL . '/products/?order=placed' ),
		esc_html__( 'Continue shopping', 'kitchen-corner' )
	);
}, 20 );
