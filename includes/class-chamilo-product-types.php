<?php
/**
 * Registers the two custom WooCommerce product types this plugin creates
 * (chamilo_course, chamilo_session — see plan §5.1/§5.2). Both are sync-managed only:
 * nobody manually creates one via the normal "Add new product" screen, so unlike a
 * typical custom product type, this plugin does not add them to the manual
 * `product_type_selector` dropdown or build a full custom "Product data" admin panel
 * tab — that's a deliberate scope decision, not an oversight. What *is* needed for
 * correct behavior (price/cart/checkout, wc_get_product() hydration) is registering
 * the PHP class so WooCommerce knows how to load a `chamilo_course`/`chamilo_session`
 * product — that's what map_product_class() does.
 *
 * The two "override locked" checkboxes below (price, image) are the one deliberate
 * exception: `Chamilo_Catalog_Sync` reads `_chamilo_price_override_locked` and
 * `_chamilo_image_override_locked` to decide whether to leave a manually-edited
 * price/image alone, so an admin needs a way to actually turn those flags on —
 * these two fields on the existing General tab (no new tab needed) are that UI.
 *
 * The "Chamilo details" sidebar box is a second, related exception: WooCommerce has
 * no setting that makes one particular product attribute more prominent within the
 * Attributes tab — that tab is only reachable
 * by clicking into it, so at-a-glance visibility needs a different UI element
 * entirely. A sidebar metabox (same pattern as the order screen's "Chamilo
 * enrollment" box, Chamilo_Admin) renders above the fold regardless of which
 * Product Data tab is active, which is what actually solves that. Read-only by
 * design — every value here is already sync-managed elsewhere (attributes, meta);
 * this box is a convenience view, not a second place to edit them.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Product_Types
{
	private const MANAGED_CLASSES = [WC_Product_Chamilo_Course::class, WC_Product_Chamilo_Session::class];

	private const COURSE_VISIBILITY_LABELS = [
		0 => 'Closed',
		1 => 'Registered users only',
		2 => 'Open to platform users',
		3 => 'Open to the world',
		4 => 'Hidden',
	];

	private const SESSION_VISIBILITY_LABELS = [
		1 => 'Read only',
		2 => 'Visible',
		3 => 'Invisible',
		4 => 'Available',
		5 => 'List only',
	];

	public static function init(): void
	{
		add_filter('woocommerce_product_class', [self::class, 'map_product_class'], 10, 2);
		add_action('woocommerce_product_options_general_product_data', [self::class, 'render_lock_fields']);
		add_action('woocommerce_process_product_meta', [self::class, 'save_lock_fields']);
		add_action('add_meta_boxes', [self::class, 'add_info_meta_box'], 10, 2);
	}

	public static function map_product_class(string $classname, string $product_type): string
	{
		if ('chamilo_course' === $product_type) {
			return WC_Product_Chamilo_Course::class;
		}

		if ('chamilo_session' === $product_type) {
			return WC_Product_Chamilo_Session::class;
		}

		return $classname;
	}

	public static function render_lock_fields(): void
	{
		global $product_object;

		if (!self::is_managed($product_object)) {
			return;
		}

		echo '<div class="options_group">';

		woocommerce_wp_checkbox([
			'id' => '_chamilo_price_override_locked',
			'label' => __('Price locked from Chamilo sync', 'chamilo'),
			'description' => __('When checked, re-syncing from Chamilo will never overwrite this product\'s price.', 'chamilo'),
			'value' => $product_object->is_chamilo_price_override_locked() ? 'yes' : 'no',
		]);

		woocommerce_wp_checkbox([
			'id' => '_chamilo_image_override_locked',
			'label' => __('Image locked from Chamilo sync', 'chamilo'),
			'description' => __('When checked, re-syncing from Chamilo will never overwrite this product\'s image.', 'chamilo'),
			'value' => $product_object->is_chamilo_image_override_locked() ? 'yes' : 'no',
		]);

		echo '</div>';
	}

	public static function save_lock_fields(int $post_id): void
	{
		$product = wc_get_product($post_id);
		if (!self::is_managed($product)) {
			return;
		}

		$product->set_chamilo_price_override_locked(isset($_POST['_chamilo_price_override_locked']));
		$product->set_chamilo_image_override_locked(isset($_POST['_chamilo_image_override_locked']));
		$product->save();
	}

	/**
	 * @param WP_Post $post
	 */
	public static function add_info_meta_box(string $post_type, $post): void
	{
		if ('product' !== $post_type || !self::is_managed(wc_get_product($post->ID))) {
			return; // Never registered at all for a regular product — not just an empty box.
		}

		add_meta_box(
			'chamilo-product-info',
			__('Chamilo details', 'chamilo'),
			[self::class, 'render_info_meta_box'],
			'product',
			'side',
			'high'
		);
	}

	public static function render_info_meta_box(WP_Post $post): void
	{
		$product = wc_get_product($post->ID);
		if (!self::is_managed($product)) {
			return;
		}

		$rows = [];

		if ($product instanceof WC_Product_Chamilo_Course) {
			$rows[__('Course code', 'chamilo')] = $product->get_chamilo_course_code();
			$rows[__('Chamilo course ID', 'chamilo')] = (string) $product->get_chamilo_course_id();
			$rows[__('Teacher(s)', 'chamilo')] = (string) $product->get_meta('_chamilo_teacher_names', true);
			$rows[__('Visibility', 'chamilo')] = self::visibility_label(
				(int) $product->get_meta('_chamilo_visibility', true),
				self::COURSE_VISIBILITY_LABELS
			);
		} elseif ($product instanceof WC_Product_Chamilo_Session) {
			$rows[__('Chamilo session ID', 'chamilo')] = (string) $product->get_chamilo_session_id();
			$capacity = $product->get_chamilo_capacity();
			$rows[__('Seats taken', 'chamilo')] = sprintf(
				'%d / %s',
				$product->get_chamilo_seats_taken(),
				null === $capacity ? __('unlimited', 'chamilo') : (string) $capacity
			);
			$rows[__('Visibility', 'chamilo')] = self::visibility_label(
				(int) $product->get_meta('_chamilo_visibility', true),
				self::SESSION_VISIBILITY_LABELS
			);
		}

		$rows[__('Last synced', 'chamilo')] = (string) $product->get_meta('_chamilo_last_synced_at', true);

		echo '<table class="widefat striped" style="border:0;">';
		foreach ($rows as $label => $value) {
			printf(
				'<tr><th style="text-align:left;">%s</th><td>%s</td></tr>',
				esc_html($label),
				'' !== $value ? esc_html($value) : '&mdash;'
			);
		}
		echo '</table>';
		echo '<p class="description">'.esc_html__('Read-only — managed by the next Chamilo sync.', 'chamilo').'</p>';
	}

	/**
	 * @param array<int, string> $labels
	 */
	private static function visibility_label(int $value, array $labels): string
	{
		return $labels[$value] ?? sprintf(
			/* translators: %d: raw Chamilo visibility value with no known label */
			__('Unknown (%d)', 'chamilo'),
			$value
		);
	}

	private static function is_managed($product): bool
	{
		foreach (self::MANAGED_CLASSES as $class) {
			if ($product instanceof $class) {
				return true;
			}
		}

		return false;
	}
}
