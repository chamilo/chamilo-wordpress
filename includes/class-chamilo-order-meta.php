<?php
/**
 * HPOS-safe read/write helpers for the "enrollment ledger" meta from plan §5.3.
 * Tracked per order *item* (not per order) — an order can mix a Chamilo course with a
 * Chamilo session, or with a non-Chamilo product entirely, so enrollment status has to
 * be resolvable per line item, not assumed uniform across the whole order.
 *
 * Every read/write goes through WC_Order_Item::get_meta()/update_meta_data()/save() —
 * never $wpdb or direct postmeta access — which is what actually makes this
 * HPOS-compatible (see chamilo.php's before_woocommerce_init declaration).
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Order_Meta
{
	public static function get_product_type(WC_Order_Item_Product $item): string
	{
		return (string) $item->get_meta('_chamilo_product_type', true);
	}

	public static function get_product_id(WC_Order_Item_Product $item): int
	{
		return (int) $item->get_meta('_chamilo_product_id', true);
	}

	public static function get_user_id(WC_Order_Item_Product $item): int
	{
		return (int) $item->get_meta('_chamilo_user_id', true);
	}

	public static function get_status(WC_Order_Item_Product $item): string
	{
		$status = (string) $item->get_meta('_chamilo_enrollment_status', true);

		return '' === $status ? 'pending' : $status;
	}

	public static function get_error(WC_Order_Item_Product $item): string
	{
		return (string) $item->get_meta('_chamilo_enrollment_error', true);
	}

	public static function init_ledger(WC_Order_Item_Product $item, string $product_type, int $product_id): void
	{
		$item->update_meta_data('_chamilo_product_type', $product_type);
		$item->update_meta_data('_chamilo_product_id', $product_id);
		$item->update_meta_data('_chamilo_enrollment_status', 'pending');
		$item->save();
	}

	public static function mark_user_resolved(WC_Order_Item_Product $item, int $chamilo_user_id): void
	{
		$item->update_meta_data('_chamilo_user_id', $chamilo_user_id);
		$item->save();
	}

	public static function mark_enrolled(WC_Order_Item_Product $item): void
	{
		$item->update_meta_data('_chamilo_enrollment_status', 'enrolled');
		$item->update_meta_data('_chamilo_enrollment_error', '');
		$item->update_meta_data('_chamilo_enrolled_at', current_time('c'));
		$item->save();
	}

	public static function mark_failed(WC_Order_Item_Product $item, string $error_message): void
	{
		$item->update_meta_data('_chamilo_enrollment_status', 'failed');
		$item->update_meta_data('_chamilo_enrollment_error', $error_message);
		$item->save();
	}

	/**
	 * What Chamilo course/session this line item's product was synced from, read
	 * straight from the product's own `_chamilo_course_id` / `_chamilo_session_id`
	 * meta — the sync's actual record of "this came from Chamilo" — rather than
	 * from WooCommerce's `product_type` term / PHP class, which can silently go
	 * stale (e.g. an admin saving the product edit screen posts type "simple").
	 *
	 * @return array{type: 'course'|'session', id: int}|null
	 */
	public static function resolve_target(WC_Order_Item_Product $item): ?array
	{
		$product_id = $item->get_product_id();
		if ($product_id <= 0) {
			return null;
		}

		$course_id = (int) get_post_meta($product_id, '_chamilo_course_id', true);
		if ($course_id > 0) {
			return ['type' => 'course', 'id' => $course_id];
		}

		$session_id = (int) get_post_meta($product_id, '_chamilo_session_id', true);
		if ($session_id > 0) {
			return ['type' => 'session', 'id' => $session_id];
		}

		return null;
	}

	/**
	 * True when this line item is a product synced from Chamilo — the only kind
	 * Chamilo_Enrollment should ever act on. Independent of the product's
	 * WooCommerce type (see resolve_target()).
	 */
	public static function is_chamilo_item(WC_Order_Item_Product $item): bool
	{
		return null !== self::resolve_target($item);
	}
}
