<?php
/**
 * Pulls the sellable catalog from Chamilo and upserts WooCommerce products.
 * See WORDPRESS_STOREFRONT_PLUGIN_PLAN.md §5.1/§5.1.1/§5.2 for the exact design this
 * implements.
 *
 * Courses and sessions are synced symmetrically, both read from their plain
 * authenticated collection endpoint (`/api/courses`, `/api/sessions`) rather than
 * Chamilo's own public-catalog machinery, and both gated only by `visibility`
 * excluding the closed/hidden-equivalent values.
 *
 * `price` is optional: when the `price` extra field is defined and an item has a
 * value for it (read via the ExtraFieldValues bulk-read pattern, §5.1.1), that
 * value becomes the product's regular price; otherwise the product is still
 * synced, with no price, for the WP admin to fill in (or not) before publishing.
 * `show_in_catalogue` is not read at all — the service account has admin-equivalent
 * access already, so it sees every non-hidden course/session regardless of that
 * flag; requiring it too would just duplicate a decision that belongs in WordPress.
 * New products sync in as **drafts** for exactly that reason — a WP admin reviews
 * `_chamilo_visibility` and decides what to publish (plan §5.1/§5.2/§9). Re-sync
 * never changes an existing product's status.
 *
 * Session capacity: Chamilo core has no max-subscribers field at all —
 * `_chamilo_capacity` is always left null (unlimited/in-stock); `_chamilo_seats_taken`
 * comes straight from `nbrUsers` on the collection response, no extra call needed.
 *
 * The product image behaves like price: refreshed from Chamilo on every sync
 * unless the product's own "Image locked from Chamilo sync" checkbox is set (see
 * class-chamilo-product-types.php). To avoid re-downloading an unchanged image on
 * every sync run, the source URL that produced the current image is remembered
 * (`_chamilo_image_source_url`) and re-import only happens when it changes.
 * Sessions import their image from `imageUrl` (a different field than course's
 * `illustrationUrl`). Course images are fetched with the service account's own
 * Bearer token (Chamilo_Api_Client::download_binary()) rather than WordPress's
 * anonymous media_sideload_image(), because a course's illustration is served
 * behind ResourceNodeVoter::VIEW, which denies anonymous requests for any course
 * visibility other than "Open to the World" — i.e. most courses actually sold
 * through a shop. Session images are unaffected by this specific access check
 * (served from the public, unauthenticated `/assets/...` route) but are routed
 * through the same authenticated download for consistent logging and to survive a
 * future change to that route.
 *
 * Every synced product is also tagged with the global "Package type" attribute
 * (Course/Session — see class-chamilo-product-attributes.php) so the storefront
 * can filter/display it, and is always marked Virtual (never Downloadable) since a
 * Chamilo course/session is never a shippable or file-download product.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Catalog_Sync
{
	private const COURSE_HIDDEN_VISIBILITIES = [0, 4]; // Course::CLOSED, Course::HIDDEN
	private const SESSION_HIDDEN_VISIBILITIES = [3];   // Session::INVISIBLE

	// Course::REGISTERED/OPEN_PLATFORM/OPEN_WORLD only — CLOSED/HIDDEN never reach
	// here (skipped in run()) so they need no term. Matches the "Course visibility"
	// attribute's terms registered in Chamilo_Product_Attributes.
	private const COURSE_VISIBILITY_TERM_SLUGS = [
		1 => 'registered',
		2 => 'open-platform',
		3 => 'open-world',
	];

	public static function init(): void
	{
		add_action('chamilo_wc_catalog_sync', [self::class, 'run']);
	}

	/**
	 * @return array{courses: int, sessions: int}|WP_Error
	 */
	public static function run()
	{
		$client = new Chamilo_Api_Client();
		if (!$client->is_configured()) {
			return new WP_Error('chamilo_not_configured', __('Chamilo connection is not configured.', 'chamilo'));
		}

		$access_url_id = Chamilo_Settings::get_access_url_id();

		// A missing `price` field (or a missing value for one item) is not an error:
		// those items just sync without a price.
		$course_price_values = self::fetch_field_values($client, 'price', 2);
		if (is_wp_error($course_price_values)) {
			return self::finish_with_error($course_price_values);
		}

		$session_price_values = self::fetch_field_values($client, 'price', 3);
		if (is_wp_error($session_price_values)) {
			return self::finish_with_error($session_price_values);
		}

		// Plain authenticated collection, not /public_courses: the service account
		// already has admin-equivalent access, so there's no reason to route through
		// Chamilo's own public-catalog helper (and its show_in_catalogue/category
		// settings meant for a different feature) — see class docblock.
		$courses = $client->get_all('/api/courses');
		if (is_wp_error($courses)) {
			return self::finish_with_error($courses);
		}

		$courses_synced = 0;
		$failures = [];
		foreach ($courses as $course) {
			$course_id = (int) ($course['id'] ?? 0);
			$visibility = (int) ($course['visibility'] ?? -1);
			if (0 === $course_id
				|| in_array($visibility, self::COURSE_HIDDEN_VISIBILITIES, true)
			) {
				continue; // Closed/Hidden: not for sale.
			}

			// Course.description (the plain field /api/courses itself returns) is
			// Chamilo's own auto-seeded placeholder at course-creation time, never
			// real content — the actual description lives in the separate "Course
			// Description" tool (CCourseDescription), concatenated across however
			// many sections exist. Unconditional overwrite, not "use if non-empty":
			// a course with zero sections should show no description, not silently
			// fall back to the placeholder this replaces (see
			// fetch_course_description()'s docblock).
			$course['description'] = self::fetch_course_description($client, $course_id, (string) ($course['description'] ?? ''));

			try {
				self::upsert_course($client, $course, self::parse_price($course_price_values[$course_id] ?? null), $access_url_id);
				++$courses_synced;
			} catch (\Throwable $e) {
				$failures[] = self::log_item_failure('course', $course_id, $e);
			}
		}

		$sessions = $client->get_all('/api/sessions');
		if (is_wp_error($sessions)) {
			return self::finish_with_error($sessions);
		}

		$sessions_synced = 0;
		foreach ($sessions as $session) {
			$session_id = (int) ($session['id'] ?? 0);
			$visibility = (int) ($session['visibility'] ?? -1);
			if (0 === $session_id
				|| in_array($visibility, self::SESSION_HIDDEN_VISIBILITIES, true)
			) {
				continue; // Invisible: not for sale.
			}

			// The bulk collection (/api/sessions) deliberately omits
			// displayStartDate/displayEndDate/duration — Chamilo's own GetCollection
			// operation is kept lean on purpose; the single-item endpoint carries
			// the fuller set. One extra call per
			// *eligible* session only (after the visibility filter above,
			// not for every session in the catalog) — acceptable at the catalog
			// sizes this plugin targets (get_all()'s own docblock: hundreds, not
			// tens of thousands). Best-effort: if this call fails, fall back to
			// the basic collection data rather than dropping the session from
			// sync entirely — it'll just be missing these three fields this run.
			$session_detail = $client->get("/api/sessions/{$session_id}");
			if (is_wp_error($session_detail)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log(sprintf(
						'[Chamilo] session #%d: detail fetch failed, falling back to basic collection data: %s',
						$session_id,
						$session_detail->get_error_message()
					));
				}
			} else {
				$session = $session_detail;
			}

			try {
				self::upsert_session($client, $session, self::parse_price($session_price_values[$session_id] ?? null), $access_url_id);
				++$sessions_synced;
			} catch (\Throwable $e) {
				$failures[] = self::log_item_failure('session', $session_id, $e);
			}
		}

		update_option('chamilo_wp_last_sync_at', current_time('mysql'));
		update_option('chamilo_wp_last_sync_status', sprintf(
			/* translators: 1: courses synced, 2: sessions synced, 3: failure summary (empty if none) */
			__('%1$d course(s), %2$d session(s)%3$s', 'chamilo'),
			$courses_synced,
			$sessions_synced,
			[] === $failures ? '' : sprintf(
				/* translators: 1: number of failed items, 2: comma-joined "type #id" list */
				__(' — %1$d failed (%2$s), see debug.log', 'chamilo'),
				count($failures),
				implode(', ', $failures)
			)
		));

		return ['courses' => $courses_synced, 'sessions' => $sessions_synced, 'failed' => count($failures)];
	}

	/**
	 * Resolves the extra field's id for the given (variable, itemType) pair, then
	 * bulk-fetches every value for it in one call, keyed by itemId — the pattern
	 * live-verified in plan §5.1.1/§6 rather than one API call per course/session.
	 *
	 * @return array<int, string>|WP_Error itemId => fieldValue
	 */
	private static function fetch_field_values(Chamilo_Api_Client $client, string $variable, int $item_type)
	{
		$fields = $client->get('/api/extra_fields', ['variable' => $variable, 'itemType' => $item_type]);
		if (is_wp_error($fields)) {
			return $fields;
		}

		$field = ($fields['hydra:member'][0] ?? null);
		if (null === $field) {
			// The extra field hasn't been defined on the Chamilo side yet (see
			// README.md, Part A.2) — not an error, just nothing to sync for it.
			return [];
		}

		$values = $client->get_all('/api/extra_field_values', ['field' => $field['id']]);
		if (is_wp_error($values)) {
			return $values;
		}

		$by_item_id = [];
		foreach ($values as $value) {
			$by_item_id[(int) $value['itemId']] = (string) $value['fieldValue'];
		}

		return $by_item_id;
	}

	/**
	 * Concatenates every section from Chamilo's Course Description tool
	 * (CCourseDescription — Description/Objectives/Topics/Methodology/Course
	 * material/Resources/Assessment/Other, zero or more per course) into one HTML
	 * block: the real course description is the *sum* of these sections, not any
	 * single one, and not Course.description — Chamilo auto-seeds that plain field
	 * with a literal translated placeholder string ("Course Description") at
	 * course-creation time, never meant to be shown as real content.
	 *
	 * Each section keeps its own heading (its `title`, already HTML-escaped
	 * server-side by CourseDescriptionListProvider) so a multi-section result
	 * doesn't read as one undifferentiated blob; sections with no content are
	 * skipped. Zero sections with content (a course that never used the tool at
	 * all) deliberately returns '' rather than falling back to Course.description —
	 * that field is exactly the placeholder this is meant to avoid showing.
	 *
	 * The service account's ROLE_ADMIN satisfies this endpoint's access check
	 * unconditionally (see CourseDescriptionHelper::canRead()), so no
	 * course-context subtlety applies here.
	 *
	 * @param string $fallback Used only if the API call itself fails (network/
	 *                         auth/server error) — a transient failure is not a
	 *                         reason to blank out an existing description.
	 */
	private static function fetch_course_description(Chamilo_Api_Client $client, int $course_id, string $fallback): string
	{
		$result = $client->get('/api/course-description/list', ['cid' => $course_id]);
		if (is_wp_error($result)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'[Chamilo] course #%d: description-tool fetch failed, falling back to previous description: %s',
					$course_id,
					$result->get_error_message()
				));
			}

			return $fallback;
		}

		$blocks = [];
		foreach ((array) ($result['items'] ?? []) as $item) {
			$content = self::strip_html_document_wrapper(trim((string) ($item['content'] ?? '')));
			if ('' === $content) {
				continue;
			}

			$title = trim((string) ($item['title'] ?? ''));
			$blocks[] = '' !== $title ? "<h3>{$title}</h3>{$content}" : $content;
		}

		return implode('', $blocks);
	}

	/**
	 * Chamilo's Course Description tool stores `content` wrapped as a *complete*
	 * HTML document (`<!doctype html><html><head>...<body>...</body></html>`)
	 * even though it's meant to be embedded as a fragment inside another page —
	 * embedding a full nested document inside a WooCommerce product page (itself
	 * already a complete HTML
	 * document) is invalid HTML and renders inconsistently across browsers.
	 * Strips only the known boilerplate prefix/suffix, keeping everything in
	 * between — including the per-language "mce-translatehtml" wrapper div
	 * Chamilo's own editor adds, which is harmless to keep (just a div with a
	 * lang attribute). If no <body> tag is present at all (a plain, unwrapped
	 * fragment — e.g. an older section predating this convention), the input is
	 * returned unchanged: the regex simply doesn't match.
	 */
	private static function strip_html_document_wrapper(string $html): string
	{
		$html = (string) preg_replace('#^.*?<body[^>]*>#is', '', $html, 1);
		$html = (string) preg_replace('#</body>\s*</html>\s*$#is', '', $html, 1);

		return trim($html);
	}

	/**
	 * Turns a raw `price` extra-field value into a float, or null when there is no
	 * usable value (field undefined, no value for this item, blank, or non-numeric)
	 * — in which case the product's existing price is left untouched.
	 */
	private static function parse_price(?string $raw): ?float
	{
		$raw = trim((string) $raw);
		if ('' === $raw) {
			return null;
		}
		$raw = str_replace(',', '.', $raw);

		return is_numeric($raw) ? (float) $raw : null;
	}

	/**
	 * @param array<string, mixed> $course
	 */
	private static function upsert_course(Chamilo_Api_Client $client, array $course, ?float $price, int $access_url_id): void
	{
		$course_id = (int) $course['id'];
		$product = self::find_product('_chamilo_course_id', $course_id, WC_Product_Chamilo_Course::class);
		$is_new = !$product instanceof WC_Product_Chamilo_Course;
		if ($is_new) {
			$product = new WC_Product_Chamilo_Course();
		}

		$product->set_name((string) ($course['title'] ?? $course['code'] ?? ('Course #'.$course_id)));
		self::sync_description($product, (string) ($course['description'] ?? ''));
		// The Chamilo course id, not the course code — guaranteed unique and stable
		// (it's the same value _chamilo_course_id is keyed on). Prefixed "c":
		// course ids and session ids are separate sequences in Chamilo, both
		// starting at 1, so a bare id would collide across the two product types
		// (course #1 and session #1 both wanting SKU "1"). "c"/"s" prefixes keep
		// the two id spaces disjoint.
		self::set_sku_if_unique($product, 'c'.$course_id, 'course', $course_id);
		$product->set_catalog_visibility('visible');
		$product->set_virtual(true); // Never shippable — see class docblock.

		// Only ever set on creation — a re-sync must never quietly draft a product an
		// admin already published, nor publish one they deliberately left as a draft.
		if ($is_new) {
			$product->set_status('draft');
		}

		if (null !== $price && !$product->is_chamilo_price_override_locked()) {
			$product->set_regular_price((string) $price);
		}

		$product->set_chamilo_course_id($course_id);
		$product->set_chamilo_course_code((string) ($course['code'] ?? ''));
		$product->set_chamilo_access_url_id($access_url_id);
		$product->set_chamilo_last_synced_at(current_time('c'));
		$product->update_meta_data('_chamilo_iri', "/api/courses/{$course_id}");
		$product->update_meta_data('_chamilo_visibility', (int) ($course['visibility'] ?? -1));

		$teacher_names = array_filter(array_map(
			static fn (array $t): string => trim((string) ($t['fullName'] ?? '')),
			(array) ($course['teachers'] ?? [])
		));
		if ([] !== $teacher_names) {
			$product->update_meta_data('_chamilo_teacher_names', implode(', ', $teacher_names));
		}

		$product->save();
		self::force_product_type_term($product);

		self::sync_image($client, $product, (string) ($course['illustrationUrl'] ?? ''));
		self::assign_categories($product, (array) ($course['categoryTitles'] ?? []));
		Chamilo_Product_Attributes::assign($product, 'package-type', 'course');

		$visibility_slug = self::COURSE_VISIBILITY_TERM_SLUGS[(int) ($course['visibility'] ?? -1)] ?? null;
		if (null !== $visibility_slug) {
			Chamilo_Product_Attributes::assign($product, 'visibility', $visibility_slug);
		}

		// Local attribute, not global: like the session dates, a course code is
		// unique per course, not a shared vocabulary — a global attribute would
		// accumulate one throwaway term per course. Courses only — sessions have
		// no equivalent code.
		Chamilo_Product_Attributes::assign_custom($product, __('Course code', 'chamilo'), (string) ($course['code'] ?? ''));
	}

	/**
	 * @param array<string, mixed> $session
	 */
	private static function upsert_session(Chamilo_Api_Client $client, array $session, ?float $price, int $access_url_id): void
	{
		$session_id = (int) $session['id'];
		$product = self::find_product('_chamilo_session_id', $session_id, WC_Product_Chamilo_Session::class);
		$is_new = !$product instanceof WC_Product_Chamilo_Session;
		if ($is_new) {
			$product = new WC_Product_Chamilo_Session();
		}

		$product->set_name((string) ($session['title'] ?? ('Session #'.$session_id)));
		self::sync_description($product, (string) ($session['description'] ?? ''));
		// The Chamilo session id — Session has no resource node of its own (does
		// not implement ResourceInterface, unlike Course), so the entity id is the
		// right, and only, stable numeric identifier available. Prefixed "s" (see
		// upsert_course()'s comment): course ids and session ids are separate
		// sequences, both starting at 1 — a bare id would let session #1 collide
		// with course #1's own SKU "1".
		self::set_sku_if_unique($product, 's'.$session_id, 'session', $session_id);
		$product->set_catalog_visibility('visible');
		$product->set_virtual(true); // Never shippable — see class docblock.

		if ($is_new) {
			$product->set_status('draft'); // Same reasoning as upsert_course() — see there.
		}

		if (null !== $price && !$product->is_chamilo_price_override_locked()) {
			$product->set_regular_price((string) $price);
		}

		$product->set_chamilo_session_id($session_id);
		$product->set_chamilo_access_url_id($access_url_id);
		$product->set_chamilo_seats_taken((int) ($session['nbrUsers'] ?? 0));
		$product->set_chamilo_capacity(null); // No source field in Chamilo core — see class docblock.
		$product->sync_stock_status_from_capacity();
		$product->update_meta_data('_chamilo_iri', "/api/sessions/{$session_id}");
		$product->update_meta_data('_chamilo_visibility', (int) ($session['visibility'] ?? -1));
		$product->set_chamilo_last_synced_at(current_time('c'));

		if (!empty($session['displayStartDate'])) {
			$product->update_meta_data('_chamilo_start_date', (string) $session['displayStartDate']);
		}
		if (!empty($session['displayEndDate'])) {
			$product->update_meta_data('_chamilo_end_date', (string) $session['displayEndDate']);
		}

		// Category assignment (plan §5.2, _chamilo_category_code) is not implemented
		// for sessions: unlike Course's `categoryTitles`, the session category
		// field's exact JSON shape on /api/sessions has not been verified — confirm
		// it before implementing rather than guess a field name. Sessions fall back
		// to the default product category (plan §5.5).
		$default_category = Chamilo_Settings::get_default_category_id();
		if (0 !== $default_category && [] === $product->get_category_ids()) {
			$product->set_category_ids([$default_category]);
		}

		$product->save();
		self::force_product_type_term($product);

		self::sync_image($client, $product, (string) ($session['imageUrl'] ?? ''));
		Chamilo_Product_Attributes::assign($product, 'package-type', 'session');
		self::sync_session_date_attributes($product, $session);
	}

	/**
	 * Exposes the session's display dates, and (only when the session is
	 * duration-based) its duration in days, as local product attributes so a
	 * shopper actually sees them on the product page — the existing
	 * _chamilo_start_date/_chamilo_end_date meta (above) is internal-only and isn't
	 * rendered anywhere by default. Display dates are always shown; duration only
	 * when Session.duration > 0 (Chamilo's own flag for "access is duration-based,
	 * not fixed-dates" — see Session::getDaysLeft() / the duration branch in
	 * Session.php). No other date fields are exposed.
	 *
	 * @param array<string, mixed> $session
	 */
	private static function sync_session_date_attributes(WC_Product $product, array $session): void
	{
		$start = !empty($session['displayStartDate']) ? self::format_date_for_attribute((string) $session['displayStartDate']) : '';
		$end = !empty($session['displayEndDate']) ? self::format_date_for_attribute((string) $session['displayEndDate']) : '';
		$duration = (int) ($session['duration'] ?? 0);

		Chamilo_Product_Attributes::assign_custom($product, __('Access start date', 'chamilo'), $start);
		Chamilo_Product_Attributes::assign_custom($product, __('Access end date', 'chamilo'), $end);
		Chamilo_Product_Attributes::assign_custom(
			$product,
			__('Access duration (days)', 'chamilo'),
			$duration > 0 ? (string) $duration : ''
		);
	}

	/**
	 * Formats an ISO 8601 datetime from the Chamilo API using the site's own
	 * configured date format/locale, rather than showing the raw ISO string to a
	 * shopper.
	 */
	private static function format_date_for_attribute(string $iso_datetime): string
	{
		$timestamp = strtotime($iso_datetime);

		return false === $timestamp ? $iso_datetime : date_i18n(get_option('date_format', 'Y-m-d'), $timestamp);
	}

	/**
	 * WooCommerce enforces unique SKUs across every product by default —
	 * `$product->set_sku()` throws an uncaught WC_Data_Exception if the code is
	 * already used by a *different* product, which would otherwise crash an entire
	 * "Sync now" run, including every course/session after the offending one. A
	 * stray/duplicate WooCommerce product left over from earlier testing is the
	 * usual cause. Checking first with wc_product_has_unique_sku() avoids the
	 * crash outright; the try/catch in run() is a backstop for anything else this
	 * doesn't anticipate.
	 */
	private static function set_sku_if_unique(WC_Product $product, string $sku, string $kind, int $chamilo_id): void
	{
		if ('' === $sku) {
			return;
		}

		if (function_exists('wc_product_has_unique_sku') && !wc_product_has_unique_sku($product->get_id(), $sku)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'[Chamilo] skipped SKU "%s" for %s #%d — already used by a different WooCommerce product; check Products for a duplicate/leftover entry',
					$sku,
					$kind,
					$chamilo_id
				));
			}

			return;
		}

		$product->set_sku($sku);
	}

	/**
	 * Forces the WooCommerce `product_type` taxonomy term to match $product->get_type()
	 * unconditionally, instead of trusting WC_Product_Data_Store_CPT::update() to do
	 * it. That data store only re-writes this term when 'type' or 'virtual' appears
	 * in the product's own tracked changes (create() force-writes it, update()
	 * doesn't) — but get_type() on WC_Product_Chamilo_Course/Session is a hardcoded
	 * method override, never a tracked prop, so it can never appear as a "change" —
	 * a product can otherwise revert to (or never correctly receive) this term and
	 * resolve as plain WC_Product_Simple everywhere, including from an order line
	 * item, which is why a real order for one could show "no Chamilo products in
	 * this order" despite the product being correctly tagged in every other
	 * respect (price, image, "Package type" attribute). Calling this after every
	 * save closes the gap and self-corrects any product already stuck in that
	 * state.
	 */
	private static function force_product_type_term(WC_Product $product): void
	{
		wp_set_object_terms($product->get_id(), [$product->get_type()], 'product_type');
	}

	private static function log_item_failure(string $kind, int $chamilo_id, \Throwable $e): string
	{
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf('[Chamilo] sync failed for %s #%d: %s', $kind, $chamilo_id, $e->getMessage()));
		}

		return sprintf('%s #%d', $kind, $chamilo_id);
	}

	/**
	 * Matches purely on the identifying Chamilo meta, via plain WordPress
	 * get_posts() — deliberately not wc_get_products()/WC_Product_Query, which does
	 * not reliably return a match here, most likely because chamilo_course/
	 * chamilo_session are deliberately never registered into WooCommerce's "known
	 * product types" list (see class-chamilo-product-types.php) and
	 * WC_Product_Query filters by it. get_posts() has no such awareness.
	 *
	 * Also never matches on the product's currently-resolved PHP class — a row can
	 * exist with the right meta but a wrong `product_type` term (see
	 * force_product_type_term()); constructing the expected class directly by id
	 * loads it via the data store's own read-by-id, independent of that term.
	 *
	 * @return ?WC_Product The matching product, or null if none exists yet.
	 */
	private static function find_product(string $meta_key, int $meta_value, string $expected_class): ?WC_Product
	{
		$posts = get_posts([
			'post_type' => 'product',
			'post_status' => 'any',
			'meta_key' => $meta_key, // phpcs:ignore -- exact lookup, not a query loop.
			'meta_value' => $meta_value,
			'numberposts' => 1,
			'fields' => 'ids',
			'cache_results' => false,
			'suppress_filters' => true, // get_posts()'s own default — skip any pre_get_posts/posts_where hooks.
		]);
		$ids = array_map('intval', $posts);

		if ([] === $ids) {
			return null;
		}

		$product_id = $ids[0];

		/** @var WC_Product $product */
		$product = new $expected_class($product_id);

		if (0 === $product->get_id()) {
			return null; // Row vanished between the query above and here.
		}

		// Idempotent — cheap to always reassert, and closes the gap regardless of
		// whether the term was already right.
		wp_set_object_terms($product_id, [$product->get_type()], 'product_type');

		return $product;
	}

	/**
	 * @param array<int, string> $titles
	 */
	private static function assign_categories(WC_Product $product, array $titles): void
	{
		if ([] === $titles) {
			$default = Chamilo_Settings::get_default_category_id();
			if (0 !== $default) {
				$product->set_category_ids([$default]);
				$product->save();
			}

			return;
		}

		$term_ids = [];
		foreach ($titles as $title) {
			$term = get_term_by('name', $title, 'product_cat');
			if (false === $term) {
				$inserted = wp_insert_term($title, 'product_cat');
				if (!is_wp_error($inserted)) {
					$term_ids[] = (int) $inserted['term_id'];
				}
			} else {
				$term_ids[] = (int) $term->term_id;
			}
		}

		if ([] !== $term_ids) {
			$product->set_category_ids($term_ids);
			$product->save();
		}
	}

	/**
	 * `illustrationUrl` (and similar Chamilo-served asset paths) comes back as a
	 * root-relative path (e.g. "/r/asset/illustrations/.../view"), not a full URL.
	 * `media_sideload_image()` needs an absolute URL to fetch, so this must be
	 * resolved against the configured Chamilo base URL before use; passing the
	 * relative path straight through silently fails to import.
	 */
	private static function absolute_chamilo_url(string $path): string
	{
		if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
			return $path;
		}

		return rtrim(Chamilo_Settings::get_base_url(), '/').'/'.ltrim($path, '/');
	}

	/**
	 * Refreshes the product description from Chamilo unless it's been edited
	 * locally since the last sync — same "sync unless overridden" spirit as
	 * price/image, but detected implicitly (comparing the current description
	 * against a snapshot of what sync itself last set) rather than via a
	 * separate lock checkbox. Emptying the description in WooCommerce is how an
	 * admin asks for it to be recovered from Chamilo again — a checkbox to
	 * un-tick first would be one extra step for no benefit over "just clear the
	 * field."
	 *
	 * A brand-new product's description and _chamilo_description_last_synced
	 * are both '' by construction, so the "current is empty" branch already
	 * covers first sync without any special-casing for $is_new.
	 */
	private static function sync_description(WC_Product $product, string $new_description): void
	{
		$current = trim((string) $product->get_description());
		$last_synced = (string) $product->get_meta('_chamilo_description_last_synced', true);

		if ('' !== $current && $current !== $last_synced) {
			return; // Edited locally since the last sync — leave it alone.
		}

		$product->set_description($new_description);
		$product->update_meta_data('_chamilo_description_last_synced', $new_description);
	}

	/**
	 * Refreshes a product's image from Chamilo — same "sync unless locked" behavior
	 * as price (see is_chamilo_price_override_locked()). Re-download only happens
	 * when the source URL actually changed since the last successful import, so an
	 * unlocked, unchanged image isn't re-fetched on every sync run.
	 *
	 * @param WC_Product_Chamilo_Course|WC_Product_Chamilo_Session $product
	 */
	private static function sync_image(Chamilo_Api_Client $client, $product, string $source_url): void
	{
		if ($product->is_chamilo_image_override_locked() || '' === $source_url) {
			return;
		}

		$absolute_url = self::absolute_chamilo_url($source_url);

		if ($absolute_url === $product->get_chamilo_image_source_url() && 0 !== $product->get_image_id()) {
			return; // Unchanged since the last import — don't re-download every sync.
		}

		$attachment_id = self::download_and_attach_image($client, $absolute_url, $product->get_id());
		if (0 === $attachment_id) {
			return; // Failure already logged inside download_and_attach_image().
		}

		$product->set_image_id($attachment_id);
		$product->set_chamilo_image_source_url($absolute_url);
		$product->save();
	}

	/**
	 * Downloads and attaches an image using Chamilo_Api_Client::download_binary()
	 * (the service account's own Bearer token — see that method's docblock for why
	 * this matters for course illustrations specifically) rather than
	 * WordPress's media_sideload_image(), which makes an anonymous request and
	 * silently fails for any course that isn't "Open to the World" visibility.
	 *
	 * Best-effort — failures here should never abort the sync of everything else, so
	 * this deliberately swallows errors (after logging them) rather than propagating
	 * a WP_Error up through upsert_course()/upsert_session().
	 */
	private static function download_and_attach_image(Chamilo_Api_Client $client, string $url, int $product_id): int
	{
		if (!function_exists('media_handle_sideload')) {
			require_once ABSPATH.'wp-admin/includes/media.php';
			require_once ABSPATH.'wp-admin/includes/file.php';
			require_once ABSPATH.'wp-admin/includes/image.php';
		}

		$download = $client->download_binary($url);
		if (is_wp_error($download)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('[Chamilo] image import failed for %s: %s', $url, $download->get_error_message()));
			}

			return 0;
		}

		$tmp = wp_tempnam($url);
		if (false === file_put_contents($tmp, $download['body'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('[Chamilo] image import failed for %s: could not write temp file', $url));
			}

			return 0;
		}

		$filename = wp_basename((string) parse_url($url, PHP_URL_PATH));
		if ('' === $filename || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $filename)) {
			$filename = 'chamilo-image'.self::extension_from_content_type($download['content_type']);
		}

		$attachment_id = media_handle_sideload(['name' => $filename, 'tmp_name' => $tmp], $product_id);

		if (is_wp_error($attachment_id)) {
			if (file_exists($tmp)) {
				wp_delete_file($tmp);
			}
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('[Chamilo] media_handle_sideload failed for %s: %s', $url, $attachment_id->get_error_message()));
			}

			return 0;
		}

		return (int) $attachment_id;
	}

	private static function extension_from_content_type(string $content_type): string
	{
		$map = [
			'image/jpeg' => '.jpg',
			'image/png' => '.png',
			'image/gif' => '.gif',
			'image/webp' => '.webp',
		];

		$mime = strtolower(trim(explode(';', $content_type)[0]));

		return $map[$mime] ?? '.jpg';
	}

	/**
	 * @return WP_Error
	 */
	private static function finish_with_error(WP_Error $error): WP_Error
	{
		update_option('chamilo_wp_last_sync_at', current_time('mysql'));
		update_option('chamilo_wp_last_sync_status', sprintf(
			/* translators: %s: error message */
			__('Failed: %s', 'chamilo'),
			$error->get_error_message()
		));

		return $error;
	}
}
