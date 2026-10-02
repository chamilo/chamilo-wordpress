# Chamilo Storefront for WooCommerce

Sells Chamilo courses and sessions through WooCommerce. Pulls a catalog from a
Chamilo v3 portal into WooCommerce products, and enrolls the buyer in Chamilo
automatically once their order completes.

See `CHANGELOG.md` for what changed between released versions.

## Requirements

- WordPress 6.9+
- WooCommerce 8.0+ (for HPOS support)
- PHP 8.2+
- A Chamilo v3.1+ portal (see Part A below for one-time setup on that side)

## What it does

- Syncs courses and sessions from Chamilo into WooCommerce as products
  (`chamilo_course` / `chamilo_session`), on a schedule or on demand.
- Enrolls the buyer in Chamilo when an order completes - creating their Chamilo
  account first if they don't already have one.
- Gives the buyer a "Continue to your course" link and a Chamilo-side notice
  either way: a new account gets Chamilo's own "set your password" email; an
  account that already existed gets a "you've been enrolled" message.
- Surfaces enrollment status (and a retry action) right on the WooCommerce order
  screen.

---

# Setup guide

**Audience:** a WordPress site administrator setting up the plugin, with help from
a Chamilo administrator for the one-time steps in Part A (this may be the same
person).

## Before you start

You'll need:

- A Chamilo v3 portal, with **admin access** to it.
- A WordPress site with **WooCommerce installed and active** (WordPress 6.9+,
  WooCommerce's current stable release, PHP 8.2+).
- **Admin access to WordPress.**
- If Chamilo and WordPress are managed by different people, budget a short
  coordination step - Part A below is done once, on the Chamilo side, before the
  plugin can connect.

## Part A - One-time setup on the Chamilo side

Do this once per Chamilo portal, before installing the plugin in WordPress.

### A.1 - Course catalog visibility settings (optional)

The plugin's service account always authenticates with its own API key, so
Chamilo's **"Course catalog published"** setting - which only gates anonymous,
unauthenticated visitors to Chamilo's own public catalog page - has no effect on
what the plugin can sync.

What the sync *does* respect, all under **Administration → Platform Settings →
Catalogue** (`/admin/settings/catalog`) if you want to use them - none are
required, all default off/permissive:

- **Only show selected courses** - if enabled, only courses explicitly flagged
  "Show in catalogue" are eligible; leave off if you'd rather every non-hidden
  course be a candidate.
- **Only show courses from selected category** - restricts the catalog to
  specific course categories.
- **Hide private courses** - excludes courses set to "Registered users only"
  visibility from the catalog.

A course's own **Visibility** setting still matters regardless of any of the
above: only the visibilities checked under **Course visibilities to sync** in the
plugin's settings (see Part C) are pulled, and Hidden courses are never synced.

### A.2 - Create the "price" field (optional)

A price is not required: courses and sessions sync with or without one. If you
want prices to come from Chamilo, create an Extra Field named **`price`**, on both
Course and Session - plain admin configuration, no Chamilo code change required.
Without it (or for an item with no value), the product syncs with no price and
you set it in WooCommerce.

1. Go to **Administration → Courses → Manage extra fields for courses**.
2. Add a new field: variable name `price`, type **Text** or **Float value**, visible to
   self (yes) + can change (yes).
3. Go to **Administration → Sessions → Manage session fields**.
4. Add the same field (`price`) for sessions.

Once this exists, setting a course or session's price is done from its own edit
page (see A.3) - not from this admin screen, which only defines the field itself.

### A.3 - Choose which courses/sessions to sell

Every course whose visibility is checked in the plugin's settings (Part C), and
every session that isn't Invisible, is synced. For **each** one you want to sell:

1. Open the course (**Course edit page**) or session (**Session edit page**) in
   Chamilo.
2. Set **Visibility** to one the plugin is set to sync (courses; by default
   *Open* and *Private*) or anything other than *Invisible* (sessions).
3. Optionally, fill in the **price** extra field (see A.2) with a positive number
   (in your shop's currency - the plugin doesn't convert currencies).

That's it. The course/session will sync into WooCommerce as a **draft** product
(see D.3) - review it and hit Publish when you're ready to actually sell it. Its
Chamilo visibility is shown on the product so you can judge whether it's the kind
of course that *should* be sellable (e.g. a "Registered users only" course syncing
in is worth a second look before publishing it to the public storefront).

A course with an unchecked visibility (or Hidden), or an Invisible session,
simply won't be pulled into WooCommerce - nothing breaks, it's just skipped. See the
troubleshooting checklist in D.4 if something you expected to see isn't showing
up.

### A.4 - Create a dedicated "service account" user

The plugin authenticates as one Chamilo user for all its background work (catalog
sync, enrollment). Don't reuse a real person's account.

1. **Administration → Users → Add a user.**
2. Give it a clear username (e.g. `wp-shop-sync`) and the **Admin** role. This is
   required, not a "use if unsure" default: reading the course and session
   catalogs and the `price` values (A.2) goes through endpoints restricted to
   Admin, so a lesser role (Teacher/session-manager) can't run the sync even though
   it might otherwise be enough for enrollment.
3. Save.

### A.5 - Generate that account's External API key

There's no self-service screen for this yet (unlike the personal MCP API key page
at **My profile → MCP API key**) - an admin or developer with **console/SSH
access** to the Chamilo server runs one command instead:

1. Turn the feature on: **Administration → Platform Settings → Security → Enable
   external API keys** → **Yes**. (Off by default - a leaked key can't be used at
   all until this is enabled, independent of revoking the key itself.)
2. From the Chamilo server, run:
   ```
   php bin/console chamilo:security:generate-external-api-key wp-shop-sync
   ```
   (replace `wp-shop-sync` with whatever username you gave the service account in
   A.4). On a multi-portal install, add `--access-url-id=<id>` to scope the key to
   the right portal - it defaults to `1`.
3. The command prints the key **once** - copy it immediately, there's no way to
   retrieve it again. Running the command again for the same user replaces the
   key (the old one stops working); add `--revoke` to just revoke without
   generating a new one.

If your hosting doesn't give you console access, ask whoever manages the server to
run this one command for you - it takes a few seconds and doesn't require any
Chamilo-specific knowledge beyond the username to use.

### A.6 - Note your connection details

Before moving to WordPress, write down:

- **Chamilo base URL** (e.g. `https://campus.example.com`)
- **The External API key** from A.5
- **Access URL / portal ID**, if your Chamilo install runs multiple portals
  (Administration → Multiple URLs) - the plugin needs to know which one it's
  talking to. If you only have one portal, this is just the default (`1`).

---

## Part B - Install the plugin in WordPress

1. **Plugins → Add New → Upload Plugin**, select the plugin's `.zip` file, click
   **Install Now**, then **Activate**. (Or install via a Git checkout / Composer,
   if your team manages plugins that way - copy this directory to
   `wp-content/plugins/chamilo/`.)
2. WordPress will show an admin notice if WooCommerce isn't active - install/
   activate WooCommerce first if you haven't.

## Part C - Connect WordPress to Chamilo

1. Go to **WooCommerce → Settings → Chamilo** (a new tab the plugin adds).
2. Fill in the three values you noted in A.6, and optionally the visibility filter:
   - **Chamilo base URL**
   - **External API key**
   - **Access URL / portal ID**
   - **Course visibilities to sync** (optional) - which Chamilo course
     visibilities to pull: Public, Open, Private, Closed. *Open* and *Private* are
     checked by default; Hidden courses are never synced. Applies to courses only.
3. Click **Test connection**. This confirms the key is valid and Chamilo is
   reachable before you save - if it fails, double-check the URL (must be
   reachable from your WordPress server, not just your browser) and that the key
   wasn't mistyped or already revoked.
4. Click **Save changes**.

At this point WordPress can talk to Chamilo, but nothing has been synced yet.

## Part D - Synchronize courses and sessions for sale

### D.1 - Run the first sync

1. Still under **WooCommerce → Settings → Chamilo**, click **Sync now**.
2. The plugin pulls every course with a selected visibility and every non-Invisible session (A.3),
   and creates one WooCommerce product per item (product type `chamilo_course` or
   `chamilo_session`), as a **draft** - not published — so you can review each one
   before it's actually purchasable. This casts a wide net deliberately: the
   service account has admin-equivalent access, so it pulls in everything
   sellable regardless of Chamilo's own catalogue-visibility settings, and expects
   you to make the "should this be sold" call in WordPress instead. Check each
   product's Chamilo visibility (shown in the "Chamilo details" box on the product
   edit screen) before publishing it.
3. Course/session categories are mapped to WooCommerce product categories
   automatically; anything without a clean match falls back to the **Default
   product category** you can set on this same settings screen.
4. Every product is also tagged with two global attributes - **"Package type"**
   (`Course` or `Session`) and **"Course visibility"** - and marked **Virtual**.
   Categories stay reserved for actual Chamilo course/session categories; use
   these attributes (via WooCommerce's normal attribute filter widget/block, or
   the product page's "Additional information" tab) whenever your theme needs to
   tell courses and sessions apart, or surface visibility, on the storefront. A
   course product also carries its Chamilo **course code** and, for sessions, the
   session's **access start/end dates and duration** as product attributes.

### D.2 - Set the sync schedule

**This is the normal way to keep the catalog refreshed - "Sync now" is a manual,
immediate override, not something you're expected to click routinely.** On the
same screen, choose how often the plugin should re-sync automatically (Hourly /
Twice daily / Daily). Saving this screen reschedules the recurring sync to match
immediately.

This runs on WordPress's own cron, which only actually fires on a site visit, not
as a true background daemon - on a low-traffic site (a staging/test site
especially), "hourly" can mean "whenever someone next happens to load a page after
that hour is up," not on the clock. Set up a real server cron hitting
`wp-cron.php` at a steady interval if sync needs to be reliably timely regardless
of traffic.

### D.3 - What each sync does, and doesn't, touch

- **Title, category** - refreshed from Chamilo every sync.
- **Description** - refreshed from Chamilo *unless* you've edited it directly on
  the product (no checkbox needed for this one): if what's currently there
  doesn't match what the last sync itself wrote, it's treated as a manual edit and
  left alone. To hand it back to auto-sync, just **clear the description field
  entirely** and save - an empty description is treated as "please recover this
  from Chamilo," and the next sync fills it back in. For courses, this pulls from
  Chamilo's Course Description tool (every section it has, concatenated), which
  reflects what teachers actually write for the course - not the course's plain
  "Description" admin field, which Chamilo only ever auto-fills with a generic
  placeholder.
- **Price** - refreshed from Chamilo *unless* you've checked **"Price locked from
  Chamilo sync"** on the product edit screen (General tab), in which case the
  plugin leaves your manually-edited price alone from then on. Uncheck it to hand
  price back to auto-sync.
- **Image** - same behavior as price, via its own **"Image locked from Chamilo
  sync"** checkbox right next to it: refreshed from Chamilo every sync unless
  locked. Unlocked, an unchanged image is not re-downloaded on every run - only a
  genuine change on the Chamilo side triggers a re-import.
- **Attributes (Package type, Course visibility, course code, session dates) and
  the Virtual flag** - always set/refreshed on every sync, not lockable (there's
  no legitimate reason a Chamilo course/session product would need to stop being
  virtual, or change what kind it is).
- **Published/draft status** - set once, at creation, and never touched again by
  later syncs. If you publish a product, re-syncing won't quietly draft it again;
  if you leave it a draft, re-syncing won't publish it for you either.
- **Stock / sold-out status** (sessions only) - Chamilo has no seat-limit concept
  today, so sessions currently always show in stock.
- **Enrollment, orders, customers** - never touched by sync; that only happens at
  purchase time (Part E).

### D.4 - Troubleshooting: "my course isn't showing up in WooCommerce"

Check, in order:

| Check | Where |
|---|---|
| Is the course's visibility checked under **Course visibilities to sync** (and not Hidden), or the session not Invisible? | WordPress: settings tab; Chamilo: course/session edit page |
| Did a sync actually run since you made the change? | WordPress: WooCommerce → Settings → Chamilo → last sync time; click **Sync now** |
| Is it there as a **draft**, just not published? | WordPress: Products list - filter by "Draft"; this is the most common "missing" course — it synced, it's just not live yet (see D.1) |
| Does the service account still have a valid, non-revoked API key? | WordPress: **Test connection** button |

**"My course/session product has no image."** Confirm the course/session actually
has an illustration set in Chamilo, check `debug.log` for `[Chamilo] image import
failed for ...` or `[Chamilo] media_handle_sideload failed for ...` lines (requires
`WP_DEBUG` on), and confirm the **"Image locked from Chamilo sync"** checkbox
isn't checked on the product (a locked image is never touched by sync, by design).

**A product's Chamilo fields (the "Chamilo details" box, the price/image lock
checkboxes) are missing from its edit screen.** WooCommerce's product-type
selector only knows its own types, so saving a synced product from the edit
screen posts "simple" and resets the product's type. The plugin restores it
automatically (after that save, when the edit screen is opened, on every order
and at the end of every sync) and shows a notice when it does; reload the screen
if the fields are still missing. Enrollment never depended on this: an order item
is recognized as a Chamilo course/session from the product's own Chamilo meta, not
from its WooCommerce type. `debug.log` (with `WP_DEBUG` on) shows
`chamilo target=course #N` for each order item and a "stale ... repaired" line
when a repair happened.

**"Sync now" says "Request failed" / a fatal error mentions "Invalid or duplicated
SKU."** WooCommerce requires every product's SKU to be unique store-wide. The
plugin sets each course/session's SKU from its Chamilo course/session ID
(prefixed `c`/`s` so a course and a session sharing the same numeric ID never
collide) - if a *different* WooCommerce product (often a leftover/duplicate from
earlier testing) already uses that same SKU, WooCommerce refuses the save. The
plugin detects this ahead of time and skips only that one product's SKU (logging
`[Chamilo] skipped SKU "..." for course #... - already used by a different
WooCommerce product` in `debug.log`) instead of failing the whole sync run - check
Products for a duplicate entry with that SKU and delete or update it, then re-sync
to have the SKU applied.

## Part E - Verify the purchase → enrollment flow works

Do this once after initial setup, with a test course priced low (or a 100%-off
test coupon) and a throwaway email address:

1. Buy the test course/session through the normal WooCommerce checkout.
2. Once the order is marked **Completed** (or **Processing**, depending on your
   payment gateway), check the order in **WooCommerce → Orders** - it should show
   a Chamilo enrollment status of **Enrolled**, with a linked Chamilo user ID.
3. If no Chamilo account existed for that email yet, check that a
   **password-reset email** arrived - a genuine set-your-password link (the same
   mechanism as Chamilo's own "Forgot your password?"), never a plaintext
   password. If you ever see a plaintext password in this email, something's
   misconfigured; stop and check the plugin's connection settings.
4. If a Chamilo account **already existed** for that email (test this with an
   account you've used before), there is no password-reset email - instead check
   that a **"you've been enrolled"** message and email arrived on that account,
   naming the course/session and reminding the buyer of their Chamilo username.
   This is the one Chamilo-side signal a pre-existing account gets that anything
   happened at all.
5. Click the **"Continue to your course"** link from the order confirmation page
   or account order history. For a course purchase it should land on Chamilo's
   login page pre-filled to redirect straight to the purchased course once you log
   in; for a session purchase it redirects to the general session list instead
   (deliberately not a deep link to the specific session - a buyer isn't usually
   enrolled in enough concurrent sessions for that to matter). *(This is a deep
   link to Chamilo's own login, not single sign-on - the buyer still enters their
   Chamilo password once.)*
6. Log in as that test account and confirm the course/session is actually
   accessible.

If any step fails, the order screen's Chamilo enrollment status will show
**Failed** with an error message - use that message plus the checklist in D.4 to
diagnose before retrying (there's a **Retry enrollment** action on the order
screen for exactly this).

## Disconnecting / uninstalling

- To pause selling without uninstalling: turning off the sync schedule (D.2) stops
  new products from being created or refreshed; existing products stay as they
  are, so also manually set them to "Out of stock" or unpublish them if you want
  to stop selling right away.
- To fully disconnect: clear the connection fields in Part C, then in Chamilo,
  revoke the service account's External API key (same screen it was generated
  from) so the old key can't be reused if it ever leaks.
- Uninstalling the plugin does **not** delete WooCommerce products it created, and
  does **not** un-enroll anyone from Chamilo - those are independent systems by
  design.

---

# For developers

## What's implemented

- `chamilo.php` - bootstrap, HPOS compatibility declaration, cron scheduling.
- `includes/class-chamilo-api-client.php` - Bearer-authenticated HTTP client for
  Chamilo's API Platform endpoints, with Hydra pagination.
- `includes/class-chamilo-settings.php` - WooCommerce → Settings → Chamilo tab
  (connection fields, Test connection, Sync now, sync interval, default product
  category).
- `includes/class-chamilo-crypto.php` - encrypts the service account API key at
  rest (libsodium secretbox, keyed from WordPress's own `wp_salt('auth')`) before
  it reaches the options table; `class-chamilo-settings.php` wires this in via the
  field's `sanitize_callback`, so the plaintext key is never written to the
  database, not even transiently.
- `includes/product-types/` - `chamilo_course` / `chamilo_session` custom
  WooCommerce product types (extend `WC_Product_Simple`, so cart/checkout/tax work
  for free).
- `includes/class-chamilo-product-types.php` - registers the two product classes;
  the "Price/Image locked from Chamilo sync" checkboxes on the product edit
  screen; and the read-only "Chamilo details" sidebar box (course code, Chamilo
  ID, teacher(s), visibility, seats/capacity, last synced).
- `includes/class-chamilo-product-attributes.php` - the global "Package type" and
  "Course visibility" WooCommerce attributes every synced product is tagged with,
  plus a helper for local (non-taxonomy) per-product attributes (course code,
  session access dates/duration).
- `includes/class-chamilo-catalog-sync.php` - the sync engine: courses and
  sessions both read from their plain authenticated collection (`/api/courses`,
  `/api/sessions`), gated only by `visibility` (courses: the admin-selected set, never Hidden; sessions: not Invisible); the
  optional `price` extra field is read via the `ExtraFieldValues` bulk-read
  pattern. New products
  sync in as **drafts**. Course descriptions come from Chamilo's Course
  Description tool (concatenated sections); price/description/image each sync
  unless locked or manually edited (see D.3). Product image is imported with the
  service account's own Bearer token so it works regardless of course visibility.
  All products are Virtual, never Downloadable. SKUs are the Chamilo course/
  session ID, prefixed `c`/`s` to keep the two id spaces distinct.
- `includes/class-chamilo-enrollment.php` - order-complete hook: resolve-or-create
  the buyer in Chamilo, enroll them, notify a pre-existing account of the
  enrollment (`POST /api/enrollment-notifications`), and build the "Continue to
  your course" deep link.
- `includes/class-chamilo-order-meta.php` - HPOS-safe enrollment-ledger meta
  helpers.
- `includes/class-chamilo-admin.php` - order-screen metabox showing enrollment
  status per line item, with a retry action.
- `uninstall.php` - removes only this plugin's own settings; never touches synced
  products or Chamilo enrollments.

## Known limitations / not in this release

- **The API key is encrypted at rest but not against full server compromise.**
  Encryption is keyed from `wp_salt('auth')`, which on a normal install lives in
  `wp-config.php`, not the database - this protects against a database-only leak
  (a stray SQL dump, a misconfigured backup, an SQL injection that can read but not
  reach the filesystem), not against an attacker who already has filesystem access
  (they can read wp-config.php directly, or just ask the running site to decrypt
  it). That stronger guarantee needs an external secrets manager, out of scope
  here.
- **Session category sync is not implemented** - sessions fall back to the
  plugin's configured default product category.
- **No self-service Chamilo product-data admin UI** - these two product types are
  sync-managed only; nobody creates one by hand via "Add new product", so there's
  no full custom "Product data" panel tab. Editing price or image directly on the
  product screen still works (inherited from `WC_Product_Simple`) and is respected
  on future syncs via the "locked from Chamilo sync" checkboxes.
- **No `[chamilo_my_courses]` shortcode / account dashboard** - progress/course
  listing inside WooCommerce's My Account is a future addition, not in this
  release.
- **No true single sign-on** - "Continue to your course" is a plain deep link to
  Chamilo's own login page; the buyer still enters their Chamilo password once.
- **Extra-field ID resolution happens on every sync run** (a couple of small
  extra API calls) rather than being cached - fine at hourly-sync scale, worth
  caching (transient) if sync frequency ever increases materially.
- **No refund → auto-unenroll hook, subscriptions, waitlists, or multi-currency**
  - WooCommerce's own currency/tax handling covers the common case today.

