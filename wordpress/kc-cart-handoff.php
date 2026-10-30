<?php
/**
 * Plugin Name: Kitchen Corner - storefront bridge
 * Description: Connects the mykitchencorner.in storefront to WooCommerce (checkout through the Store API) and runs this site as a complete shop of its own, with its policy and contact pages.
 * Version:     2.1.0
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
 * The store is a complete shop of its own (PhonePe reviews it as the website
 * taking payments): its homepage lists the products, product pages, cart and
 * checkout all work here, and the policy and contact pages are published.
 * The storefront at mykitchencorner.in stays the main site, so search
 * engines are asked not to index the store - people browsing it still see
 * everything.
 */
add_filter( 'wp_robots', function ( $robots ) {
	if ( ! is_admin() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
} );

// Policy and contact links at the foot of every store page, whatever the theme.
add_action( 'wp_footer', function () {
	$links = array();
	foreach ( kc_store_pages() as $page ) {
		$found = get_page_by_path( $page['slug'] );
		if ( $found && 'publish' === $found->post_status ) {
			$links[] = '<a href="' . esc_url( get_permalink( $found ) ) . '">' . esc_html( $page['title'] ) . '</a>';
		}
	}
	if ( ! $links ) {
		return;
	}
	echo '<nav class="kc-policy-links" aria-label="Store policies" style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px 20px;padding:24px 16px;border-top:1px solid rgba(0,0,0,.08);font-size:15px;">'
		. implode( '', $links ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
		. '<span>&copy; ' . esc_html( gmdate( 'Y' ) ) . ' K C Food Products</span></nav>';
} );

/*
 * One-time store setup (runs once, then remembers it is done): publishes the
 * policy and contact pages if they don't exist yet, makes the Shop page the
 * homepage, and points WooCommerce's terms and WordPress's privacy settings
 * at the new pages so checkout links to them. Pages edited afterwards in
 * wp-admin are never overwritten.
 */
const KC_SETUP_VERSION = '1';

add_action( 'init', function () {
	if ( KC_SETUP_VERSION === get_option( 'kc_store_setup' ) || ! function_exists( 'wc_get_page_id' ) ) {
		return;
	}
	$ids = array();
	foreach ( kc_store_pages() as $page ) {
		$existing = get_page_by_path( $page['slug'] );
		if ( $existing ) {
			$ids[ $page['slug'] ] = $existing->ID;
			continue;
		}
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $page['title'],
			'post_name'    => $page['slug'],
			'post_content' => $page['content'],
		), true );
		if ( ! is_wp_error( $id ) ) {
			$ids[ $page['slug'] ] = $id;
		}
	}
	$shop = wc_get_page_id( 'shop' );
	if ( $shop > 0 ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $shop );
	}
	if ( ! empty( $ids['terms-conditions'] ) ) {
		update_option( 'woocommerce_terms_page_id', $ids['terms-conditions'] );
	}
	if ( ! empty( $ids['privacy-policy'] ) ) {
		update_option( 'wp_page_for_privacy_policy', $ids['privacy-policy'] );
	}
	update_option( 'kc_store_setup', KC_SETUP_VERSION );
}, 20 );

function kc_store_pages() {
	// Generated from the storefront policy pages (scratchpad/mkpages.py).
	return array(
		array(
			'slug'    => 'terms-conditions',
			'title'   => 'Terms & Conditions',
			'content' => <<<'KCHTML'
<p><em>Last Updated : 21-10-2026</em></p>
            <ol><li>This document is an electronic record in terms of the Information Technology Act, 2000 and rules there under as applicable and the amended provisions pertaining to electronic records in various statutes as amended by the Information Technology Act, 2000. This electronic record is generated by a computer system and does not require any physical or digital signatures.</li><li>This document is published in accordance with the provisions of Rule 3 (1) of the Information Technology (Intermediaries guidelines) Rules, 2011 that require publishing the rules and regulations, privacy policy and Terms of Use for access or usage of domain name <b>https://mykitchencorner.in</b> (&lsquo;Website&rsquo;), including the related mobile site and mobile application (hereinafter referred to as &lsquo;Platform&rsquo;).</li><li>The Platform is owned by <b>K C Food Products</b>, a company incorporated under the Companies Act, 1956 with its registered office at <b>Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</b> (hereinafter referred to as &lsquo;Platform Owner&rsquo;, &lsquo;we&rsquo;, &lsquo;us&rsquo;, &lsquo;our&rsquo;).</li><li>Your use of the Platform and services and tools are governed by the following terms and conditions (&ldquo;Terms of Use&rdquo;) as applicable to the Platform including the applicable policies which are incorporated herein by way of reference. If You transact on the Platform, You shall be subject to the policies that are applicable to the Platform for such transaction. By mere use of the Platform, You shall be contracting with the Platform Owner and these terms and conditions including the policies constitute Your binding obligations, with Platform Owner. These Terms of Use relate to your use of our website, goods (as applicable) or services (as applicable) (collectively, &lsquo;Services&rsquo;). Any terms and conditions proposed by You which are in addition to or which conflict with these Terms of Use are expressly rejected by the Platform Owner and shall be of no force or effect. These Terms of Use can be modified at any time without assigning any reason. It is your responsibility to periodically review these Terms of Use to stay informed of updates.</li><li>For the purpose of these Terms of Use, wherever the context so requires &lsquo;you&rsquo;, &lsquo;your&rsquo; or &lsquo;user&rsquo; shall mean any natural or legal person who has agreed to become a user/buyer on the Platform.</li><li>ACCESSING, BROWSING OR OTHERWISE USING THE PLATFORM INDICATES YOUR AGREEMENT TO ALL THE TERMS AND CONDITIONS UNDER THESE TERMS OF USE, SO PLEASE READ THE TERMS OF USE CAREFULLY BEFORE PROCEEDING.</li><li>The use of Platform and/or availing of our Services is subject to the following Terms of Use:<ol><li>To access and use the Services, you agree to provide true, accurate and complete information to us during and after registration, and you shall be responsible for all acts done through the use of your registered account on the Platform.</li><li>Neither we nor any third parties provide any warranty or guarantee as to the accuracy, timeliness, performance, completeness or suitability of the information and materials offered on this website or through the Services, for any specific purpose. You acknowledge that such information and materials may contain inaccuracies or errors and we expressly exclude liability for any such inaccuracies or errors to the fullest extent permitted by law.</li><li>Your use of our Services and the Platform is solely and entirely at your own risk and discretion for which we shall not be liable to you in any manner. You are required to independently assess and ensure that the Services meet your requirements.</li><li>The contents of the Platform and the Services are proprietary to us and are licensed to us. You will not have any authority to claim any intellectual property rights, title, or interest in its contents. The contents includes and is not limited to the design, layout, look and graphics.</li><li>You acknowledge that unauthorized use of the Platform and/or the Services may lead to action against you as per these Terms of Use and/or applicable laws.</li><li>You agree to pay us the charges associated with availing the Services.</li><li>You agree not to use the Platform and/or Services for any purpose that is unlawful, illegal or forbidden by these Terms, or Indian or local laws that might apply to you.</li><li>You agree and acknowledge that website and the Services may contain links to other third party websites. On accessing these links, you will be governed by the terms of use, privacy policy and such other policies of such third party websites. These links are provided for your convenience to provide further information.</li></ol></li><li>You understand that upon initiating a transaction for availing the Services you are entering into a legally binding and enforceable contract with the Platform Owner for the Services.</li><li>You shall indemnify and hold harmless Platform Owner, its affiliates, group companies (as applicable) and their respective officers, directors, agents, and employees, from any claim or demand, or actions including reasonable attorney&rsquo;s fees, made by any third party or penalty imposed due to or arising out of Your breach of this Terms of Use, Privacy Policy and other Policies, or Your violation of any law, rules or regulations or the rights (including infringement of intellectual property rights) of a third party.</li><li>Notwithstanding anything contained in these Terms of Use, the parties shall not be liable for any failure to perform an obligation under these Terms if performance is prevented or delayed by a force majeure event.</li><li>These Terms and any dispute or claim relating to it, or its enforceability, shall be governed by and construed in accordance with the laws of India.</li><li>All disputes arising out of or in connection with these Terms shall be subject to the exclusive jurisdiction of the courts in Rajkot, Gujarat.</li><li>All concerns or communications relating to these Terms must be communicated to us using the contact information provided on this website, listed below.</li></ol>
            <h2>Contact Us</h2>
            <p><b>K C Food Products</b><br>Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</p>
<ul>
<li>Email: <a href="mailto:info@mykitchencorner.in">info@mykitchencorner.in</a></li>
<li>Phone: <a href="tel:+919081271119">+91 90812 71119</a></li>
<li>Time: Monday &ndash; Friday (9:00 &ndash; 18:00)</li>
</ul>
KCHTML
		),
		array(
			'slug'    => 'privacy-policy',
			'title'   => 'Privacy Policy',
			'content' => <<<'KCHTML'
<p><em>Last Updated : 21-10-2026</em></p>
            <h2>Introduction</h2>
            <p>This Privacy Policy describes how <b>K C Food Products</b> and its affiliates (collectively &ldquo;K C Food Products, we, our, us&rdquo;) collect, use, share, protect or otherwise process your information/personal data through our website <b>https://mykitchencorner.in</b> (hereinafter referred to as <b>Platform</b>). Please note that you may be able to browse certain sections of the Platform without registering with us. We do not offer any product/service under this Platform outside India and your personal data will primarily be stored and processed in India. By visiting this Platform, providing your information or availing any product/service offered on the Platform, you expressly agree to be bound by the terms and conditions of this Privacy Policy, the Terms of Use and the applicable service/product terms and conditions, and agree to be governed by the laws of India including but not limited to the laws applicable to data protection and privacy. If you do not agree please do not use or access our Platform.</p>
            <h2>Collection</h2>
            <p>We collect your personal data when you use our Platform, services or otherwise interact with us during the course of our relationship, and related information provided from time to time. Some of the information that we may collect includes but is not limited to personal data/information provided to us during sign-up/registering or using our Platform such as name, date of birth, address, telephone/mobile number, email ID and/or any such information shared as proof of identity or address. Some of the sensitive personal data may be collected with your consent, such as your bank account or credit or debit card or other payment instrument information or biometric information such as your facial features or physiological information (in order to enable use of certain features when opted for, available on the Platform) etc., all of the above being in accordance with applicable law(s). You always have the option to not provide information, by choosing not to use a particular service or feature on the Platform. We may track your behaviour, preferences, and other information that you choose to provide on our Platform. This information is compiled and analysed on an aggregated basis. We will also collect your information related to your transactions on Platform and such third-party business partner platforms. When such a third-party business partner collects your personal data directly from you, you will be governed by their privacy policies. We shall not be responsible for the third-party business partner&rsquo;s privacy practices or the content of their privacy policies, and we request you to read their privacy policies prior to disclosing any information. If you receive an email, a call from a person/association claiming to be <b>K C Food Products</b> seeking any personal data like debit/credit card PIN, net-banking or mobile banking password, we request you to never provide such information. If you have already revealed such information, report it immediately to an appropriate law enforcement agency.</p>
            <h2>Usage</h2>
            <p>We use personal data to provide the services you request. To the extent we use your personal data to market to you, we will provide you the ability to opt-out of such uses. We use your personal data to assist sellers and business partners in handling and fulfilling orders; enhancing customer experience; to resolve disputes; troubleshoot problems; inform you about online and offline offers, products, services, and updates; customise your experience; detect and protect us against error, fraud and other criminal activity; enforce our terms and conditions; conduct marketing research, analysis and surveys; and as otherwise described to you at the time of collection of information. You understand that your access to these products/services may be affected in the event permission is not provided to us.</p>
            <h2>Sharing</h2>
            <p>We may share your personal data internally within our group entities, our other corporate entities, and affiliates to provide you access to the services and products offered by them. These entities and affiliates may market to you as a result of such sharing unless you explicitly opt-out. We may disclose personal data to third parties such as sellers, business partners, third party service providers including logistics partners, prepaid payment instrument issuers, third-party reward programs and other payment opted by you. These disclosure may be required for us to provide you access to our services and products offered to you, to comply with our legal obligations, to enforce our user agreement, to facilitate our marketing and advertising activities, to prevent, detect, mitigate, and investigate fraudulent or illegal activities related to our services. We may disclose personal and sensitive personal data to government agencies or other authorised law enforcement agencies if required to do so by law or in the good faith belief that such disclosure is reasonably necessary to respond to subpoenas, court orders, or other legal process. We may disclose personal data to law enforcement offices, third party rights owners, or others in the good faith belief that such disclosure is reasonably necessary to: enforce our Terms of Use or Privacy Policy; respond to claims that an advertisement, posting or other content violates the rights of a third party; or protect the rights, property or personal safety of our users or the general public.</p>
            <h2>Security Precautions</h2>
            <p>To protect your personal data from unauthorised access or disclosure, loss or misuse we adopt reasonable security practices and procedures. Once your information is in our possession or whenever you access your account information, we adhere to our security guidelines to protect it against unauthorised access and offer the use of a secure server. However, the transmission of information is not completely secure for reasons beyond our control. By using the Platform, the users accept the security implications of data transmission over the internet and the World Wide Web which cannot always be guaranteed as completely secure, and therefore, there would always remain certain inherent risks regarding use of the Platform. Users are responsible for ensuring the protection of login and password records for their account.</p>
            <h2>Data Deletion and Retention</h2>
            <p>You have an option to delete your account by visiting your profile and settings on our Platform; this action would result in you losing all information related to your account. You may also write to us at the contact information provided below to assist you with these requests. We may in event of any pending grievance, claims, pending shipments or any other services refuse or delay deletion of the account. Once the account is deleted, you will lose access to the account. We retain your personal data information for a period no longer than is required for the purpose for which it was collected or as required under any applicable law. However, we may retain data related to you if we believe it may be necessary to prevent fraud or future abuse or for other legitimate purposes. We may continue to retain your data in anonymised form for analytical and research purposes.</p>
            <h2>Your Rights</h2>
            <p>You may access, rectify, and update your personal data directly through the functionalities provided on the Platform.</p>
            <h2>Consent</h2>
            <p>By visiting our Platform or by providing your information, you consent to the collection, use, storage, disclosure and otherwise processing of your information on the Platform in accordance with this Privacy Policy. If you disclose to us any personal data relating to other people, you represent that you have the authority to do so and permit us to use the information in accordance with this Privacy Policy. You, while providing your personal data over the Platform or any partner platforms or establishments, consent to us (including our other corporate entities, affiliates, lending partners, technology partners, marketing channels, business partners and other third parties) to contact you through SMS, instant messaging apps, call and/or e-mail for the purposes specified in this Privacy Policy. You have an option to withdraw your consent that you have already provided by writing to the Grievance Officer at the contact information provided below. Please mention &ldquo;Withdrawal of consent for processing personal data&rdquo; in your subject line of your communication. We may verify such requests before acting on our request. However, please note that your withdrawal of consent will not be retrospective and will be in accordance with the Terms of Use, this Privacy Policy, and applicable laws. In the event you withdraw consent given to us under this Privacy Policy, we reserve the right to restrict or deny the provision of our services for which we consider such information to be necessary.</p>
            <h2>Changes to this Privacy Policy</h2>
            <p>Please check our Privacy Policy periodically for changes. We may update this Privacy Policy to reflect changes to our information practices. We may alert/notify you about the significant changes to the Privacy Policy, in the manner as may be required under applicable laws.</p>
            <h2>Grievance Officer &amp; Contact Us</h2>
            <p><b>K C Food Products</b><br>Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</p>
<ul>
<li>Email: <a href="mailto:info@mykitchencorner.in">info@mykitchencorner.in</a></li>
<li>Phone: <a href="tel:+919081271119">+91 90812 71119</a></li>
<li>Time: Monday &ndash; Friday (9:00 &ndash; 18:00)</li>
</ul>
KCHTML
		),
		array(
			'slug'    => 'refund-cancellation-policy',
			'title'   => 'Refund & Cancellation Policy',
			'content' => <<<'KCHTML'
<p><em>Last Updated : 21-10-2026</em></p>
          <p>This refund and cancellation policy outlines how you can cancel or seek a refund for a product/service that you have purchased through the Platform. Under this policy:</p>
            <ol><li>Cancellations will only be considered if the request is made within <b>1 day</b> of placing the order. However, cancellation requests may not be entertained if the orders have been communicated to the sellers/merchant(s) listed on the Platform and they have initiated the process of shipping them, or the product is out for delivery. In such an event, you may choose to reject the product at the doorstep.</li><li><b>K C Food Products</b> does not accept cancellation requests for perishable items like flowers, eatables, etc. However, the refund/replacement can be made if the user establishes that the quality of the product delivered is not good.</li><li>In case of receipt of damaged or defective items, please report to our customer service team. The request would be entertained once the seller/merchant listed on the Platform has checked and determined the same at its own end. This should be reported within <b>1 day</b> of receipt of products. In case you feel that the product received is not as shown on the site or as per your expectations, you must bring it to the notice of our customer service within <b>1 day</b> of receiving the product. The customer service team after looking into your complaint will take an appropriate decision.</li><li>In case of complaints regarding the products that come with a warranty from the manufacturers, please refer the issue to them.</li><li>In case of any refunds approved by <b>K C Food Products</b>, it will take <b>7 days</b> for the refund to be processed to you.</li></ol>
            <h2>Contact Us</h2>
            <p><b>K C Food Products</b><br>Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</p>
<ul>
<li>Email: <a href="mailto:info@mykitchencorner.in">info@mykitchencorner.in</a></li>
<li>Phone: <a href="tel:+919081271119">+91 90812 71119</a></li>
<li>Time: Monday &ndash; Friday (9:00 &ndash; 18:00)</li>
</ul>
KCHTML
		),
		array(
			'slug'    => 'return-policy',
			'title'   => 'Return Policy',
			'content' => <<<'KCHTML'
<p><em>Last Updated : 21-10-2026</em></p>
            <h2>Returns &amp; Exchanges</h2>
            <p>We offer refund/exchange within the first <b>1 day</b> from the date of your purchase. If 1 day has passed since your purchase, you will not be offered a return, exchange or refund of any kind. In order to become eligible for a return or an exchange, (i) the purchased item should be unused and in the same condition as you received it, (ii) the item must have original packaging, (iii) if the item that you purchased on a sale, then the item may not be eligible for a return/exchange. Further, only such items are replaced by us (based on an exchange request), if such items are found defective or damaged.</p>
            <h2>Exempted Items &amp; Process</h2>
            <p>You agree that there may be a certain category of products/items that are exempted from returns or refunds. Such categories of the products would be identified to you at the time of purchase. For exchange/return accepted request(s) (as applicable), once your returned product/item is received and inspected by us, we will send you an email to notify you about receipt of the returned/exchanged product. Further, if the same has been approved after the quality check at our end, your request (i.e. return/exchange) will be processed in accordance with our policies.</p>
            <h2>Contact Us</h2>
            <p><b>K C Food Products</b><br>Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</p>
<ul>
<li>Email: <a href="mailto:info@mykitchencorner.in">info@mykitchencorner.in</a></li>
<li>Phone: <a href="tel:+919081271119">+91 90812 71119</a></li>
<li>Time: Monday &ndash; Friday (9:00 &ndash; 18:00)</li>
</ul>
KCHTML
		),
		array(
			'slug'    => 'shipping-policy',
			'title'   => 'Shipping Policy',
			'content' => <<<'KCHTML'
<p><em>Last Updated : 21-10-2026</em></p>
            <h2>Shipping &amp; Delivery</h2>
            <p>The orders for the user are shipped through registered domestic courier companies and/or speed post only. Orders are shipped within <b>7 days</b> from the date of the order and/or payment or as per the delivery date agreed at the time of order confirmation and delivering of the shipment, subject to courier company/post office norms. Platform Owner shall not be liable for any delay in delivery by the courier company/postal authority. Delivery of all orders will be made to the address provided by the buyer at the time of purchase. Delivery of our services will be confirmed on your email ID as specified at the time of registration. If there are any shipping cost(s) levied by the seller or the Platform Owner (as the case be), the same is not refundable.</p>
            <h2>Contact Us</h2>
            <p><b>K C Food Products</b><br>Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</p>
<ul>
<li>Email: <a href="mailto:info@mykitchencorner.in">info@mykitchencorner.in</a></li>
<li>Phone: <a href="tel:+919081271119">+91 90812 71119</a></li>
<li>Time: Monday &ndash; Friday (9:00 &ndash; 18:00)</li>
</ul>
KCHTML
		),
		array(
			'slug'    => 'contact-us',
			'title'   => 'Contact Us',
			'content' => <<<'KCHTML'
<p>We'd love to hear from you. For questions about an order, our products, or anything else, reach us here:</p>
<p><strong>K C Food Products</strong> (Kitchen Corner)<br>
Office No 701, 7th Floor, Pride Empire, Race Course Road, Near Union Bank, Rajkot, Gujarat 360001</p>
<ul>
<li>Email: <a href="mailto:info@mykitchencorner.in">info@mykitchencorner.in</a></li>
<li>Phone: <a href="tel:+919081271119">+91 90812 71119</a></li>
<li>Hours: Monday &ndash; Friday, 9:00 &ndash; 18:00</li>
<li>Instagram: <a href="https://www.instagram.com/kitchencorner.in/">@kitchencorner.in</a></li>
</ul>
<p>You can also visit our main website: <a href="https://mykitchencorner.in/">mykitchencorner.in</a>.</p>
KCHTML
		),
	);
}

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
 * Who placed the order, and where.
 * Every shopper reaches "pay for order" with the order key in the link. That
 * visit (a normal page load on this site) leaves a cookie, so when they come
 * back from the payment page we know the order is theirs. Orders placed on
 * the storefront arrive with ?kc_src=storefront; that is saved on the order
 * so their shoppers return to the storefront, while orders placed on this
 * site keep WooCommerce's own pages.
 */
const KC_SOURCE_META = '_kc_source';

function kc_is_shoppers_order( WC_Order $order ) {
	$raw = isset( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ) : '';
	list( $id, $key ) = array_pad( explode( ':', $raw, 2 ), 2, '' );
	return absint( $id ) === $order->get_id() && '' !== $key && hash_equals( $order->get_order_key(), $key );
}

function kc_cookie_order_id() {
	$raw = isset( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ KC_CHECKOUT_COOKIE ] ) ) : '';
	return absint( strtok( $raw, ':' ) );
}

function kc_from_storefront( WC_Order $order ) {
	return 'storefront' === $order->get_meta( KC_SOURCE_META );
}

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
	$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
		return;
	}
	wc_setcookie( KC_CHECKOUT_COOKIE, $order->get_id() . ':' . $order->get_order_key(), time() + DAY_IN_SECONDS, true, true );
	if ( isset( $_GET['kc_src'] ) && 'storefront' === $_GET['kc_src'] && ! kc_from_storefront( $order ) ) {
		$order->update_meta_data( KC_SOURCE_META, 'storefront' );
		$order->save();
	}
}, 5 );

/*
 * Where the shopper lands after the payment page.
 * Storefront orders:
 * - paid: the storefront's thank-you page (order number, total, Shop more);
 * - not paid (cancelled, failed, or the gateway would not start the payment):
 *   back to the storefront checkout with a clear message - the storefront
 *   still holds the cart.
 * Orders placed on this site: WooCommerce's own thank-you page; if not paid,
 * back to this site's checkout (its cart is still there) with the message,
 * instead of the order page that asks guests to log in.
 * PhonePe's cancel and failure links point at the checkout or order pages
 * with ?phonepe_response.
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

function kc_store_checkout_retry_url() {
	return add_query_arg( array(
		'phonepe_response' => rawurlencode( __( 'Your payment was not completed, so no order was placed. Please try again.', 'kitchen-corner' ) ),
		'type'             => 'error',
	), wc_get_checkout_url() );
}

// Storefront orders coming back to this site's checkout: send them home
// before WooCommerce's own "empty cart" redirect (priority 10) runs, which
// would drop the message.
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! isset( $_GET['phonepe_response'] ) ) {
		return;
	}
	if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}
	$id    = kc_cookie_order_id();
	$order = $id ? wc_get_order( $id ) : false;
	if ( ! $order || ! kc_from_storefront( $order ) || $order->is_paid() || $order->has_status( 'on-hold' ) ) {
		return;
	}
	if ( kc_is_shoppers_order( $order ) ) {
		kc_abandon( $order, 'Payment cancelled or failed - moved to abandoned checkouts.' );
	}
	wp_safe_redirect( add_query_arg( 'payment', 'failed', KC_STOREFRONT_CHECKOUT ) );
	exit;
}, 1 );

// Runs late, after the gateway's own return handler has recorded the payment.
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	$order = kc_order_from_request();
	if ( ! $order ) {
		return;
	}
	$storefront = kc_from_storefront( $order );

	if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
		// Only the confirmation link itself (it carries the order key) moves on.
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $storefront && is_wc_endpoint_url( 'order-received' ) && '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( add_query_arg( array(
				'order' => 'placed',
				'n'     => rawurlencode( $order->get_order_number() ),
				't'     => wc_format_decimal( $order->get_total(), 2 ),
			), KC_STOREFRONT_URL . '/order-confirmed/' ) );
			exit;
		}
		return;
	}

	$mine = kc_is_shoppers_order( $order );
	if ( $mine ) {
		kc_abandon( $order, 'Shopper returned without completing payment - moved to abandoned checkouts.' );
	}
	// Nothing about the order is shown or changed for anyone else; at most
	// they are sent back to a checkout page.
	if ( $storefront && ( $mine || isset( $_GET['phonepe_response'] ) ) ) {
		wp_safe_redirect( add_query_arg( 'payment', 'failed', KC_STOREFRONT_CHECKOUT ) );
		exit;
	}
	if ( ! $storefront && $mine && ! is_checkout() ) {
		wp_safe_redirect( kc_store_checkout_retry_url() );
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
