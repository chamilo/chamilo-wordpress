=== Chamilo Storefront for WooCommerce ===
Contributors: chamilo
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.2
WC requires at least: 8.0
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Sells Chamilo courses and sessions through WooCommerce.

== Description ==

Pulls a catalog of courses and sessions from a Chamilo v3 portal into WooCommerce as
products, and enrolls the buyer in Chamilo automatically once their order completes —
creating their Chamilo account first if they don't already have one.

See README.md in this plugin's own repository for the full setup guide and the list
of what's implemented.

== Installation ==

1. Upload to `wp-content/plugins/chamilo/` (or install as a zip via Plugins → Add New).
2. Activate. Requires WooCommerce to already be active.
3. Go to WooCommerce → Settings → Chamilo and fill in your Chamilo portal's base URL
   and service-account API key.
4. Click "Test connection", then "Sync now".

== Changelog ==

= 1.0.2 =
* New: "Course visibilities to sync" setting (Public, Open, Private, Closed; Open and Private by default).
* Change: a `price` extra field is no longer required for a course or session to sync.
* Fix: orders for a synced product were skipped when WooCommerce had reset its product type to
  "simple"; products are now recognized from their Chamilo meta and the type is restored automatically.
  See CHANGELOG.md for detail.

= 1.0.1 =
* Fix: saving the settings screen fataled with "SodiumException: unsupported key length" — the
  service-account API key encryption key derivation passed the (too-long) WordPress auth salt into
  a length-constrained argument of the hashing call. See CHANGELOG.md for detail.

= 1.0.0 =
* Initial release.
