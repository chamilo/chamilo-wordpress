<?php
/**
 * Runs only on "Delete" from the Plugins screen (never on deactivate). Per the admin
 * guide's "Disconnecting / uninstalling" section: this deliberately does NOT delete
 * WooCommerce products it created, and does NOT un-enroll anyone from Chamilo —
 * those are independent systems by design (plan §1). Only this plugin's own
 * connection settings are removed.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	die;
}

$chamilo_wc_options = [
	'chamilo_wp_base_url',
	'chamilo_wp_service_account_api_key',
	'chamilo_wp_access_url_id',
	'chamilo_wp_sync_interval',
	'chamilo_wp_default_product_category_id',
	'chamilo_wp_course_visibilities',
	'chamilo_wp_last_sync_at',
	'chamilo_wp_last_sync_status',
];

foreach ($chamilo_wc_options as $option) {
	delete_option($option);
}

wp_clear_scheduled_hook('chamilo_wc_catalog_sync');
