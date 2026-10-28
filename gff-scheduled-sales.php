<?php
/**
 * Plugin Name:       GFF Scheduled Sales for WooCommerce
 * Plugin URI:        https://getfoundfirst.com/
 * Description:       Create immediate or scheduled sale rules (percentage or fixed discount) for selected products and categories, with live progress, background scheduling, one-click deactivation and a full activity log.
 * Version:           1.0.3
 * Author:            Get Found First
 * Author URI:        https://getfoundfirst.com/
 * Text Domain:       gff-scheduled-sales
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GFF_Scheduled_Sales
 */

defined( 'ABSPATH' ) || exit;

define( 'GFF_SS_VERSION', '1.0.3' );
define( 'GFF_SS_FILE', __FILE__ );
define( 'GFF_SS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFF_SS_URL', plugin_dir_url( __FILE__ ) );

require_once GFF_SS_DIR . 'includes/class-gff-ss-install.php';
require_once GFF_SS_DIR . 'includes/class-gff-ss-engine.php';
require_once GFF_SS_DIR . 'includes/class-gff-ss-admin.php';

register_activation_hook( __FILE__, array( 'GFF_SS_Install', 'install' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>GFF Scheduled Sales</strong> requires WooCommerce to be active.</p></div>';
				}
			);
			return;
		}
		GFF_SS_Install::maybe_upgrade();
		GFF_SS_Engine::init();
		if ( is_admin() ) {
			GFF_SS_Admin::init();
		}
	}
);
