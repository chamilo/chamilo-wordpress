<?php
/**
 * Admin-side glue: an "Enrollment" metabox on the WooCommerce order edit screen
 * (status + retry per Chamilo line item), HPOS-aware. See plan §5.3 — this is what
 * makes a `failed` enrollment "visible and retryable from the WooCommerce order
 * screen rather than silently lost."
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Admin
{
	public static function init(): void
	{
		add_action('add_meta_boxes', [self::class, 'add_meta_box']);
		add_action('wp_ajax_chamilo_retry_enrollment', [self::class, 'ajax_retry_enrollment']);
	}

	public static function add_meta_box(): void
	{
		$screen = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id('shop-order')
			: 'shop_order';

		add_meta_box(
			'chamilo-enrollment',
			__('Chamilo enrollment', 'chamilo'),
			[self::class, 'render_meta_box'],
			$screen,
			'side'
		);
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order_object
	 */
	public static function render_meta_box($post_or_order_object): void
	{
		$order = $post_or_order_object instanceof WC_Order ? $post_or_order_object : wc_get_order($post_or_order_object->ID);
		if (!$order instanceof WC_Order) {
			return;
		}

		$found = false;
		foreach ($order->get_items() as $item) {
			if (!$item instanceof WC_Order_Item_Product || !Chamilo_Order_Meta::is_chamilo_item($item)) {
				continue;
			}
			$found = true;

			$status = Chamilo_Order_Meta::get_status($item);
			$error = Chamilo_Order_Meta::get_error($item);
			$badge_class = 'enrolled' === $status ? 'chamilo-status-ok' : ('failed' === $status ? 'chamilo-status-error' : 'chamilo-status-pending');
			?>
			<div class="chamilo-enrollment-item" style="margin-bottom:10px;">
				<strong><?php echo esc_html($item->get_name()); ?></strong><br>
				<span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
				<?php if ('' !== $error) : ?>
					<p style="color:#a00;margin:4px 0;"><?php echo esc_html($error); ?></p>
				<?php endif; ?>
				<?php if ('enrolled' !== $status) : ?>
					<button type="button" class="button chamilo-retry-enrollment"
						data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>"
						data-item-id="<?php echo esc_attr((string) $item->get_id()); ?>">
						<?php esc_html_e('Retry enrollment', 'chamilo'); ?>
					</button>
				<?php endif; ?>
			</div>
			<?php
		}

		if (!$found) {
			echo '<p>'.esc_html__('No Chamilo products in this order.', 'chamilo').'</p>';

			return;
		}
		?>
		<script>
		document.querySelectorAll('.chamilo-retry-enrollment').forEach(function (button) {
			button.addEventListener('click', function () {
				button.disabled = true;
				var data = new FormData();
				data.append('action', 'chamilo_retry_enrollment');
				data.append('nonce', <?php echo wp_json_encode(wp_create_nonce('chamilo_admin_action')); ?>);
				data.append('order_id', button.dataset.orderId);
				data.append('item_id', button.dataset.itemId);
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function () { window.location.reload(); });
			});
		});
		</script>
		<?php
	}

	public static function ajax_retry_enrollment(): void
	{
		check_ajax_referer('chamilo_admin_action', 'nonce');
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => __('Permission denied.', 'chamilo')], 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;

		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			wp_send_json_error(['message' => __('Order not found.', 'chamilo')], 404);
		}

		$item = $order->get_item($item_id);
		if (!$item instanceof WC_Order_Item_Product) {
			wp_send_json_error(['message' => __('Order item not found.', 'chamilo')], 404);
		}

		$client = new Chamilo_Api_Client();
		if (!$client->is_configured()) {
			wp_send_json_error(['message' => __('Chamilo connection is not configured.', 'chamilo')]);
		}

		Chamilo_Enrollment::process_item($client, $order, $item);

		wp_send_json_success(['status' => Chamilo_Order_Meta::get_status($item)]);
	}
}
