<?php
/**
 * Order-complete → provision-or-resolve the buyer in Chamilo, enroll them, and build
 * the "Continue to your course" deep link. See plan §4 for the lifecycle this
 * implements and §3.2 for why the deep link is a plain login redirect, not real SSO.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Enrollment
{
	private const MAX_USERNAME_ATTEMPTS = 5;

	public static function init(): void
	{
		add_action('woocommerce_order_status_completed', [self::class, 'handle_order_completed']);
		add_action('woocommerce_order_status_processing', [self::class, 'handle_order_completed']);
		add_action('woocommerce_order_details_after_order_table', [self::class, 'render_continue_links']);
		add_action('woocommerce_thankyou', [self::class, 'render_continue_links_thankyou']);
	}

	public static function handle_order_completed(int $order_id): void
	{
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('[Chamilo] handle_order_completed: order #%d not found via wc_get_order()', $order_id));
			}

			return;
		}

		$client = new Chamilo_Api_Client();
		if (!$client->is_configured()) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('[Chamilo] handle_order_completed: order #%d skipped — Chamilo connection not configured', $order_id));
			}

			return;
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'[Chamilo] handle_order_completed: order #%d, status=%s, %d item(s)',
				$order_id,
				$order->get_status(),
				count($order->get_items())
			));
		}

		foreach ($order->get_items() as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			$is_chamilo_item = Chamilo_Order_Meta::is_chamilo_item($item);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				$product = $item->get_product();
				$product_id = $product instanceof WC_Product ? $product->get_id() : 0;
				error_log(sprintf(
					'[Chamilo] handle_order_completed: order #%d item #%d "%s" -> product_id=%d, product_type=%s, is_chamilo_item=%s',
					$order_id,
					$item->get_id(),
					$item->get_name(),
					$product_id,
					$product instanceof WC_Product ? $product->get_type() : 'n/a (product not found)',
					$is_chamilo_item ? 'true' : 'false'
				));

				// A product carrying Chamilo meta but NOT resolving as chamilo_course/
				// chamilo_session is the known "stale product_type term" bug (see D.4 in
				// README.md): WooCommerce only re-writes that taxonomy term when a
				// product is first created, so a term that went wrong at some point
				// stays wrong until the next sync's force_product_type_term() call.
				// Logging the raw postmeta (independent of which PHP class WooCommerce
				// decided to instantiate) and the raw taxonomy term(s) makes that
				// diagnosis possible straight from this log line, without needing to
				// separately inspect the product edit screen.
				if (!$is_chamilo_item && $product_id > 0) {
					$raw_course_id = get_post_meta($product_id, '_chamilo_course_id', true);
					$raw_session_id = get_post_meta($product_id, '_chamilo_session_id', true);
					$raw_terms = wp_get_object_terms($product_id, 'product_type', ['fields' => 'names']);
					error_log(sprintf(
						'[Chamilo] handle_order_completed: order #%d item #%d -> product_id=%d carries _chamilo_course_id=%s, _chamilo_session_id=%s, raw product_type term(s)=%s%s',
						$order_id,
						$item->get_id(),
						$product_id,
						'' !== (string) $raw_course_id ? $raw_course_id : '(none)',
						'' !== (string) $raw_session_id ? $raw_session_id : '(none)',
						is_wp_error($raw_terms) ? 'ERROR: '.$raw_terms->get_error_message() : implode(',', $raw_terms),
						('' !== (string) $raw_course_id || '' !== (string) $raw_session_id)
							? ' -- this product WAS synced by Chamilo; its product_type term is stale. Re-run Sync now, then Retry enrollment on this order.'
							: ' -- this product has no Chamilo meta at all; it is genuinely not a Chamilo item.'
					));
				}
			}

			if (!$is_chamilo_item) {
				continue;
			}

			if ('enrolled' === Chamilo_Order_Meta::get_status($item)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log(sprintf('[Chamilo] handle_order_completed: order #%d item #%d already enrolled, skipping', $order_id, $item->get_id()));
				}

				continue; // Already handled — e.g. "processing" then "completed" both fire this hook.
			}

			self::process_item($client, $order, $item);
		}
	}

	/**
	 * Public so the admin "Retry enrollment" action (Chamilo_Admin) can re-run a
	 * single failed item without resolving the buyer or re-processing the rest of
	 * the order.
	 */
	public static function process_item(Chamilo_Api_Client $client, WC_Order $order, WC_Order_Item_Product $item): void
	{
		$product = $item->get_product();
		if (!$product instanceof WC_Product) {
			return;
		}

		$product_type = 'chamilo_course' === $product->get_type() ? 'course' : 'session';
		$chamilo_id = 'chamilo_course' === $product->get_type()
			? $product->get_chamilo_course_id()
			: $product->get_chamilo_session_id();

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'[Chamilo] process_item: order #%d item #%d -> chamilo %s #%d',
				$order->get_id(),
				$item->get_id(),
				$product_type,
				$chamilo_id
			));
		}

		Chamilo_Order_Meta::init_ledger($item, $product_type, $chamilo_id);

		$user_result = self::resolve_or_create_user($client, $order);
		if (is_wp_error($user_result)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'[Chamilo] process_item: order #%d item #%d -> resolve_or_create_user FAILED: %s',
					$order->get_id(),
					$item->get_id(),
					$user_result->get_error_message()
				));
			}
			Chamilo_Order_Meta::mark_failed($item, $user_result->get_error_message());

			return;
		}

		$chamilo_user_id = $user_result['id'];
		$is_new_account = $user_result['is_new'];

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'[Chamilo] process_item: order #%d item #%d -> resolved Chamilo user #%d (%s)',
				$order->get_id(),
				$item->get_id(),
				$chamilo_user_id,
				$is_new_account ? 'newly created' : 'existing account'
			));
		}

		Chamilo_Order_Meta::mark_user_resolved($item, $chamilo_user_id);
		self::link_wp_user($order, $chamilo_user_id);

		$enroll_result = self::enroll($client, $chamilo_user_id, $product_type, $chamilo_id);
		if (is_wp_error($enroll_result)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'[Chamilo] process_item: order #%d item #%d -> enroll FAILED: %s',
					$order->get_id(),
					$item->get_id(),
					$enroll_result->get_error_message()
				));
			}
			Chamilo_Order_Meta::mark_failed($item, $enroll_result->get_error_message());

			return;
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf('[Chamilo] process_item: order #%d item #%d -> enrolled successfully', $order->get_id(), $item->get_id()));
		}

		Chamilo_Order_Meta::mark_enrolled($item);

		// A brand-new account already gets Chamilo's own "set your password" email,
		// which mentions its username (self::create_user()'s sendEmail=true below) —
		// only a pre-existing account is silently enrolled with zero Chamilo-side
		// signal, so only that case needs the explicit notification.
		if (!$is_new_account) {
			self::notify_existing_user_enrollment($client, $order, $item, $chamilo_user_id, $product_type, $chamilo_id);
		}
	}

	/**
	 * @return array{id: int, is_new: bool}|WP_Error Chamilo user id, and whether this
	 *                                                call just created the account.
	 */
	private static function resolve_or_create_user(Chamilo_Api_Client $client, WC_Order $order)
	{
		$email = $order->get_billing_email();
		if ('' === $email) {
			return new WP_Error('chamilo_no_email', __('Order has no billing email — cannot resolve a Chamilo account.', 'chamilo'));
		}

		// Exact-match lookup. Depends on User's SearchFilter using 'exact' for
		// 'email' (User.php's #[ApiFilter(SearchFilter::class)]) — a 'partial'
		// (LIKE '%value%') match could hit an unrelated account whose email merely
		// contains this string, either falsely tripping the "ambiguous" branch
		// below or, worse, silently resolving to the wrong account. Chamilo also
		// does not enforce unique emails at the schema level, so more than one
		// match can still be entirely genuine — treat it as ambiguous rather than
		// guessing.
		$existing = $client->get('/api/users', ['email' => $email]);
		if (is_wp_error($existing)) {
			return $existing;
		}

		$total = (int) ($existing['hydra:totalItems'] ?? 0);
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf('[Chamilo] resolve_or_create_user: email="%s" -> %d existing account(s)', $email, $total));
		}
		if (1 === $total) {
			return ['id' => (int) $existing['hydra:member'][0]['id'], 'is_new' => false];
		}
		if ($total > 1) {
			return new WP_Error(
				'chamilo_ambiguous_email',
				sprintf(
					/* translators: %s: buyer email */
					__('%d existing Chamilo accounts share this email — needs manual review, not auto-linked.', 'chamilo'),
					$total
				)
			);
		}

		$created = self::create_user($client, $order, $email);

		return is_wp_error($created) ? $created : ['id' => $created, 'is_new' => true];
	}

	/**
	 * Reminds a pre-existing Chamilo account that it was just silently enrolled, and
	 * which username the enrollment is linked to. Calls the callable-only
	 * EnrollmentNotificationService via POST /api/enrollment-notifications (Chamilo
	 * core) — see WORDPRESS_STOREFRONT_PLUGIN_PLAN.md for why this is never fired
	 * automatically from Chamilo's own side. A failure here is logged but never
	 * fails the enrollment itself: the notification is a courtesy, not part of the
	 * provisioning contract, and the item is already correctly marked "enrolled".
	 */
	private static function notify_existing_user_enrollment(
		Chamilo_Api_Client $client,
		WC_Order $order,
		WC_Order_Item_Product $item,
		int $chamilo_user_id,
		string $product_type,
		int $chamilo_id
	): void {
		$result = $client->post('/api/enrollment-notifications', [
			'user' => "/api/users/{$chamilo_user_id}",
			'itemType' => $product_type,
			'itemId' => $chamilo_id,
		]);

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'[Chamilo] notify_existing_user_enrollment: order #%d item #%d -> %s',
				$order->get_id(),
				$item->get_id(),
				is_wp_error($result) ? 'FAILED: '.$result->get_error_message() : 'sent'
			));
		}
	}

	/**
	 * @return int|WP_Error Chamilo user id.
	 */
	private static function create_user(Chamilo_Api_Client $client, WC_Order $order, string $email)
	{
		$access_url_id = Chamilo_Settings::get_access_url_id();
		$base_username = sanitize_user(current(explode('@', $email)), true);
		if ('' === $base_username) {
			$base_username = 'buyer';
		}

		for ($attempt = 0; $attempt < self::MAX_USERNAME_ATTEMPTS; ++$attempt) {
			$username = 0 === $attempt ? $base_username : $base_username.$attempt;

			$result = $client->post("/api/access_urls/{$access_url_id}/user", [
				'username' => $username,
				'email' => $email,
				'firstname' => $order->get_billing_first_name(),
				'lastname' => $order->get_billing_last_name(),
				// No password: Chamilo's own native reset-password flow sends the buyer
				// a set-your-password link instead — never a plaintext password
				// (plan §4, explicitly rejecting the old PrestaShop module's approach).
				'sendEmail' => true,
			]);

			if (!is_wp_error($result)) {
				return (int) $result['id'];
			}

			$status = (int) ($result->get_error_data()['status'] ?? 0);
			if (409 === $status && str_contains($result->get_error_message(), 'username_already_exists')) {
				continue; // Try the next suffixed username.
			}

			if (409 === $status && str_contains($result->get_error_message(), 'email_already_exists')) {
				// The lookup above should have caught this — if we're here, something
				// raced or the two email comparisons disagree (e.g. collation).
				// Don't guess which account: surface it for manual review.
				return new WP_Error(
					'chamilo_email_race',
					__('Chamilo reports this email already has an account, but it was not found by lookup — needs manual review.', 'chamilo')
				);
			}

			return $result; // Any other error: don't retry.
		}

		return new WP_Error('chamilo_username_exhausted', __('Could not find an available username after several attempts.', 'chamilo'));
	}

	/**
	 * @return true|WP_Error
	 */
	private static function enroll(Chamilo_Api_Client $client, int $chamilo_user_id, string $product_type, int $chamilo_id)
	{
		if ('course' === $product_type) {
			$result = $client->post('/api/course_rel_users', [
				'user' => "/api/users/{$chamilo_user_id}",
				'course' => "/api/courses/{$chamilo_id}",
				'relationType' => 5, // STUDENT — see plan §5.3/§6 item 4 (live-verified on-behalf-of enrollment).
				'status' => 5,
			]);
		} else {
			$result = $client->post('/api/session_rel_users', [
				'user' => "/api/users/{$chamilo_user_id}",
				'session' => "/api/sessions/{$chamilo_id}",
				'relationType' => 0, // STUDENT.
			]);
		}

		return is_wp_error($result) ? $result : true;
	}

	/**
	 * Links the buyer's WordPress account to the Chamilo user, per plan §5.4 — only
	 * possible for a real registered customer, not a guest checkout.
	 */
	private static function link_wp_user(WC_Order $order, int $chamilo_user_id): void
	{
		$wp_user_id = $order->get_customer_id();
		if (0 === $wp_user_id) {
			return;
		}

		update_user_meta($wp_user_id, 'chamilo_user_id', $chamilo_user_id);
		update_user_meta($wp_user_id, 'chamilo_access_url_id', Chamilo_Settings::get_access_url_id());
		if (!get_user_meta($wp_user_id, 'chamilo_account_linked_at', true)) {
			update_user_meta($wp_user_id, 'chamilo_account_linked_at', current_time('mysql'));
		}
	}

	/**
	 * Builds the "Continue to your course" deep link (plan §3.2) — a plain link to
	 * Chamilo's own login page with a `redirect` query param, never real SSO.
	 *
	 * Course target confirmed live: /courses/{code}/index.php 302-redirects to the
	 * real course home once logged in. The session target is deliberately the
	 * general session list (/sessions), not a deep link into the specific
	 * session — a student typically isn't enrolled in enough concurrent sessions
	 * for that to be a real hurdle, and it avoids depending on a specific
	 * session-detail URL shape that was never live-verified.
	 */
	public static function get_continue_link(WC_Order_Item_Product $item): ?string
	{
		$product = $item->get_product();
		if (!$product instanceof WC_Product) {
			return null;
		}

		$base_url = rtrim(Chamilo_Settings::get_base_url(), '/');
		if ('' === $base_url) {
			return null;
		}

		if ($product instanceof WC_Product_Chamilo_Course) {
			$target = '/courses/'.rawurlencode($product->get_chamilo_course_code()).'/index.php';
		} elseif ($product instanceof WC_Product_Chamilo_Session) {
			$target = '/sessions';
		} else {
			return null;
		}

		return $base_url.'/login?redirect='.rawurlencode($target);
	}

	public static function render_continue_links_thankyou(int $order_id): void
	{
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			self::render_continue_links($order);
		}
	}

	public static function render_continue_links(WC_Order $order): void
	{
		$links = [];
		foreach ($order->get_items() as $item) {
			if (!$item instanceof WC_Order_Item_Product || !Chamilo_Order_Meta::is_chamilo_item($item)) {
				continue;
			}
			if ('enrolled' !== Chamilo_Order_Meta::get_status($item)) {
				continue;
			}

			$link = self::get_continue_link($item);
			if (null !== $link) {
				$links[$item->get_name()] = $link;
			}
		}

		if ([] === $links) {
			return;
		}
		?>
		<section class="chamilo-continue-links">
			<h2><?php esc_html_e('Continue to your course', 'chamilo'); ?></h2>
			<ul>
				<?php foreach ($links as $name => $url) : ?>
					<li>
						<a href="<?php echo esc_url($url); ?>" class="button">
							<?php echo esc_html(sprintf(
								/* translators: %s: course or session name */
								__('Continue to: %s', 'chamilo'),
								$name
							)); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
