# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Course visibilities to sync** setting (WooCommerce → Settings → Chamilo): checkboxes for
  Public, Open, Private and Closed, applied to the sync. Open and Private are checked by
  default; Hidden courses are never synced. It does not apply to sessions.

### Fixed

- Orders for a synced product were skipped (no enrollment) whenever WooCommerce had reset the
  product's type to "simple", which happens when a synced product is saved from the product
  edit screen. Order items are now recognized as Chamilo items from the product's
  `_chamilo_course_id` / `_chamilo_session_id` meta instead of its WooCommerce type, and
  enrollment and the "Continue to your course" link use that meta too.
- The product type is now restored automatically after a product save, when a product's edit
  screen is opened (with an admin notice), when an order is processed, and for every product
  carrying Chamilo meta at the end of each sync.

### Changed

- A `price` extra field is no longer required for a course or session to sync. Every course
  and session that isn't Closed/Hidden (courses) or Invisible (sessions) is now synced; the
  `price` extra field is optional, and an item without one (or on a portal where the field
  isn't defined) syncs with no price instead of being skipped. Previously such a portal
  reported "Synced 0 course(s), 0 session(s)" with no error.

## [1.0.1] - 2026-09-29

### Fixed

- Saving **WooCommerce → Settings → Chamilo** fataled with `SodiumException: unsupported key
  length` on every attempt. `Chamilo_Crypto::derive_key()` passed `wp_salt('auth')` - a real
  install's `AUTH_KEY.AUTH_SALT` pair, ~128 bytes - into `sodium_crypto_generichash()`'s `$key`
  argument, which is constrained to 16-64 bytes (`$message`, unlike `$key`, has no such limit).
  Fixed by swapping the two arguments: the arbitrary-length salt is now the `$message`, and the
  short fixed context string is now the `$key`.

## [1.0.0] - 2026-09-29

### Added

- Scheduled and on-demand catalog sync: courses and sessions from a Chamilo v3 portal become
  `chamilo_course` / `chamilo_session` WooCommerce products, gated on a `price` extra field and
  non-hidden visibility, created as drafts for admin review.
- Sync keeps title, category, description (unless manually edited), price and image (each
  lockable per product), and course code / session dates / "Package type" / "Course visibility"
  attributes up to date on every run.
- Order-complete hook: resolves or creates the buyer's Chamilo account, enrolls them in the
  purchased course/session, and links their WordPress account to it.
- New-account buyers get Chamilo's native "set your password" email; pre-existing accounts get an
  explicit "you've been enrolled" notification (`POST /api/enrollment-notifications`, a new
  Chamilo-core endpoint built alongside this plugin) naming the course/session and their username.
- "Continue to your course" deep link on the order confirmation/history and the order screen's
  enrollment status metabox, with a manual retry action for failed enrollments.
- WooCommerce → Settings → Chamilo screen: connection fields, Test connection, Sync now, sync
  interval, default product category.
- Service-account API key encrypted at rest (libsodium secretbox, keyed from `wp_salt('auth')`).
- Read-only "Chamilo details" sidebar box on the product edit screen (course code, Chamilo ID,
  teacher(s), visibility, seats/capacity, last synced).
- HPOS-compatible throughout.
