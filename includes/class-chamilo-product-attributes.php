<?php
/**
 * Manages the global, taxonomy-backed WooCommerce product attributes this plugin
 * assigns to synced products — currently "Package type" (Course/Session) and
 * "Course visibility" — plus a helper for *local* (non-taxonomy) attributes used
 * for per-product values that don't belong in a shared vocabulary (session access
 * dates/duration — see Chamilo_Catalog_Sync).
 *
 * Global attributes are the right tool for a small, shared, filterable facet
 * (layered nav / attribute filter widgets and blocks, "Additional information" tab):
 * "Package type" lets shoppers tell the two Chamilo product kinds apart; "Course
 * visibility" surfaces Chamilo's own Registered/Open-to-platform/Open-to-the-world
 * distinction the same way. Neither is a product category — course/session
 * categories already carry Chamilo's own category taxonomy (`categoryTitles`, plan
 * §5.1/§5.5); reusing categories for these would collide unrelated classification
 * axes into one taxonomy.
 *
 * Note "Package type" is a *storefront-facing* convenience, not the underlying
 * distinction: the WooCommerce product TYPE (`chamilo_course` / `chamilo_session`,
 * see class-chamilo-product-types.php) already tells the two apart for every
 * backend purpose. WooCommerce just doesn't expose product_type as a
 * customer-facing filter by default, which is the only reason that attribute exists.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Product_Attributes
{
	/**
	 * @var array<string, array{label: string, terms: array<string, string>}>
	 */
	private const ATTRIBUTES = [
		'package-type' => [
			'label' => 'Package type',
			'terms' => [
				'course' => 'Course',
				'session' => 'Session',
			],
		],
		'visibility' => [
			'label' => 'Course visibility',
			'terms' => [
				'registered' => 'Registered users only',
				'open-platform' => 'Open to platform users',
				'open-world' => 'Open to the world',
			],
		],
	];

	public static function init(): void
	{
		add_action('init', [self::class, 'ensure_registered'], 5);
	}

	/**
	 * Idempotent: creates each global attribute and its terms the first time this
	 * runs, then just confirms the taxonomies are registered on every later
	 * request. Safe to call on every page load.
	 */
	public static function ensure_registered(): void
	{
		if (!function_exists('wc_attribute_taxonomy_id_by_name')) {
			return; // WooCommerce isn't active (yet) — nothing to register.
		}

		foreach (self::ATTRIBUTES as $slug => $definition) {
			self::ensure_attribute_registered($slug, $definition);
		}
	}

	/**
	 * @param array{label: string, terms: array<string, string>} $definition
	 */
	private static function ensure_attribute_registered(string $slug, array $definition): void
	{
		if (0 === wc_attribute_taxonomy_id_by_name($slug)) {
			$attribute_id = wc_create_attribute([
				'name' => $definition['label'],
				'slug' => $slug,
				'type' => 'select',
				'order_by' => 'menu_order',
				'has_archives' => true,
			]);

			if (is_wp_error($attribute_id)) {
				return; // Next page load retries — nothing was assigned yet.
			}

			delete_transient('wc_attribute_taxonomies');
		}

		$taxonomy = wc_attribute_taxonomy_name($slug);

		if (!taxonomy_exists($taxonomy)) {
			register_taxonomy(
				$taxonomy,
				'product',
				[
					'hierarchical' => false,
					'show_ui' => false,
					'query_var' => true,
					'rewrite' => false,
					'public' => true,
				]
			);
		}

		foreach ($definition['terms'] as $term_slug => $label) {
			if (!term_exists($term_slug, $taxonomy)) {
				wp_insert_term($label, $taxonomy, ['slug' => $term_slug]);
			}
		}
	}

	/**
	 * Assigns a term of a registered global attribute to a product, both as an
	 * actual taxonomy term (so layered-nav/attribute filtering can query it) and in
	 * the product's own attribute list (so it shows in the "Additional
	 * information" tab). Requires the product to already have an ID — call this
	 * after the product's own save(), same as category assignment (see
	 * Chamilo_Catalog_Sync::assign_categories()).
	 */
	public static function assign(WC_Product $product, string $attribute_slug, string $term_slug): void
	{
		if (!isset(self::ATTRIBUTES[$attribute_slug]['terms'][$term_slug]) || 0 === $product->get_id()) {
			return;
		}

		$taxonomy = wc_attribute_taxonomy_name($attribute_slug);
		$term = get_term_by('slug', $term_slug, $taxonomy);
		if (!$term instanceof WP_Term) {
			return; // ensure_registered() hasn't run yet — the next sync will pick it up.
		}

		wp_set_object_terms($product->get_id(), [(int) $term->term_id], $taxonomy);

		$attribute = new WC_Product_Attribute();
		$attribute->set_id(wc_attribute_taxonomy_id_by_name($attribute_slug));
		$attribute->set_name($taxonomy);
		$attribute->set_options([(int) $term->term_id]);
		$attribute->set_position(0);
		$attribute->set_visible(true);
		$attribute->set_variation(false);

		$others = array_values(array_filter(
			$product->get_attributes(),
			static fn (WC_Product_Attribute $existing): bool => $taxonomy !== $existing->get_name()
		));
		$others[] = $attribute;

		$product->set_attributes($others);
		$product->save();
	}

	/**
	 * Sets a per-product *local* (non-taxonomy) attribute — a plain name/value pair
	 * that shows in the "Additional information" tab without needing a shared,
	 * global vocabulary. The right tool for a value that's unique per product (a
	 * date, a duration) rather than a shared facet across many products: a global
	 * attribute would otherwise accumulate one throwaway term per distinct value.
	 *
	 * Pass an empty $value to remove a previously-set local attribute of this
	 * label (e.g. a session that was duration-based and no longer is).
	 */
	public static function assign_custom(WC_Product $product, string $label, string $value): void
	{
		if (0 === $product->get_id()) {
			return;
		}

		$attributes = array_values(array_filter(
			$product->get_attributes(),
			static fn (WC_Product_Attribute $existing): bool => $existing->is_taxonomy() || $label !== $existing->get_name()
		));

		if ('' !== $value) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name($label);
			$attribute->set_options([$value]);
			$attribute->set_position(count($attributes));
			$attribute->set_visible(true);
			$attribute->set_variation(false);
			$attributes[] = $attribute;
		}

		$product->set_attributes($attributes);
		$product->save();
	}
}
