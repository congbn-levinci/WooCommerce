<?php
// wc-enhanced.php (Updated with all optimizations: nonce, configurable COD fee, REST security, sale price support, quantity check, version 1.3.0)

declare( strict_types=1 );

/*
Plugin Name: WooCommerce Enhanced Features
Description: Dynamic pack pricing, COD fees, and custom REST API endpoints
Version: 1.3.0
Author: Bui Nam Cong
Requires at least: 5.0
Requires PHP: 8.1
WC tested up to: 8.5
Text Domain: wc-enhanced
Domain Path: /languages
*/

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants
const WC_ENHANCED_VERSION       = '1.3.0';
const WC_ENHANCED_TEXT_DOMAIN   = 'wc-enhanced';
const WC_ENHANCED_OPTION_PREFIX = 'wc_enhanced_';
const WC_ENHANCED_API_NAMESPACE = 'mystore/v1';
define( 'WC_ENHANCED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_ENHANCED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin bootstrap class
 */
class WC_Enhanced_Features_Plugin {
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

		// Add settings for COD fee
		add_filter( 'woocommerce_get_sections_general', [ $this, 'addCodSection' ] );
		add_filter( 'woocommerce_get_settings_general', [ $this, 'addCodSettings' ] );
	}

	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'wooCommerceRequiredNotice' ] );

			return;
		}

		load_plugin_textdomain( WC_ENHANCED_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Initialize features as separate classes
		new WC_Enhanced_Dynamic_Pricing();
		new WC_Enhanced_Cod_Fees();
		new WC_Enhanced_Rest_Api();
		new WC_Enhanced_Admin_Interface();
	}

	public function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'WooCommerce Enhanced Features requires WooCommerce to be installed and activated.', WC_ENHANCED_TEXT_DOMAIN ),
				esc_html__( 'Plugin Activation Error', WC_ENHANCED_TEXT_DOMAIN ),
				[ 'back_link' => true ]
			);
		}
		flush_rewrite_rules();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}

	public function wooCommerceRequiredNotice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					esc_html__( '%1$s requires %2$s to be installed and activated.', WC_ENHANCED_TEXT_DOMAIN ),
					'<strong>WooCommerce Enhanced Features</strong>',
					'<strong>WooCommerce</strong>'
				)
			)
		);
	}

	public function addCodSection( $sections ) {
		$sections['wc_enhanced_cod'] = __( 'Enhanced COD', WC_ENHANCED_TEXT_DOMAIN );

		return $sections;
	}

	public function addCodSettings( $settings ): array {
		$current_section = $_GET['section'] ?? '';
		if ( $current_section === 'wc_enhanced_cod' ) {
			$settings   = [];
			$settings[] = [
				'title' => __( 'COD Fee', WC_ENHANCED_TEXT_DOMAIN ),
				'type'  => 'title',
				'id'    => 'wc_enhanced_cod_title',
			];
			$settings[] = [
				'title'   => __( 'Fee Amount (VND)', WC_ENHANCED_TEXT_DOMAIN ),
				'id'      => WC_ENHANCED_OPTION_PREFIX . 'cod_fee',
				'type'    => 'number',
				'default' => 30000,
				'desc'    => __( 'Enter the COD processing fee.', WC_ENHANCED_TEXT_DOMAIN ),
			];
			$settings[] = [ 'type' => 'sectionend', 'id' => 'wc_enhanced_cod' ];
		}

		return $settings;
	}
}

/**
 * Dynamic Pricing Feature
 */
class WC_Enhanced_Dynamic_Pricing {
	public function __construct() {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'updateCartItemPrices' ] );
		add_action( 'woocommerce_product_options_pricing', [ $this, 'addPackPricingFields' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'savePackPricingFields' ] );
		add_action( 'woocommerce_single_product_summary', [ $this, 'displayPricingTable' ], 25 );
		add_action( 'woocommerce_cart_item_name', [ $this, 'displayCartPricingBreakdown' ], 10, 3 );

		// Enqueue admin assets only on product edit screen
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdminAssets' ] );
	}

	public function enqueueAdminAssets( $hook ): void {
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		global $post;
		if ( $post && $post->post_type !== 'product' ) {
			return;
		}

		wp_enqueue_style( 'wc-enhanced-admin', WC_ENHANCED_PLUGIN_URL . 'assets/css/admin.css', [], WC_ENHANCED_VERSION );
		wp_enqueue_script( 'wc-enhanced-admin', WC_ENHANCED_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], WC_ENHANCED_VERSION, true );
		wp_localize_script( 'wc-enhanced-admin', 'wcEnhanced', [
			'packSizeLabel'  => __( 'Pack Size:', WC_ENHANCED_TEXT_DOMAIN ),
			'packPriceLabel' => __( 'Pack Price:', WC_ENHANCED_TEXT_DOMAIN ),
			'removeLabel'    => __( 'Remove', WC_ENHANCED_TEXT_DOMAIN ),
		] );
	}

	public function updateCartItemPrices( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product instanceof WC_Product || ! $this->shouldApplyDynamicPricing( $product ) ) {
				continue;
			}

			$quantity = (int) $cart_item['quantity'];
			if ( $quantity < 2 ) {
				continue;
			}

			$tiers = $this->getProductPricingTiers( $product );
			if ( empty( $tiers ) ) {
				continue;
			}

			$breakdown    = $this->getPricingBreakdown( $quantity, $tiers, $product );
			$optimalTotal = array_reduce( $breakdown, fn( $sum, $item ) => $sum + $item['total'], 0.0 );
			$newUnitPrice = $optimalTotal / $quantity;

			if ( abs( (float) $product->get_price() - $newUnitPrice ) > 0.01 ) {
				$product->set_price( $newUnitPrice );
				WC()->cart->cart_contents[ $cart_item_key ]['pricing_breakdown'] = $breakdown;
			}
		}
	}

	private function shouldApplyDynamicPricing( WC_Product $product ): bool {
		return get_post_meta( $product->get_id(), WC_ENHANCED_OPTION_PREFIX . 'pricing_enabled', true ) === 'yes';
	}

	private function getProductPricingTiers( WC_Product $product ): array {
		$tierData = get_post_meta( $product->get_id(), WC_ENHANCED_OPTION_PREFIX . 'pricing_tiers', true );
		if ( ! is_array( $tierData ) || empty( $tierData ) ) {
			return [];
		}

		$tiers = array_filter( $tierData, fn( $tier ) => ! empty( $tier['quantity'] ) && ! empty( $tier['price'] ) );
		$tiers = array_map( fn( $tier ) => [
			'quantity' => (int) $tier['quantity'],
			'price'    => (float) $tier['price']
		], $tiers );
		usort( $tiers, fn( $a, $b ) => $a['quantity'] <=> $b['quantity'] );

		return $tiers;
	}

	private function getPricingBreakdown( int $quantity, array $tiers, WC_Product $product ): array {
		$basePrice = (float) $product->get_price( 'edit' ); // Support sale price
		$allTiers  = array_merge( [ [ 'quantity' => 1, 'price' => $basePrice ] ], $tiers );
		usort( $allTiers, fn( $a, $b ) => $b['quantity'] <=> $a['quantity'] );

		$remaining = $quantity;
		$breakdown = [];

		foreach ( $allTiers as $tier ) {
			$packSize = $tier['quantity'];
			if ( $packSize === 1 ) {
				if ( $remaining > 0 ) {
					$breakdown[] = [
						'type'       => 'individual',
						'quantity'   => $remaining,
						'unit_price' => $tier['price'],
						'total'      => $remaining * $tier['price'],
					];
				}
				break;
			}

			$packs = (int) ( $remaining / $packSize );
			if ( $packs > 0 ) {
				$breakdown[] = [
					'type'       => 'pack',
					'pack_size'  => $packSize,
					'pack_count' => $packs,
					'pack_price' => $tier['price'],
					'total'      => $packs * $tier['price'],
				];
				$remaining   -= $packs * $packSize;
			}
		}

		return $breakdown;
	}

	public function addPackPricingFields(): void {
		echo '<div class="options_group">';
		echo '<h3>' . esc_html__( 'Dynamic Pack Pricing', WC_ENHANCED_TEXT_DOMAIN ) . '</h3>';

		woocommerce_wp_checkbox( [
			'id'          => WC_ENHANCED_OPTION_PREFIX . 'pricing_enabled',
			'label'       => __( 'Enable Pack Pricing', WC_ENHANCED_TEXT_DOMAIN ),
			'description' => __( 'Enable dynamic pack pricing for this product', WC_ENHANCED_TEXT_DOMAIN ),
		] );

		global $post;
		$tiers = get_post_meta( $post->ID, WC_ENHANCED_OPTION_PREFIX . 'pricing_tiers', true ) ?: [];
		echo '<div id="pack-pricing-tiers">';
		echo '<h4>' . esc_html__( 'Pricing Tiers', WC_ENHANCED_TEXT_DOMAIN ) . '</h4>';

		foreach ( $tiers as $index => $tier ) {
			$quantity = esc_attr( $tier['quantity'] ?? '' );
			$price    = esc_attr( $tier['price'] ?? '' );
			echo "<div class='tier-row'>";
			echo "<label>" . esc_html__( 'Pack Size:', WC_ENHANCED_TEXT_DOMAIN ) . "</label>";
			echo "<input type='number' name='pack_tiers[{$index}][quantity]' value='{$quantity}' min='2' />";
			echo "<label>" . esc_html__( 'Pack Price:', WC_ENHANCED_TEXT_DOMAIN ) . "</label>";
			echo "<input type='number' step='0.01' name='pack_tiers[{$index}][price]' value='{$price}' min='0' />";
			echo "<button type='button' class='remove-tier button'>" . esc_html__( 'Remove', WC_ENHANCED_TEXT_DOMAIN ) . "</button>";
			echo "</div>";
		}

		echo '<button type="button" id="add-tier" class="button">' . esc_html__( 'Add Tier', WC_ENHANCED_TEXT_DOMAIN ) . '</button>';
		echo '</div>';
		wp_nonce_field( 'wc_enhanced_pricing_nonce', 'wc_enhanced_pricing_nonce' );
		echo '</div>';
	}

	public function savePackPricingFields( int $postId ): void {
		if ( ! isset( $_POST['wc_enhanced_pricing_nonce'] ) || ! wp_verify_nonce( $_POST['wc_enhanced_pricing_nonce'], 'wc_enhanced_pricing_nonce' ) ) {
			return;
		}

		$enabled = isset( $_POST[ WC_ENHANCED_OPTION_PREFIX . 'pricing_enabled' ] ) ? 'yes' : 'no';
		update_post_meta( $postId, WC_ENHANCED_OPTION_PREFIX . 'pricing_enabled', $enabled );

		if ( isset( $_POST['pack_tiers'] ) && is_array( $_POST['pack_tiers'] ) ) {
			$tiers = array_filter( $_POST['pack_tiers'], fn( $tier ) => ! empty( $tier['quantity'] ) && ! empty( $tier['price'] ) );
			$tiers = array_map( fn( $tier ) => [
				'quantity' => (int) $tier['quantity'],
				'price'    => (float) $tier['price']
			], $tiers );
			update_post_meta( $postId, WC_ENHANCED_OPTION_PREFIX . 'pricing_tiers', $tiers );
		}
	}

	public function displayPricingTable(): void {
		global $product;
		if ( ! $this->shouldApplyDynamicPricing( $product ) ) {
			return;
		}

		$tiers = $this->getProductPricingTiers( $product );
		if ( empty( $tiers ) ) {
			return;
		}

		$basePrice = (float) $product->get_price();
		echo '<div class="pack-pricing-table">';
		echo '<h4>' . esc_html__( 'Volume Pricing', WC_ENHANCED_TEXT_DOMAIN ) . '</h4>';
		echo '<table class="pricing-table">';
		echo '<thead><tr><th>' . esc_html__( 'Quantity', WC_ENHANCED_TEXT_DOMAIN ) . '</th><th>' . esc_html__( 'Unit Price', WC_ENHANCED_TEXT_DOMAIN ) . '</th><th>' . esc_html__( 'Total Price', WC_ENHANCED_TEXT_DOMAIN ) . '</th><th>' . esc_html__( 'You Save', WC_ENHANCED_TEXT_DOMAIN ) . '</th></tr></thead>';
		echo '<tbody>';
		echo '<tr><td>1</td><td>' . wc_price( $basePrice ) . '</td><td>' . wc_price( $basePrice ) . '</td><td>-</td></tr>';

		foreach ( $tiers as $tier ) {
			$unitPrice = $tier['price'] / $tier['quantity'];
			$savings   = ( $basePrice * $tier['quantity'] ) - $tier['price'];
			echo '<tr><td>' . esc_html( $tier['quantity'] ) . '</td><td>' . wc_price( $unitPrice ) . '</td><td>' . wc_price( $tier['price'] ) . '</td><td>' . wc_price( $savings ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	public function displayCartPricingBreakdown( string $productName, array $cartItem ): string {
		if ( empty( $cartItem['pricing_breakdown'] ) ) {
			return $productName;
		}

		$breakdownHtml = '<br><small class="pricing-breakdown">';
		$parts         = [];

		foreach ( $cartItem['pricing_breakdown'] as $item ) {
			if ( $item['type'] === 'pack' ) {
				$packText = $item['pack_count'] === 1
					? sprintf( __( '1 pack of %d', WC_ENHANCED_TEXT_DOMAIN ), $item['pack_size'] )
					: sprintf( __( '%d packs of %d each', WC_ENHANCED_TEXT_DOMAIN ), $item['pack_count'], $item['pack_size'] );
				$parts[]  = $packText . ' (' . wc_price( $item['pack_price'] ) . ' ' . __( 'each', WC_ENHANCED_TEXT_DOMAIN ) . ')';
			} else {
				$parts[] = sprintf( __( '%d individual (%s each)', WC_ENHANCED_TEXT_DOMAIN ), $item['quantity'], wc_price( $item['unit_price'] ) );
			}
		}

		$breakdownHtml .= implode( ' + ', $parts ) . '</small>';

		return $productName . $breakdownHtml;
	}
}

/**
 * COD Fees Feature
 */
class WC_Enhanced_Cod_Fees {
	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'addCodFee' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueCodScript' ] );
	}

	public function enqueueCodScript(): void {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-cod', WC_ENHANCED_PLUGIN_URL . 'assets/js/cod-fee.js', [
			'jquery',
			'wc-checkout'
		], WC_ENHANCED_VERSION, true );
	}

	public function addCodFee(): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$fee = (float) get_option( WC_ENHANCED_OPTION_PREFIX . 'cod_fee', 30000 );
		if ( WC()->session->get( 'chosen_payment_method' ) === 'cod' ) {
			WC()->cart->add_fee( __( 'COD Processing Fee', WC_ENHANCED_TEXT_DOMAIN ), $fee );
		}
	}
}

/**
 * REST API Feature
 */
class WC_Enhanced_Rest_Api {
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
	}

	public function registerRestRoutes(): void {
		$routes = [
			'/test'          => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'apiTest' ],
				'permission_callback' => '__return_true',
			],
			'/orders/stats'  => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'getOrdersStats' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			'/orders/recent' => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'getRecentOrders' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => [
					'limit'  => [
						'default'           => 10,
						'sanitize_callback' => 'absint',
					],
					'status' => [
						'default'           => 'any',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		];

		foreach ( $routes as $route => $config ) {
			register_rest_route( WC_ENHANCED_API_NAMESPACE, $route, $config );
		}
	}

	public function apiTest(): WP_REST_Response {
		return rest_ensure_response( [
			'success'             => true,
			'message'             => 'WooCommerce Enhanced Features API is operational',
			'version'             => WC_ENHANCED_VERSION,
			'timestamp'           => current_time( 'mysql' ),
			'wordpress_version'   => get_bloginfo( 'version' ),
			'woocommerce_version' => Automattic\Jetpack\Constants::get_constant( 'WC_VERSION' ) ?? 'Not available',
			'features'            => [ 'dynamic_pricing' => true, 'cod_fees' => true, 'rest_api' => true ],
		] );
	}

	public function getOrdersStats(): WP_REST_Response|WP_Error {
		global $wpdb;

		try {
			$results = $wpdb->get_results( "
				SELECT post_status, COUNT(*) as count 
				FROM {$wpdb->posts} 
				WHERE post_type = 'shop_order' 
				GROUP BY post_status
			" );

			$stats = [];
			$total = 0;

			foreach ( $results as $result ) {
				$status           = str_replace( 'wc-', '', $result->post_status );
				$count            = (int) $result->count;
				$stats[ $status ] = $count;
				$total            += $count;
			}

			return rest_ensure_response( [
				'success'      => true,
				'total_orders' => $total,
				'by_status'    => $stats,
				'generated_at' => current_time( 'mysql' ),
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function getRecentOrders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$limit  = min( $request['limit'] ?: 10, 50 );
			$status = $request['status'] ?: 'any';

			$args = [
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
			];

			if ( $status !== 'any' ) {
				$args['status'] = "wc-{$status}";
			}

			$orders     = wc_get_orders( $args );
			$ordersData = array_map( [ $this, 'formatOrderData' ], $orders );

			return rest_ensure_response( [
				'success' => true,
				'count'   => count( $ordersData ),
				'orders'  => $ordersData,
				'params'  => compact( 'limit', 'status' ),
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'orders_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	private function formatOrderData( WC_Order $order ): array {
		$items = array_map( function ( WC_Order_Item_Product $item ) {
			$product = $item->get_product();

			return [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => (float) $item->get_total(),
				'sku'      => $product ? $product->get_sku() : '',
			];
		}, $order->get_items() );

		return [
			'id'             => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'date_created'   => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
			'payment_method' => $order->get_payment_method_title(),
			'customer'       => [
				'id'    => $order->get_customer_id(),
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			],
			'items_count'    => $order->get_item_count(),
			'items'          => $items,
		];
	}
}

/**
 * Admin Interface
 */
class WC_Enhanced_Admin_Interface {
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'addAdminMenu' ] );
	}

	public function addAdminMenu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Enhanced Features', WC_ENHANCED_TEXT_DOMAIN ),
			__( 'Enhanced Features', WC_ENHANCED_TEXT_DOMAIN ),
			'manage_options',
			'wc-enhanced-features',
			[ $this, 'renderAdminPage' ]
		);
	}

	public function renderAdminPage(): void {
		?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WooCommerce Enhanced Features', WC_ENHANCED_TEXT_DOMAIN ); ?></h1>
            <div class="card">
                <h2><?php esc_html_e( 'Dynamic Pack Pricing', WC_ENHANCED_TEXT_DOMAIN ); ?></h2>
                <p><?php esc_html_e( 'Automatically applies optimal pack pricing based on quantity tiers.', WC_ENHANCED_TEXT_DOMAIN ); ?></p>
                <h3><?php esc_html_e( 'Example Configuration:', WC_ENHANCED_TEXT_DOMAIN ); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e( 'Individual Price:', WC_ENHANCED_TEXT_DOMAIN ); ?></strong> $5.00 per
                        item
                    </li>
                    <li><strong><?php esc_html_e( '10-Pack:', WC_ENHANCED_TEXT_DOMAIN ); ?></strong> $45.00 total ($4.50
                        per item)
                    </li>
                    <li><strong><?php esc_html_e( '20-Pack:', WC_ENHANCED_TEXT_DOMAIN ); ?></strong> $80.00 total ($4.00
                        per item)
                    </li>
                </ul>
                <h3><?php esc_html_e( 'Pricing Examples:', WC_ENHANCED_TEXT_DOMAIN ); ?></h3>
                <ul>
                    <li><strong>12 items:</strong> 1×10-pack ($45) + 2×individual ($10) = $55</li>
                    <li><strong>22 items:</strong> 1×20-pack ($80) + 2×individual ($10) = $90</li>
                    <li><strong>32 items:</strong> 1×20-pack ($80) + 1×10-pack ($45) + 2×individual ($10) = $135</li>
                </ul>
            </div>
            <div class="card">
                <h2><?php esc_html_e( 'COD Fees', WC_ENHANCED_TEXT_DOMAIN ); ?></h2>
                <p><?php esc_html_e( 'Automatically adds configurable processing fee for Cash on Delivery orders. Configure in WooCommerce > Settings > General > Enhanced COD.', WC_ENHANCED_TEXT_DOMAIN ); ?></p>
            </div>
            <div class="card">
                <h2><?php esc_html_e( 'REST API Endpoints', WC_ENHANCED_TEXT_DOMAIN ); ?></h2>
                <ul>
                    <li><code>GET <?php echo esc_url( rest_url( WC_ENHANCED_API_NAMESPACE . '/test' ) ); ?></code></li>
                    <li>
                        <code>GET <?php echo esc_url( rest_url( WC_ENHANCED_API_NAMESPACE . '/orders/stats' ) ); ?></code>
                    </li>
                    <li>
                        <code>GET <?php echo esc_url( rest_url( WC_ENHANCED_API_NAMESPACE . '/orders/recent' ) ); ?></code>
                    </li>
                </ul>
            </div>
        </div>
		<?php
	}
}

// Initialize
new WC_Enhanced_Features_Plugin();