<?php
/**
 * WooCommerce > Settings > Chamilo tab. Field names match
 * WORDPRESS_STOREFRONT_PLUGIN_PLAN.md §5.5 exactly (chamilo_wp_*), so the plan doc and
 * this file stay traceable to each other.
 *
 * The service account API key is encrypted at rest (see class-chamilo-crypto.php).
 * The field also declares a `sanitize_callback`, which is the idiomatic WooCommerce
 * Settings API way to transform a value before `update_option()` ever sees it —
 * but live-confirmed 2026-09-29 against a real WooCommerce install that this
 * particular install does NOT invoke it for this field, so `save_settings()`
 * below re-reads and re-encrypts the option immediately after
 * `woocommerce_update_options()` runs, as the actual mechanism that makes this
 * work (see encrypt_stored_api_key()). `get_api_key()` decrypts on read. The field
 * intentionally still renders back whatever is currently stored (now ciphertext,
 * not plaintext) in the password input on reload — cosmetically a garbled string
 * rather than a masked placeholder, but harmless, since it's not the real secret;
 * `Chamilo_Crypto::encrypt()` recognizes its own already-encrypted format and
 * passes it through unchanged on an unmodified re-save, so this never risks
 * double-encrypting the stored value into something undecryptable.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Settings
{
	/**
	 * Chamilo course visibility values an admin can choose to sync, as
	 * value => label. Course::HIDDEN (4) is deliberately absent: it is never synced.
	 */
	public const COURSE_VISIBILITY_CHOICES = [
		3 => 'Public (open to the world)',
		2 => 'Open (open to platform users)',
		1 => 'Private (registered users only)',
		0 => 'Closed',
	];

	/** Synced when the admin has never saved a choice: Open and Private. */
	private const DEFAULT_COURSE_VISIBILITIES = [2, 1];

	public static function init(): void
	{
		add_action('woocommerce_admin_field_chamilo_course_visibilities', [self::class, 'render_course_visibilities_field']);
		add_filter('woocommerce_settings_tabs_array', [self::class, 'add_settings_tab'], 50);
		add_action('woocommerce_settings_tabs_chamilo', [self::class, 'render_settings_page']);
		add_action('woocommerce_update_options_chamilo', [self::class, 'save_settings']);
		add_action('wp_ajax_chamilo_test_connection', [self::class, 'ajax_test_connection']);
		add_action('wp_ajax_chamilo_sync_now', [self::class, 'ajax_sync_now']);
	}

	/**
	 * @param array<string, string> $tabs
	 * @return array<string, string>
	 */
	public static function add_settings_tab(array $tabs): array
	{
		$tabs['chamilo'] = __('Chamilo', 'chamilo');

		return $tabs;
	}

	public static function render_settings_page(): void
	{
		woocommerce_admin_fields(self::get_fields());

		$last_sync = get_option('chamilo_wp_last_sync_at', '');
		$last_sync_status = get_option('chamilo_wp_last_sync_status', '');
		?>
		<h2><?php esc_html_e('Connection tools', 'chamilo'); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e('Last catalog sync', 'chamilo'); ?></th>
				<td>
					<?php if ('' !== $last_sync) : ?>
						<?php echo esc_html($last_sync); ?> — <?php echo esc_html($last_sync_status); ?>
					<?php else : ?>
						<em><?php esc_html_e('Never run yet.', 'chamilo'); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th></th>
				<td>
					<button type="button" class="button" id="chamilo-test-connection">
						<?php esc_html_e('Test connection', 'chamilo'); ?>
					</button>
					<button type="button" class="button button-primary" id="chamilo-sync-now">
						<?php esc_html_e('Sync now', 'chamilo'); ?>
					</button>
					<span id="chamilo-connection-result"></span>
				</td>
			</tr>
		</table>
		<script>
		(function () {
			var nonce = <?php echo wp_json_encode(wp_create_nonce('chamilo_admin_action')); ?>;
			function run(action, button) {
				var result = document.getElementById('chamilo-connection-result');
				result.textContent = <?php echo wp_json_encode(__('Working…', 'chamilo')); ?>;
				var data = new FormData();
				data.append('action', action);
				data.append('nonce', nonce);
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) {
						return r.text().then(function (text) {
							return { status: r.status, text: text };
						});
					})
					.then(function (res) {
						var parsed;
						try {
							parsed = JSON.parse(res.text);
						} catch (e) {
							// Not JSON: either check_ajax_referer()/current_user_can()
							// rejected the request before any of our own code ran
							// (WordPress replies with a bare "-1" or "0", not JSON —
							// typically a stale nonce because this page was left open
							// a while), or something else printed output ahead of our
							// JSON response. Show the raw body so it's actually
							// diagnosable instead of a generic failure message.
							var trimmed = res.text.trim();
							var hint = ('-1' === trimmed || '0' === trimmed)
								? <?php echo wp_json_encode(__('This usually means the security token expired — reload this page and try again.', 'chamilo')); ?>
								: <?php echo wp_json_encode(__('The server did not return a valid response — check debug.log for a [Chamilo] line.', 'chamilo')); ?>;
							result.textContent = '✗ HTTP ' + res.status + ': ' + hint + ' (raw: ' + trimmed.substring(0, 200) + ')';

							return;
						}
						result.textContent = (parsed.success ? '✓ ' : '✗ ') + (parsed.data && parsed.data.message ? parsed.data.message : '');
					})
					.catch(function (err) {
						result.textContent = <?php echo wp_json_encode(__('Request failed:', 'chamilo')); ?> + ' ' + (err && err.message ? err.message : err);
					});
			}
			document.getElementById('chamilo-test-connection').addEventListener('click', function () {
				run('chamilo_test_connection');
			});
			document.getElementById('chamilo-sync-now').addEventListener('click', function () {
				run('chamilo_sync_now');
			});
		})();
		</script>
		<?php
	}

	public static function save_settings(): void
	{
		woocommerce_update_options(self::get_fields());
		self::save_course_visibilities();
		self::encrypt_stored_api_key();
		self::reschedule_sync();
	}

	/**
	 * Backstop for the API key field's `sanitize_callback` (which is *supposed* to
	 * encrypt the value before woocommerce_update_options() ever writes it — see
	 * sanitize_api_key()) — live-confirmed 2026-09-29 against a real WooCommerce
	 * install that the callback is NOT invoked here, so `update_option()` above
	 * always writes whatever was submitted as-is. Re-reads and re-writes the
	 * option through Chamilo_Crypto::encrypt() immediately afterward, in the same
	 * request, so the plaintext is on disk only for the instant between these two
	 * calls rather than indefinitely. encrypt() is idempotent, so this is safe to
	 * run unconditionally even if a future WooCommerce version does start honoring
	 * sanitize_callback and this becomes a harmless no-op.
	 */
	private static function encrypt_stored_api_key(): void
	{
		$raw = (string) get_option('chamilo_wp_service_account_api_key', '');
		if ('' === $raw) {
			return;
		}

		update_option('chamilo_wp_service_account_api_key', Chamilo_Crypto::encrypt($raw));
	}

	/**
	 * The "Sync interval" field alone did nothing until this existed: the recurring
	 * chamilo_wc_catalog_sync cron event is only ever scheduled once, at plugin
	 * activation (see chamilo.php), so changing this dropdown never actually touched
	 * the schedule — confirmed by reading chamilo_wc_activate() directly, it isn't a
	 * guess. Re-scheduling on every settings save (not just when the interval
	 * actually changed) is deliberately simple: the only side effect is resetting
	 * "next run" to a fresh countdown, which is a reasonable thing to happen right
	 * after an admin saves this screen anyway.
	 */
	private static function reschedule_sync(): void
	{
		wp_clear_scheduled_hook('chamilo_wc_catalog_sync');
		wp_schedule_event(time(), self::get_sync_interval(), 'chamilo_wc_catalog_sync');
	}

	public static function ajax_test_connection(): void
	{
		// Logged before check_ajax_referer() deliberately: that call wp_die()s
		// immediately on a bad/stale nonce, before anything else in this method
		// runs — if this line is the *only* one that shows up in debug.log, the
		// nonce check is what failed (typically: the settings page was left open
		// long enough for the nonce to expire — reloading the page fixes it).
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Chamilo] ajax_test_connection: request received');
		}

		check_ajax_referer('chamilo_admin_action', 'nonce');

		if (!current_user_can('manage_woocommerce')) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Chamilo] ajax_test_connection: current user lacks manage_woocommerce');
			}
			wp_send_json_error(['message' => __('Permission denied.', 'chamilo')], 403);
		}

		$client = new Chamilo_Api_Client();
		if (!$client->is_configured()) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'[Chamilo] ajax_test_connection: not configured (base_url=%s, api_key=%s)',
					'' !== self::get_base_url() ? 'set' : 'EMPTY',
					'' !== self::get_api_key() ? 'set' : 'EMPTY'
				));
			}
			wp_send_json_error(['message' => __('Base URL and API key must both be set first.', 'chamilo')]);
		}

		// Any authenticated, lightweight call proves the key + firewall wiring
		// work. The call itself (URL, HTTP status, response body) is already
		// logged by Chamilo_Api_Client::log_request() — no need to duplicate that
		// here, just the outcome.
		$result = $client->get('/api/course_categories', ['itemsPerPage' => 1]);
		if (is_wp_error($result)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('[Chamilo] ajax_test_connection: failed — %s', $result->get_error_message()));
			}
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Chamilo] ajax_test_connection: succeeded');
		}

		wp_send_json_success(['message' => __('Connected successfully.', 'chamilo')]);
	}

	public static function ajax_sync_now(): void
	{
		check_ajax_referer('chamilo_admin_action', 'nonce');
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => __('Permission denied.', 'chamilo')], 403);
		}

		$result = Chamilo_Catalog_Sync::run();
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success([
			'message' => sprintf(
				/* translators: 1: courses synced, 2: sessions synced */
				__('Synced %1$d course(s), %2$d session(s).', 'chamilo'),
				$result['courses'],
				$result['sessions']
			),
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_fields(): array
	{
		return [
			[
				'title' => __('Chamilo connection', 'chamilo'),
				'type' => 'title',
				'desc' => __('Connect this WooCommerce site to a Chamilo v3 portal. See the Chamilo admin for how to generate the API key (Administration → Platform Settings → Security, then the chamilo:security:generate-external-api-key console command).', 'chamilo'),
				'id' => 'chamilo_wp_options',
			],
			[
				'title' => __('Chamilo base URL', 'chamilo'),
				'id' => 'chamilo_wp_base_url',
				'type' => 'text',
				'placeholder' => 'https://campus.example.com',
				'desc' => __('No trailing slash needed.', 'chamilo'),
			],
			[
				'title' => __('Service account API key', 'chamilo'),
				'id' => 'chamilo_wp_service_account_api_key',
				'type' => 'password',
				'desc' => __('Generated on the Chamilo server via the chamilo:security:generate-external-api-key console command. Stored encrypted, not in plain text.', 'chamilo'),
				'sanitize_callback' => [self::class, 'sanitize_api_key'],
			],
			[
				'title' => __('Access URL (portal) ID', 'chamilo'),
				'id' => 'chamilo_wp_access_url_id',
				'type' => 'number',
				'default' => '1',
				'desc' => __('Leave at 1 unless this Chamilo install runs multiple portals.', 'chamilo'),
			],
			[
				'title' => __('Sync interval', 'chamilo'),
				'id' => 'chamilo_wp_sync_interval',
				'type' => 'select',
				'default' => 'hourly',
				'options' => [
					'hourly' => __('Hourly', 'chamilo'),
					'twicedaily' => __('Twice daily', 'chamilo'),
					'daily' => __('Daily', 'chamilo'),
				],
			],
			[
				'title' => __('Course visibilities to sync', 'chamilo'),
				'id' => 'chamilo_wp_course_visibilities',
				'type' => 'chamilo_course_visibilities',
				'desc' => __('Only courses with a checked visibility are pulled from Chamilo. Hidden courses are never synced.', 'chamilo'),
			],
			[
				'title' => __('Default product category', 'chamilo'),
				'id' => 'chamilo_wp_default_product_category_id',
				'type' => 'select',
				'class' => 'wc-enhanced-select',
				'options' => self::get_product_category_options(),
				'desc' => __('Fallback WooCommerce category when a Chamilo category has no mapping yet.', 'chamilo'),
			],
			[
				'type' => 'sectionend',
				'id' => 'chamilo_wp_options',
			],
		];
	}

	/**
	 * WooCommerce Settings API `sanitize_callback` for the API key field — runs on
	 * every save, before `update_option()`. See the class docblock for why this is
	 * where encryption happens, not a separate step after saving.
	 */
	public static function sanitize_api_key(?string $value): string
	{
		return Chamilo_Crypto::encrypt(null === $value ? '' : trim($value));
	}

	/**
	 * @return array<int|string, string>
	 */
	private static function get_product_category_options(): array
	{
		$options = ['' => __('— None —', 'chamilo')];
		$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
		if (is_array($terms)) {
			foreach ($terms as $term) {
				$options[$term->term_id] = $term->name;
			}
		}

		return $options;
	}

	public static function get_base_url(): string
	{
		return (string) get_option('chamilo_wp_base_url', '');
	}

	public static function get_api_key(): string
	{
		return Chamilo_Crypto::decrypt((string) get_option('chamilo_wp_service_account_api_key', ''));
	}

	public static function get_access_url_id(): int
	{
		return (int) get_option('chamilo_wp_access_url_id', 1);
	}

	public static function get_sync_interval(): string
	{
		return (string) get_option('chamilo_wp_sync_interval', 'hourly');
	}

	/**
	 * Custom WooCommerce Settings API field type: one checkbox per syncable course
	 * visibility. Rendered via the woocommerce_admin_field_{type} action.
	 *
	 * @param array<string, mixed> $field
	 */
	public static function render_course_visibilities_field(array $field): void
	{
		$selected = self::get_course_visibilities();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html((string) $field['title']); ?></th>
			<td class="forminp">
				<?php foreach (self::COURSE_VISIBILITY_CHOICES as $value => $label) : ?>
					<label style="display:block;margin-bottom:4px;">
						<input type="checkbox" name="chamilo_wp_course_visibilities[]"
							value="<?php echo esc_attr((string) $value); ?>"
							<?php checked(in_array($value, $selected, true)); ?>>
						<?php echo esc_html(__($label, 'chamilo')); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?>
					</label>
				<?php endforeach; ?>
				<p class="description"><?php echo esc_html((string) $field['desc']); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saved by hand rather than through woocommerce_update_options(): an
	 * all-unchecked group posts nothing at all, which must mean "none selected",
	 * not "leave the previous value".
	 */
	private static function save_course_visibilities(): void
	{
		$posted = isset($_POST['chamilo_wp_course_visibilities']) // phpcs:ignore WordPress.Security.NonceVerification -- verified by WooCommerce before this hook fires.
			? (array) wp_unslash($_POST['chamilo_wp_course_visibilities']) // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			: [];

		$values = array_values(array_intersect(
			array_map('intval', $posted),
			array_keys(self::COURSE_VISIBILITY_CHOICES)
		));

		update_option('chamilo_wp_course_visibilities', $values);
	}

	/**
	 * @return array<int, int> Chamilo course visibility values to sync.
	 */
	public static function get_course_visibilities(): array
	{
		$stored = get_option('chamilo_wp_course_visibilities', null);
		if (!is_array($stored)) {
			return self::DEFAULT_COURSE_VISIBILITIES;
		}

		return array_values(array_intersect(array_map('intval', $stored), array_keys(self::COURSE_VISIBILITY_CHOICES)));
	}

	public static function get_default_category_id(): int
	{
		return (int) get_option('chamilo_wp_default_product_category_id', 0);
	}
}
