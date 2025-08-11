<?php
// uninstall.php (New file for cleanup)

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clean up post meta
delete_post_meta_by_key( WC_ENHANCED_OPTION_PREFIX . 'pricing_enabled' );
delete_post_meta_by_key( WC_ENHANCED_OPTION_PREFIX . 'pricing_tiers' );

// Clean up options
delete_option( WC_ENHANCED_OPTION_PREFIX . 'cod_fee' );
