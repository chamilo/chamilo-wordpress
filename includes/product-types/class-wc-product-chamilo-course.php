<?php
/**
 * A Chamilo course, synced in as a WooCommerce product. Meta keys match
 * WORDPRESS_STOREFRONT_PLUGIN_PLAN.md §5.1 exactly.
 *
 * Extends WC_Product_Simple (not WC_Product) so cart, checkout, tax and price display
 * all work out of the box with zero extra code — that reuse is the whole point of the
 * "WooCommerce owns commerce, Chamilo owns content" split (plan §1).
 */

if (!defined('ABSPATH')) {
	die;
}

class WC_Product_Chamilo_Course extends WC_Product_Simple
{
	public function get_type()
	{
		return 'chamilo_course';
	}

	public function get_chamilo_course_id(): int
	{
		return (int) $this->get_meta('_chamilo_course_id', true);
	}

	public function set_chamilo_course_id(int $course_id): void
	{
		$this->update_meta_data('_chamilo_course_id', $course_id);
	}

	public function get_chamilo_course_code(): string
	{
		return (string) $this->get_meta('_chamilo_course_code', true);
	}

	public function set_chamilo_course_code(string $code): void
	{
		$this->update_meta_data('_chamilo_course_code', $code);
	}

	public function get_chamilo_access_url_id(): int
	{
		return (int) $this->get_meta('_chamilo_access_url_id', true);
	}

	public function set_chamilo_access_url_id(int $access_url_id): void
	{
		$this->update_meta_data('_chamilo_access_url_id', $access_url_id);
	}

	public function is_chamilo_price_override_locked(): bool
	{
		return 'yes' === $this->get_meta('_chamilo_price_override_locked', true);
	}

	public function set_chamilo_price_override_locked(bool $locked): void
	{
		$this->update_meta_data('_chamilo_price_override_locked', $locked ? 'yes' : 'no');
	}

	public function is_chamilo_image_override_locked(): bool
	{
		return 'yes' === $this->get_meta('_chamilo_image_override_locked', true);
	}

	public function set_chamilo_image_override_locked(bool $locked): void
	{
		$this->update_meta_data('_chamilo_image_override_locked', $locked ? 'yes' : 'no');
	}

	/**
	 * The absolute Chamilo URL the current product image was imported from — lets
	 * sync tell "Chamilo's illustration genuinely changed" apart from "same image,
	 * don't re-download it every run" without keeping a separate timestamp.
	 */
	public function get_chamilo_image_source_url(): string
	{
		return (string) $this->get_meta('_chamilo_image_source_url', true);
	}

	public function set_chamilo_image_source_url(string $url): void
	{
		$this->update_meta_data('_chamilo_image_source_url', $url);
	}

	public function get_chamilo_last_synced_at(): string
	{
		return (string) $this->get_meta('_chamilo_last_synced_at', true);
	}

	public function set_chamilo_last_synced_at(string $iso8601): void
	{
		$this->update_meta_data('_chamilo_last_synced_at', $iso8601);
	}
}
