<?php
/**
 * Encrypts/decrypts secrets (currently just the Chamilo service-account API key)
 * before they reach a WordPress option row, using libsodium's secretbox
 * (XSalsa20-Poly1305 authenticated symmetric encryption).
 *
 * Key material is derived from wp_salt('auth') — the same secret WordPress itself
 * uses to sign auth cookies, and, on essentially every real install, sourced from
 * the AUTH_KEY/AUTH_SALT constants in wp-config.php: a file, not a database row
 * (WordPress only falls back to generating and storing these in the options table
 * itself if those constants are missing from wp-config.php, which the installer
 * always populates on a normal setup). That makes this meaningfully different from
 * encrypting a secret with a key stored in the very same options table: a
 * database-only leak (a stray SQL dump, a misconfigured backup, a read-only DB
 * credential leak, an SQL injection that reads but can't reach the filesystem) no
 * longer hands over the API key in the clear. This is NOT protection against an
 * attacker who already has full server/filesystem access — at that point they can
 * read wp-config.php directly, or simply ask the running application to decrypt
 * the value for them. That is a fundamentally different, much harder threat model
 * that no software-only secret store solves without an external secrets manager
 * (Vault, a cloud KMS, etc.), which is out of scope for a WordPress plugin.
 *
 * Sodium availability: every function this class calls (sodium_crypto_secretbox(),
 * sodium_crypto_secretbox_open(), sodium_crypto_generichash(), random_bytes()) is
 * guaranteed present on any WordPress install this plugin supports. ext-sodium has
 * shipped built into PHP core since PHP 7.2 (this plugin requires 8.2+), and even
 * on the rare build without it, WordPress core itself has bundled the pure-PHP
 * `sodium_compat` polyfill since WP 5.2 (this plugin requires 6.9+) specifically
 * so `sodium_*` calls always work — see wp-settings.php's
 * `extension_loaded('sodium')` check, which loads the polyfill only when the
 * native extension is absent. The function_exists() guards below are defense in
 * depth, not an expected code path.
 */

if (!defined('ABSPATH')) {
	die;
}

class Chamilo_Crypto
{
	private const ENCRYPTED_PREFIX = 'sodium:v1:';
	private const PLAINTEXT_PREFIX = 'plain:v1:';
	private const KEY_CONTEXT = 'chamilo-wc:service-account-api-key:v1';

	/**
	 * Encrypts $plaintext for storage. Idempotent: if $plaintext already looks
	 * like this class's own output — i.e. a value that round-tripped unchanged
	 * through an HTML form field that echoes the stored value back as-is — it is
	 * returned unchanged instead of being encrypted a second time, which would
	 * otherwise corrupt it into something that can never decrypt back to the
	 * original secret.
	 */
	public static function encrypt(string $plaintext): string
	{
		if ('' === $plaintext || self::is_own_format($plaintext)) {
			return $plaintext;
		}

		if (!function_exists('sodium_crypto_secretbox') || !function_exists('random_bytes')) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Chamilo] Chamilo_Crypto::encrypt: sodium unavailable, storing unencrypted — unexpected on any supported WordPress install, see class docblock');
			}

			return self::PLAINTEXT_PREFIX.$plaintext;
		}

		$key = self::derive_key();
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
		sodium_memzero($key);

		return self::ENCRYPTED_PREFIX.base64_encode($nonce.$ciphertext);
	}

	/**
	 * Decrypts a value produced by encrypt(). Returns '' (and logs, under
	 * WP_DEBUG) for a tampered/undecryptable value rather than throwing — a
	 * corrupted or stale option should behave like "not configured", not fatal
	 * the settings page or a cron run.
	 */
	public static function decrypt(string $stored): string
	{
		if ('' === $stored) {
			return '';
		}

		if (str_starts_with($stored, self::PLAINTEXT_PREFIX)) {
			return substr($stored, \strlen(self::PLAINTEXT_PREFIX));
		}

		if (!str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
			// Not our format — a value saved before this feature existed, or by a
			// site where sodium was unavailable under a build with no prefix at
			// all. Treat as plaintext; the next save re-encrypts it.
			return $stored;
		}

		if (!function_exists('sodium_crypto_secretbox_open')) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Chamilo] Chamilo_Crypto::decrypt: sodium unavailable, cannot decrypt the stored key');
			}

			return '';
		}

		$raw = base64_decode(substr($stored, \strlen(self::ENCRYPTED_PREFIX)), true);
		$nonce_bytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if (false === $raw || \strlen($raw) <= $nonce_bytes) {
			return '';
		}

		$nonce = substr($raw, 0, $nonce_bytes);
		$ciphertext = substr($raw, $nonce_bytes);

		$key = self::derive_key();
		$plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
		sodium_memzero($key);

		if (false === $plaintext) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log("[Chamilo] Chamilo_Crypto::decrypt: authentication failed — stored value is corrupted, or the site's auth salts changed since it was saved");
			}

			return '';
		}

		return $plaintext;
	}

	private static function is_own_format(string $value): bool
	{
		return str_starts_with($value, self::ENCRYPTED_PREFIX) || str_starts_with($value, self::PLAINTEXT_PREFIX);
	}

	/**
	 * Derives a 32-byte secretbox key from wp_salt('auth') via a keyed BLAKE2b
	 * hash — never uses the salt's raw bytes directly as the key. The fixed
	 * context string domain-separates this from anything else that might derive
	 * key material from the same salt (including WordPress's own use of it for
	 * auth cookies).
	 *
	 * wp_salt('auth') is the arbitrary-length secret being hashed (the $message
	 * argument, which has no length constraint), and KEY_CONTEXT is the keyed
	 * hash's actual $key — sodium_crypto_generichash()'s $key argument, unlike
	 * $message, must be 16-64 bytes (SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN/MAX).
	 * A real wp_salt('auth') is `AUTH_KEY.AUTH_SALT`, each generated ~64 characters
	 * by WordPress's own secret-key API, so ~128 bytes combined — passing it as
	 * $key (as an earlier version of this method did) throws "SodiumException:
	 * unsupported key length" on any real WordPress install, not just a rare edge
	 * case; a short synthetic stub in this class's own test happened to fit the
	 * $key constraint by coincidence and didn't catch this before it shipped.
	 */
	private static function derive_key(): string
	{
		return sodium_crypto_generichash(wp_salt('auth'), self::KEY_CONTEXT, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
	}
}
