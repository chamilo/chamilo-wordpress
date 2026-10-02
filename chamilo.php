<?php
/**
 * Plugin Name: Chamilo Storefront for WooCommerce
 * Description: Sells Chamilo courses and sessions through WooCommerce — pulls a catalog from a Chamilo v3 portal into WooCommerce products, and pushes enrollment back to Chamilo when an order completes.
 * Version: 1.0.2
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * Author: Chamilo
 * Author URI: https://chamilo.org
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: chamilo
 *
 * This plugin does not implement single sign-on: "Continue to your course" is a plain
 * deep link to Chamilo's own login page (see includes/class-chamilo-enrollment.php).
 * See WORDPRESS_STOREFRONT_PLUGIN_PLAN.md (Chamilo repo) for the full design and the
 * rationale behind every decision referenced in the comments throughout this plugin.
 */

if (!defined('ABSPATH')) {
	die;
}

define('CHAMILO_WC_VERSION', '1.0.2');
define('CHAMILO_WC_PLUGIN_FILE', __FILE__);
define('CHAMILO_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHAMILO_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Declare HPOS (High-Performance Order Storage) compatibility. WooCommerce's current
 * default order storage — order meta throughout this plugin goes through
 * Chamilo_Order_Meta, which uses $order->get_meta()/update_meta_data()/save(), never
 * direct postmeta table access, so this is a straightforward declaration, not a
 * migration.
 */
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			CHAMILO_WC_PLUGIN_FILE,
			true
		);
	}
});

/**
 * Everything in this plugin depends on WooCommerce being active — a Chamilo course
 * is a WooCommerce product type, so there is no useful degraded mode without it.
 */
function chamilo_wc_missing_woocommerce_notice(): void
{
	echo '<div class="notice notice-error"><p>';
	echo esc_html__('Chamilo Storefront requires WooCommerce to be installed and active.', 'chamilo');
	echo '</p></div>';
}

function chamilo_wc_init(): void
{
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'chamilo_wc_missing_woocommerce_notice');

		return;
	}

	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-crypto.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-api-client.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-settings.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/product-types/class-wc-product-chamilo-course.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/product-types/class-wc-product-chamilo-session.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-product-types.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-product-attributes.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-order-meta.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-catalog-sync.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-enrollment.php';
	require_once CHAMILO_WC_PLUGIN_DIR.'includes/class-chamilo-admin.php';

	Chamilo_Settings::init();
	Chamilo_Product_Types::init();
	Chamilo_Product_Attributes::init();
	Chamilo_Catalog_Sync::init();
	Chamilo_Enrollment::init();
	Chamilo_Admin::init();
}
/*
 * Priority 20, not the default 10: plugins_loaded callbacks otherwise run in the
 * order plugins happen to be loaded (roughly alphabetical by file path), and
 * "chamilo.php" sorts before "woocommerce.php" — without this, WC_Product_Simple
 * (which the custom product type classes below extend) might not exist yet.
 */
add_action('plugins_loaded', 'chamilo_wc_init', 20);

/**
 * Schedules the recurring catalog sync at the interval chosen in settings (default:
 * hourly — see Chamilo_Settings::get_sync_interval()). WP-Cron only actually fires on
 * a site visit unless a real server cron hits wp-cron.php (see README.md, D.2) —
 * that's an ops concern for whoever deploys this, not something the plugin can fix.
 */
function chamilo_wc_activate(): void
{
	if (!wp_next_scheduled('chamilo_wc_catalog_sync')) {
		// Chamilo_Settings isn't loaded yet at this point — plugins_loaded (where
		// chamilo_wc_init() requires it) already fired earlier in this same request,
		// before this plugin was in active_plugins, so it won't fire again just
		// because activation included this file. Read the raw option directly
		// instead of depending on a class that isn't guaranteed to exist yet.
		wp_schedule_event(time(), (string) get_option('chamilo_wp_sync_interval', 'hourly'), 'chamilo_wc_catalog_sync');
	}
}
register_activation_hook(__FILE__, 'chamilo_wc_activate');

function chamilo_wc_deactivate(): void
{
	wp_clear_scheduled_hook('chamilo_wc_catalog_sync');
}
register_deactivation_hook(__FILE__, 'chamilo_wc_deactivate');
