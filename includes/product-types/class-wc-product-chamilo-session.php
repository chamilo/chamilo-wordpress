<?php
/**
 * A Chamilo session, synced in as a WooCommerce product. Meta keys match
 * WORDPRESS_STOREFRONT_PLUGIN_PLAN.md §5.2 exactly.
 */

if (!defined('ABSPATH')) {
	die;
}

class WC_Product_Chamilo_Session extends WC_Product_Simple
{
	public function get_type()
	{
		return 'chamilo_session';
	}

	public function get_chamilo_session_id(): int
	{
		return (int) $this->get_meta('_chamilo_session_id', true);
	}

	public function set_chamilo_session_id(int $session_id): void
	{
		$this->update_meta_data('_chamilo_session_id', $session_id);
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
	 * sync tell "Chamilo's image genuinely changed" apart from "same image, don't
	 * re-download it every run" without keeping a separate timestamp.
	 */
	public function get_chamilo_image_source_url(): string
	{
		return (string) $this->get_meta('_chamilo_image_source_url', true);
	}

	public function set_chamilo_image_source_url(string $url): void
	{
		$this->update_meta_data('_chamilo_image_source_url', $url);
	}

	public function get_chamilo_capacity(): ?int
	{
		$value = $this->get_meta('_chamilo_capacity', true);

		return '' === $value ? null : (int) $value;
	}

	public function set_chamilo_capacity(?int $capacity): void
	{
		$this->update_meta_data('_chamilo_capacity', null === $capacity ? '' : $capacity);
	}

	public function get_chamilo_seats_taken(): int
	{
		return (int) $this->get_meta('_chamilo_seats_taken', true);
	}

	public function set_chamilo_seats_taken(int $seats_taken): void
	{
		$this->update_meta_data('_chamilo_seats_taken', $seats_taken);
	}

	public function get_chamilo_last_synced_at(): string
	{
		return (string) $this->get_meta('_chamilo_last_synced_at', true);
	}

	public function set_chamilo_last_synced_at(string $iso8601): void
	{
		$this->update_meta_data('_chamilo_last_synced_at', $iso8601);
	}

	/**
	 * Sets stock status from capacity vs. seats taken (plan §5.2:
	 * "Drives a 'sold out' state in WooCommerce"). A null capacity means
	 * unlimited — always in stock.
	 */
	public function sync_stock_status_from_capacity(): void
	{
		$capacity = $this->get_chamilo_capacity();
		if (null === $capacity) {
			$this->set_stock_status('instock');
			$this->set_manage_stock(false);

			return;
		}

		$this->set_manage_stock(true);
		$this->set_stock_quantity(max(0, $capacity - $this->get_chamilo_seats_taken()));
		$this->set_stock_status($this->get_stock_quantity() > 0 ? 'instock' : 'outofstock');
	}
}
