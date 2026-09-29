<?php
/**
 * Thin wrapper around Chamilo's API Platform endpoints (JSON-LD/Hydra), authenticated
 * with the service account's "external" API key (see WORDPRESS_STOREFRONT_PLUGIN_PLAN.md
 * §3.1 and §6 item 3 — Chamilo\CoreBundle\Security\Authenticator\ExternalApiKeyAuthenticator).
 *
 * Every call happens server-side, from WordPress's own backend (cron jobs, order hooks) —
 * never from the visitor's browser — so the API key never leaves the server.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Api_Client
{
	/** @var string|null */
	private $base_url;

	/** @var string|null */
	private $api_key;

	public function __construct(?string $base_url = null, ?string $api_key = null)
	{
		$this->base_url = null !== $base_url ? rtrim($base_url, '/') : rtrim((string) Chamilo_Settings::get_base_url(), '/');
		$this->api_key = $api_key ?? Chamilo_Settings::get_api_key();
	}

	public function is_configured(): bool
	{
		return '' !== $this->base_url && '' !== (string) $this->api_key;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>|WP_Error Decoded JSON-LD body, or WP_Error on failure.
	 */
	public function get(string $path, array $query = [])
	{
		$url = $this->build_url($path, $query);
		if (is_wp_error($url)) {
			return $url;
		}

		$response = wp_remote_get($url, $this->request_args());
		self::log_request('GET', $url, $response);

		return $this->handle_response($response);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|WP_Error Decoded JSON-LD body, or WP_Error on failure.
	 */
	public function post(string $path, array $body)
	{
		$url = $this->build_url($path, []);
		if (is_wp_error($url)) {
			return $url;
		}

		$args = $this->request_args();
		$args['method'] = 'POST';
		$args['headers']['Content-Type'] = 'application/ld+json';
		$args['body'] = wp_json_encode($body);

		$response = wp_remote_post($url, $args);
		self::log_request('POST', $url, $response);

		return $this->handle_response($response);
	}

	/**
	 * Fetches every page of a Hydra collection, following "hydra:view"/"hydra:next".
	 * Chamilo catalogs are expected to be small enough (hundreds, not tens of
	 * thousands of courses/sessions) that this is fine as a synchronous loop; if a
	 * portal's catalog grows large enough for this to matter, that's a real scaling
	 * note for a later phase, not a v1 concern.
	 *
	 * @param array<string, mixed> $query
	 * @return array<int, array<string, mixed>>|WP_Error Every "hydra:member" row across all pages.
	 */
	public function get_all(string $path, array $query = [])
	{
		$members = [];
		$next = $this->build_url($path, $query);
		if (is_wp_error($next)) {
			return $next;
		}

		$guard = 0;
		while (null !== $next && $guard < 200) {
			++$guard;
			$response = wp_remote_get($next, $this->request_args());
			self::log_request('GET', $next, $response, "page {$guard}");
			$decoded = $this->handle_response($response);
			if (is_wp_error($decoded)) {
				return $decoded;
			}

			foreach ((array) ($decoded['hydra:member'] ?? []) as $member) {
				$members[] = $member;
			}

			$next_path = $decoded['hydra:view']['hydra:next'] ?? null;
			$next = null !== $next_path ? $this->absolute_url((string) $next_path) : null;
		}

		return $members;
	}

	/**
	 * Downloads a binary resource (course/session illustration).
	 *
	 * For a course illustration (`/r/asset/illustrations/.../view`), Chamilo denies
	 * an anonymous request unless the course's own visibility is "Open to the
	 * World" — most courses actually sold through a shop aren't — so this sends
	 * our service account's own Bearer token, same as every other API call.
	 *
	 * For a session's `imageUrl` (served from `/assets/...`), the opposite is true:
	 * that route requires no authentication at all (`AssetController::showFile` has
	 * no access check), and sending a Bearer token there is actively harmful, not
	 * merely unnecessary — Chamilo's `main` firewall
	 * (everything outside `/api`) only has the stock JWT authenticator wired up,
	 * which matches ANY bearer token and rejects ours with 401 "Invalid JWT Token"
	 * regardless of whether the route itself needed a credential. So the
	 * Authorization header is omitted specifically for `/assets/...` URLs.
	 *
	 * @return array{body: string, content_type: string}|WP_Error
	 */
	public function download_binary(string $url)
	{
		$args = $this->request_args();
		unset($args['headers']['Accept']);

		if (false !== strpos((string) parse_url($url, PHP_URL_PATH), '/assets/')) {
			unset($args['headers']['Authorization']);
		}

		$response = wp_remote_get($url, $args);
		self::log_request('GET', $url, $response, 'binary download');

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			return new WP_Error(
				'chamilo_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: URL */
					__('Chamilo returned HTTP %1$d while downloading %2$s', 'chamilo'),
					$code,
					$url
				),
				['status' => $code]
			);
		}

		return [
			'body' => wp_remote_retrieve_body($response),
			'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
		];
	}

	/**
	 * @return string|WP_Error
	 */
	private function build_url(string $path, array $query)
	{
		if (!$this->is_configured()) {
			return new WP_Error(
				'chamilo_not_configured',
				__('Chamilo connection is not configured (base URL / API key missing).', 'chamilo')
			);
		}

		$url = $this->base_url.'/'.ltrim($path, '/');
		if ([] !== $query) {
			$url = add_query_arg($query, $url);
		}

		return $url;
	}

	private function absolute_url(string $path): string
	{
		if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
			return $path;
		}

		return $this->base_url.'/'.ltrim($path, '/');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_args(): array
	{
		return [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer '.$this->api_key,
				'Accept' => 'application/ld+json',
			],
		];
	}

	/**
	 * Logs every outbound call and its outcome to WordPress's own debug log — gated
	 * behind WP_DEBUG so nothing extra is written (or leaked) on a production site
	 * that hasn't turned it on. WP_DEBUG_LOG alone does NOT capture this: it only
	 * catches PHP errors/warnings/notices, never a plugin's own outbound HTTP calls,
	 * so without this a failing request is invisible in debug.log no matter how
	 * debug logging is configured.
	 *
	 * @param array<string, mixed>|WP_Error $response
	 */
	private static function log_request(string $method, string $url, $response, string $note = ''): void
	{
		if (!defined('WP_DEBUG') || !WP_DEBUG) {
			return;
		}

		$suffix = '' !== $note ? " ({$note})" : '';

		if (is_wp_error($response)) {
			error_log(sprintf(
				'[Chamilo] %s %s%s -> transport error: %s',
				$method,
				$url,
				$suffix,
				$response->get_error_message()
			));

			return;
		}

		error_log(sprintf(
			'[Chamilo] %s %s%s -> HTTP %d, body: %s',
			$method,
			$url,
			$suffix,
			(int) wp_remote_retrieve_response_code($response),
			mb_substr(wp_remote_retrieve_body($response), 0, 500)
		));
	}

	/**
	 * @param array<string, mixed>|WP_Error $response
	 * @return array<string, mixed>|WP_Error
	 */
	private function handle_response($response)
	{
		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw_body = wp_remote_retrieve_body($response);
		$decoded = json_decode($raw_body, true);

		if ($code >= 400) {
			$message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['hydra:description'] ?? '') : '';

			return new WP_Error(
				'chamilo_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message from Chamilo */
					__('Chamilo API returned HTTP %1$d: %2$s', 'chamilo'),
					$code,
					'' !== $message ? $message : __('(no error message returned)', 'chamilo')
				),
				['status' => $code]
			);
		}

		if ('' === trim($raw_body)) {
			// A success response with no body — e.g. an `output: false` API Platform
			// operation such as /api/enrollment-notifications, which returns 200 with
			// nothing to decode. The call still succeeded.
			return [];
		}

		if (!is_array($decoded)) {
			return new WP_Error('chamilo_api_invalid_response', __('Chamilo API returned a non-JSON response.', 'chamilo'));
		}

		return $decoded;
	}
}
